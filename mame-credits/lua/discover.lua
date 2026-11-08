-- Discovery for one set, run by MAME through -autoboot_script.
-- Writes key=value lines to $MAMECREDITS_OUT and exits MAME.

-- MAME runs the autoboot script again on every machine reset; the first run owns the search.
if MAMECREDITS_STARTED then
	return
end
MAMECREDITS_STARTED = true

local here     = debug.getinfo(1, "S").source:match("^@(.-)[^/]*$")
local Search   = dofile(here .. "search.lua")
local patterns = dofile(here .. "patterns.lua")

-- Per-set settings from config/tuning.txt, "name=value,...".
local tuning = {}
for name, value in (os.getenv("MAMECREDITS_TUNING") or ""):gmatch("(%w+)=(%d+)") do
	tuning[name] = tonumber(value)
end

-- Emulated seconds.
local IDLE         = 1.0  -- quiet window that exposes self changing bytes
local HOLD         = 0.1  -- coin switch closed
local SETTLE       = 1.0  -- after the coin, before reading the result
local START_HOLD   = 0.5  -- start button pressed; shorter presses are missed (tmnt)
local START_SETTLE = 3.0  -- after the start button, before reading the result
local BOOT_MAX     = tuning.boot or 90 -- coins inserted until a counter is confirmed, also after a reset
-- Boot without coins on a power cycled run, or on every run when tuned: games counting coins during boot
-- may sit at their credit limit when it ends and take no more, even after a soft reset (altbeast).
local QUIET_BOOT = tuning.quiet_boot or 30
-- Longer than the time Search's MAX_SPACING coins take: slow timers that look like
-- "+1 every few coins" change in it.
local LONG_IDLE = 10

local CONFIRM_CHANGES = 3  -- credit steps a candidate needs to count as found
local ENCODE_CREDITS  = 10 -- credit count that separates BCD (0x10) from binary (0x0a)
local MAX_BUTTONS     = 8  -- player buttons tried as start when there is no start input
local START2_CREDITS  = 4  -- credits needed before the second player start
-- Rounds through the start inputs: a press may only leave an info screen (toki) or land where the
-- game ignores it (psychic5).
local START_ROUNDS = 3
local START_PAUSE  = 5 -- emulated seconds between rounds

local machine = manager.machine
local ioport  = machine.ioport

-- Frame subscription (MAME 0.254+); report() referencing it keeps it from being collected, which unsubscribes.
local subscription

local function report(values)
	local file = assert(io.open(os.getenv("MAMECREDITS_OUT"), "w"))
	for name, value in pairs(values) do
		file:write(name, "=", tostring(value), "\n")
	end
	file:close()
	if subscription then
		subscription:unsubscribe()
	end
	machine:exit()
end

-- Token with the player: without it every player's button 1 is "P1_BUTTON1".
local function token(input)
	return ioport:input_type_to_token(input.type, input.player)
end

local function field(name)
	for _, port in pairs(ioport.ports) do
		for _, candidate in pairs(port.fields) do
			if token(candidate) == name then
				return candidate
			end
		end
	end
end

local function matches(text, list)
	text = text:lower()
	for _, pattern in ipairs(list) do
		if text:find(pattern) then
			return true
		end
	end
	return false
end

local function isFreePlay(text)
	return matches(text, patterns.freeplay.include) and not matches(text, patterns.freeplay.exclude)
end

-- Free play DIP switches: a dedicated switch ("dip") or a coinage setting ("dip_coinage").
-- Each entry carries the setting that turns free play off.
local function freePlaySwitches()
	local switches = {}
	for _, port in pairs(ioport.ports) do
		for name, switch in pairs(port.fields) do
			if switch.type_class == "dipswitch" then
				local off, free
				for value, setting in pairs(switch.settings) do
					if matches(setting, patterns.off) then
						off = off or value
					end
					if isFreePlay(setting) then
						free = value
					end
				end
				if isFreePlay(name) and off then
					switches[#switches + 1] = {kind = "dip", switch = switch, off = off, on = switch.defvalue ~= off}
				elseif free then
					local paid
					for value, setting in pairs(switch.settings) do
						if matches(setting, patterns.coin1) or (not paid and not isFreePlay(setting)) then
							paid = value
						end
					end
					switches[#switches + 1] = {kind = "dip_coinage", switch = switch, off = paid, on = switch.defvalue == free}
				end
			end
		end
	end
	return switches
end

-- DIP switches LEDSpicer reports at launch, as sorted "kind=port/mask" references; the plugin reads
-- their live settings by name. A dedicated free play switch is one of them.
local function dipReferences(freePlay)
	local function reference(kind, switch)
		return string.format("%s=%s/%x", kind, switch.port.tag, switch.mask)
	end
	local list = {}
	for _, entry in ipairs(freePlay) do
		if entry.kind == "dip" then
			list[#list + 1] = reference("freeplay", entry.switch)
		end
	end
	for _, port in pairs(ioport.ports) do
		for name, switch in pairs(port.fields) do
			if switch.type_class == "dipswitch" then
				for _, group in ipairs(patterns.dips) do
					for _, pattern in ipairs(group) do
						local slot = name:lower():match(pattern)
						if slot then
							list[#list + 1] = reference((group.kind:gsub("%%1", slot)), switch)
						end
					end
				end
			end
		end
	end
	table.sort(list)
	return table.concat(list, ",")
end

-- Inputs that may start a game for a player: its start input, then its buttons in order.
local function startInputs(player)
	local inputs = {field("START" .. player)}
	for number = 1, MAX_BUTTONS do
		inputs[#inputs + 1] = field("P" .. player .. "_BUTTON" .. number)
	end
	return inputs
end

-- Whether a bank has memory selected: reading one without crashes MAME (bfcobra, multigam), and
-- selecting its own entry again fails only then.
local function isSelected(bank)
	local current = bank.entry
	return pcall(function() bank.entry = current end)
end

-- Whether a map entry reads RAM: plain RAM, or a selected bank the CPU can write (phoenix maps its RAM as
-- a bank; ROM banks take no writes). Banks are keyed by their last tag component.
local function isRam(entry, banks)
	local write = entry.write.handlertype
	return entry.read.handlertype == "ram"
		or (entry.read.handlertype == "bank" and write ~= "none" and write ~= "nop" and write ~= "unmap"
			and isSelected(banks[entry.read.tag:match("[^:]+$")]))
end

-- Bytes first..last (0 based) of a share, as read_range gives a space's.
local function shareBytes(share, first, last)
	local parts = {}
	for from = first, last, 4096 do
		local bytes = {}
		for offset = from, math.min(from + 4095, last) do
			bytes[#bytes + 1] = share:read_u8(offset)
		end
		parts[#parts + 1] = string.char(table.unpack(bytes))
	end
	return table.concat(parts)
end

-- Bytes compared to tell whether a memory mapper moved a share away from its mapped address.
local SAMPLE = 64

-- Largest share read from Lua, byte by byte: bigger ones (I/O board buffers, flash, graphics; ksys573 has a
-- 32 MB one) would stall every snapshot and hold no credit counter. Mapped ones are still read through the space.
local SHARE_MAX = 0x100000

-- Largest RAM range scanned: every snapshot copies it, several are held at once, and one run on a board
-- with hundreds of megabytes reached 9 GB. Counters live in small work RAM, not in PC-class main memory.
local REGION_MAX = 0x4000000

-- Readable RAM of every address space, each share once; shares no space maps are read directly.
-- A map names shares relative to the device owning the map, so shares match by their last tag part.
-- A memory mapper (System 16B/18: Sega 315-5195) moves shares at runtime: a mapped share is read through
-- the space while a sample of it matches the share, through the share once it does not.
local function regions()
	local shares, banks, used, list = {}, {}, {}, {}
	for tag, share in pairs(machine.memory.shares) do
		shares[tag:match("[^:]+$")] = {tag = tag, share = share}
	end
	for tag, bank in pairs(machine.memory.banks) do
		banks[tag:match("[^:]+$")] = bank
	end
	for tag, device in pairs(machine.devices) do
		for name, space in pairs(device.spaces) do
			for _, entry in ipairs(space.map.entries) do
				local held = entry.share and shares[entry.share:match("[^:]+$")]
				if isRam(entry, banks) and not (held and used[held.tag]) and entry.address_end - entry.address_start < REGION_MAX then
					-- Shares are counted in bytes: only a byte addressed space (shift 0) lines up with them
					-- (tickee's TMS34010 addresses bits).
					if held and (held.share.size > SHARE_MAX or space.shift ~= 0) then
						used[held.tag], held = true, nil
					end
					local first, last = entry.address_start, entry.address_end
					local region = {
						cpu = tag, space = name, first = first, last = last,
						rank = (tag == ":maincpu" and 0 or 2) + (entry.write.handlertype == "ram" and 0 or 1),
						read = function() return space:read_range(first, last, 8) end,
						peek = function(offset) return space:read_u8(first + offset - 1) end,
					}
					if held then
						used[held.tag] = true
						local share, size = held.share, math.min(held.share.size, last - first + 1)
						region.shareTag = held.tag
						-- Compares the bytes around offset (0 based); a ROM bank over part of the range (williams)
						-- differs only there.
						region.isMoved  = function(offset)
							local from = math.max(0, offset - SAMPLE // 2)
							local to   = math.min(size, from + SAMPLE) - 1
							return space:read_range(first + from, first + to, 8) ~= shareBytes(share, from, to)
						end
						region.read = function()
							return region.isMoved(0) and shareBytes(share, 0, size - 1) or space:read_range(first, first + size - 1, 8)
						end
						region.peek = function(offset) return share:read_u8(offset - 1) end
					end
					list[#list + 1] = region
				end
			end
		end
	end
	-- RAM devices: memory a CPU installs at startup instead of mapping it (main RAM of the PlayStation based
	-- boards: ZN, Namco System 11/12, System 573), read through their save state items like cheatfind does.
	-- A RAM device is known by its items: other devices may also be called "ram" (bfcobra, multigam crashed).
	for tag, device in pairs(machine.devices) do
		if device.items["0/m_pointer"] and device.items["0/m_size"] then
			local size  = emu.item(device.items["0/m_size"]):read(0)
			local block = emu.item(device.items["0/m_pointer"])
			if size <= REGION_MAX then
				list[#list + 1] = {
					cpu = tag, space = "ram", first = 0, last = size - 1, rank = tag:find("^:maincpu:") and 0 or 2,
					read = function() return block:read_block(0, size) end,
					peek = function(offset) return block:read(offset - 1) end,
				}
			end
		end
	end
	for tag, share in pairs(machine.memory.shares) do
		if not used[tag] and share.size <= SHARE_MAX then
			list[#list + 1] = {
				cpu = tag, space = "share", first = 0, last = share.size - 1, rank = 4, shareTag = tag,
				read = function() return shareBytes(share, 0, share.size - 1) end,
				peek = function(offset) return share:read_u8(offset - 1) end,
			}
		end
	end
	return list
end

-- Where a counter is reported: its CPU address, or its share and offset when a mapper moved the share.
local function location(found)
	local region = found.region
	if region.isMoved and region.isMoved(found.address - region.first) then
		return region.shareTag, "share", found.address - region.first
	end
	return region.cpu, region.space, found.address
end

-- Collects first: snapshots of large RAM are tens of megabytes each, and the incremental collector lets
-- old ones pile up to gigabytes over parallel runs.
local function snapshot(list)
	collectgarbage()
	local snap = {}
	for index, region in ipairs(list) do
		snap[index] = region.read()
	end
	return snap
end

local function wait(seconds)
	local target = emu.time() + seconds
	while emu.time() < target do
		coroutine.yield()
	end
end

local function press(switch, seconds)
	switch:set_value(1)
	wait(seconds)
	switch:set_value(0)
end

local function softReset()
	machine:soft_reset()
	wait(SETTLE)
end

local function isDigit(value)
	return value >= 1 and value <= 9
end

-- A found counter: where it is and how it counts, nil for a digit without tens (a display digit
-- cycling 0-9). A "digits" counter has wrapped past 9, so its tens digit is the neighbour holding 1-9:
-- before it (address = tens, "digits") or after it ("digits_le").
local function counter(list, candidate, state)
	local index, offset = Search.split(candidate)
	local region   = list[index]
	local found    = {
		candidate = candidate, state = state, region = region, offset = offset, address = region.first + offset - 1, size = 1,
		encoding = Search.encodingName(state.encoding),
		credits = state.encoding.bcd or state.encoding.bin or state.encoding.digit, spacing = state.spacing,
		autoStart = state.autoStart, limit = state.limit,
	}
	if found.encoding == "digits" then
		found.tens = isDigit(region.peek(offset - 1)) and offset - 1 or isDigit(region.peek(offset + 1)) and offset + 1
		if not found.tens then
			return nil
		end
		found.size = 2
		if found.tens < offset then
			found.address = found.address - 1
		else
			found.encoding = "digits_le"
		end
	end
	return found
end

local function value(found)
	local ones = Search.decode(found.region.peek(found.offset), found.encoding)
	return found.tens and found.region.peek(found.tens) * 10 + ones or ones
end

-- Most plausible first: fewest credits per step (coinage defaults to 1 coin, 1 credit),
-- then the region rank, then the lowest address.
local function sort(counters)
	table.sort(counters, function(a, b)
		if a.credits ~= b.credits then
			return a.credits < b.credits
		end
		if a.region.rank ~= b.region.rank then
			return a.region.rank < b.region.rank
		end
		return a.address < b.address
	end)
	return counters
end

-- Coins until bytes step like a credit counter; returns them sorted, whether the credit limit was hit
-- and the seconds the game took to count credits (its boot time, at most).
local function findCounters(list, coin)
	local search   = Search.new()
	local before   = snapshot(list)
	local began    = emu.time()
	local deadline = began + BOOT_MAX

	local function idle(seconds)
		wait(seconds)
		local quiet = snapshot(list)
		search:idle(before, quiet, CONFIRM_CHANGES)
		before = quiet
	end

	-- One cycle: idle window, then a coin.
	local function cycle()
		idle(IDLE)
		press(coin, HOLD)
		wait(SETTLE)
		local after = snapshot(list)
		search:coin(before, after)
		before = after
	end

	local function found()
		local counters = {}
		for _, entry in ipairs(search:confirmed(CONFIRM_CHANGES)) do
			counters[#counters + 1] = counter(list, entry.candidate, entry.state)
		end
		return sort(counters)
	end

	repeat
		cycle()
		if #found() > 0 then
			idle(LONG_IDLE)
		end
	until #found() > 0 or emu.time() > deadline
	if #found() == 0 then
		return {}, false
	end
	local boot = emu.time() - began

	-- More credits until BCD and binary differ, or the game stops accepting them.
	local saturated, limit = false, emu.time() + BOOT_MAX
	while not saturated do
		local leader = found()[1]
		if not leader or value(leader) >= ENCODE_CREDITS or emu.time() > limit then
			break
		end
		local saved = search:save()
		for _ = 1, leader.spacing do
			cycle()
		end
		-- A confirmed candidate that stopped while coins go in hit its credit limit (myhero stops at 9 while
		-- its coin total goes on): it stays, at its limit; one that changed any other way is gone.
		for candidate, state in pairs(saved.candidates) do
			local index, offset = Search.split(candidate)
			if state.changes >= CONFIRM_CHANGES and search.candidates[candidate] == nil
				and list[index].peek(offset) == state.value then
				state.limit, search.candidates[candidate] = state.value, state
			end
		end
		-- The leader stopping is the credit limit for the whole search.
		-- A dropped leader may be seeded again by the same coin: compare the state, not the key.
		if search.candidates[leader.candidate] ~= leader.state then
			saved.candidates[leader.candidate].limit = leader.state.value
			search:restore(saved)
			saturated = true
		end
	end

	-- Candidates confirmed while counting up to ENCODE_CREDITS have not seen a long idle yet.
	idle(LONG_IDLE)
	return found(), saturated, boot
end

-- Known locations to verify before searching, best first: "method,cpu,space,address,encoding;...".
local function hints()
	local list = {}
	for entry in (os.getenv("MAMECREDITS_HINTS") or ""):gmatch("[^;]+") do
		local method, cpu, space, address, encoding = entry:match("^([^,]+),([^,]+),([^,]+),(%x+),([^,]+)$")
		list[#list + 1] = {method = method, cpu = cpu, space = space, address = tonumber(address, 16), encoding = encoding}
	end
	return list
end

-- The counter a hint describes, or nil when no scanned region holds its address.
local function located(list, hint)
	for _, region in ipairs(list) do
		-- A share location (space "share") names the share and an offset in it, see location().
		local base = hint.space == "share" and region.shareTag == hint.cpu and region.first
			or region.cpu == hint.cpu and region.space == hint.space and 0
		local address = base and base + hint.address
		if address and address >= region.first and address <= region.last then
			local offset = address - region.first + 1
			local found  = {region = region, offset = offset, address = address, size = 1, encoding = hint.encoding}
			if hint.encoding == "digits" then
				found.tens, found.offset, found.size = offset, offset + 1, 2
			elseif hint.encoding == "digits_le" then
				found.tens, found.size = offset + 1, 2
			end
			return found
		end
	end
end

-- Coins until a hinted counter steps CONFIRM_CHANGES times alike while keeping still when idle, like the
-- search requires; sets its coinage. Losing credits in the long idle is a start timer (auto start).
local function confirms(found, coin)
	local deadline, steps, since, last = emu.time() + BOOT_MAX, 0, 0, value(found)
	while steps < CONFIRM_CHANGES and emu.time() < deadline do
		wait(IDLE)
		if value(found) ~= last then
			steps, last = 0, value(found)
		end
		press(coin, HOLD)
		wait(SETTLE)
		since = since + 1
		local now = value(found)
		if now > last then
			local alike = now - last == found.credits and since == found.spacing
			found.credits, found.spacing, steps, since = now - last, since, alike and steps + 1 or 1, 0
		elseif since >= Search.MAX_SPACING then
			steps = 0
		end
		last = now
	end
	wait(LONG_IDLE)
	found.autoStart = value(found) < last
	return steps == CONFIRM_CHANGES and value(found) <= last
end

-- Credits a counter gained since `old` ({byte, credits}), negative for a loss; a counter still "any"
-- takes the encoding that explains the smaller change (0x10 -> 0x09 is -1 in BCD, -7 in binary).
local function change(found, old)
	if found.encoding ~= "any" then
		return value(found) - old.credits
	end
	local new = found.region.peek(found.offset)
	local bin = new - old.byte
	local bcd = Search.isBcd(old.byte) and Search.isBcd(new) and Search.bcdDecode(new) - Search.bcdDecode(old.byte)
	if bcd and bcd ~= 0 and math.abs(bcd) < math.abs(bin) then
		found.encoding = "bcd"
		return bcd
	end
	return bin
end

local function mark(found)
	return {byte = found.region.peek(found.offset), credits = value(found)}
end

-- Credits `coin` adds to a counter over the counter's coins per step; slots may differ (rodland).
local function adds(found, coin)
	local old = mark(found)
	for _ = 1, found.spacing do
		press(coin, HOLD)
		wait(SETTLE + IDLE)
	end
	return change(found, old)
end

-- After a reset: waits the boot time, then coins until the counter holds `wanted` credits.
-- Games may count coins before they accept a start, and may keep their credits through a reset.
local function ready(found, coin, wanted, boot)
	wait(boot)
	local deadline = emu.time() + BOOT_MAX
	while value(found) < wanted and emu.time() < deadline do
		press(coin, HOLD)
		wait(SETTLE + IDLE)
	end
	return value(found) >= wanted
end

-- Presses each input, in up to START_ROUNDS rounds, until counters lose credits; returns the input
-- token, the cost and the counters that paid. Without a loss, returns whether any input changed a counter.
local function start(counters, inputs)
	local changed = false
	for round = 1, START_ROUNDS do
		if round > 1 then
			wait(START_PAUSE)
		end
		for _, input in ipairs(inputs) do
			local before = {}
			for index, found in ipairs(counters) do
				before[index] = mark(found)
			end
			press(input, START_HOLD)
			wait(START_SETTLE)
			-- The smallest loss wins: display copies (ASCII digits, tiles) fall further when the text clears.
			local paid, cost = {}, nil
			for index, found in ipairs(counters) do
				changed = changed or value(found) ~= before[index].credits
				-- More than MAX_STEP credits gone is no start, the byte is no credit counter.
				local loss = -change(found, before[index])
				loss = loss <= Search.MAX_STEP and loss or 0
				if loss > 0 and (cost == nil or loss < cost) then
					cost, paid = loss, {}
				end
				if loss > 0 and loss == cost then
					paid[#paid + 1] = found
				end
			end
			if cost then
				return token(input), cost, paid
			end
		end
	end
	return nil, changed
end

local function discover()
	local coins = {field("COIN1"), field("COIN2")}
	if not coins[1] then
		return {status = "found", reason = "no_coin_field"}
	end
	local result = {freeplay = "none", lockout_suspected = "no", auto_start = "no"}

	local switches = freePlaySwitches()
	result.dips    = dipReferences(switches)
	local disabled = false
	for _, entry in ipairs(switches) do
		result.freeplay         = entry.kind
		result.freeplay_default = entry.on and "on" or "off"
		if entry.on then
			if not entry.off then
				return {status = "found", reason = "freeplay_only", freeplay = entry.kind, freeplay_default = "on"}
			end
			entry.switch.user_value, disabled = entry.off, true
		end
	end
	if disabled then
		softReset()
	end

	if os.getenv("MAMECREDITS_POWER_CYCLED") or tuning.quiet_boot then
		wait(QUIET_BOOT)
	end

	-- Hints first: a confirmed hint replaces the search; verify mode only checks them.
	local list = regions()
	local counters, saturated, boot, method = {}, false, nil, "search"
	local began = emu.time()
	for _, hint in ipairs(hints()) do
		local found = located(list, hint)
		if found and confirms(found, coins[1]) then
			counters, boot, method = {found}, emu.time() - began, hint.method
			break
		end
	end
	-- Like a search without a counter, so the launcher power cycles it the same way.
	if #counters == 0 and os.getenv("MAMECREDITS_MODE") == "verify" then
		return {status = "found", reason = "no_reaction"}
	end
	if #counters == 0 then
		counters, saturated, boot = findCounters(list, coins[1])
	end
	if #counters == 0 then
		machine.options.entries.coin_lockout:value(false)
		counters, saturated, boot = findCounters(list, coins[1])
		result.lockout_suspected = #counters > 0 and "yes" or "no"
	end
	if #counters == 0 then
		result.status, result.reason = "found", "no_reaction"
		return result
	end

	-- Without a credit taken, Start is tried again from a reset: a coin, then Start at once, before a start
	-- countdown runs out (PGM: 6 s) and the game starts by itself.
	result.auto_start = counters[1].autoStart and "yes" or "no"
	local token, cost, paid
	if not counters[1].autoStart then
		token, cost, paid = start(counters, startInputs(1))
	end
	if not token then
		softReset()
		ready(counters[1], coins[1], 1, boot)
		token, cost, paid = start(counters, startInputs(1))
	end
	paid = token and paid
	local chosen = (paid or counters)[1]
	local cpu, space, address = location(chosen)
	result.cpu, result.space = cpu, space
	result.address, result.size, result.encoding = string.format("%x", address), chosen.size, chosen.encoding
	result.max_credits      = chosen.limit and Search.decode(chosen.limit, chosen.encoding) or ""
	result.coins_per_credit = chosen.spacing .. "/" .. chosen.credits
	result.candidates       = #(paid or counters)
	-- Coins that only ever add (health, play time) and inputs that never touch them: no credit is spent.
	if not token then
		result.status, result.reason = "found", cost and "no_start" or "coin_buys_play"
		result.start_mode = not cost and "coin_buys_play" or nil
		return result
	end
	result.start_mode, result.start1_field, result.start1_cost = "input", token, cost

	-- Second player: shared credits, or its own counter fed by its own coin slot.
	result.credit_scope = "shared"
	local second, coin2 = chosen, coins[1]
	local added = coins[2] and adds(chosen, coins[2])
	-- No coin slot adds more than MAX_STEP credits to a credit counter (mvsc2: a sound RAM byte took +52).
	if added and added > Search.MAX_STEP then
		result.status, result.reason = "found", "implausible"
		return result
	end
	if added and added > 0 then
		result.coins_per_credit = result.coins_per_credit .. "|" .. chosen.spacing .. "/" .. added
	elseif added then
		local own = findCounters(list, coins[2])
		if #own > 0 then
			result.credit_scope, second, coin2 = "per_player", own[1], coins[2]
			result.address          = result.address .. string.format("|%x", select(3, location(second)))
			result.coins_per_credit = result.coins_per_credit .. "|" .. second.spacing .. "/" .. second.credits
		end
	end
	local inputs = startInputs(2)
	if #inputs > 0 then
		softReset()
		local field2, cost2 = nil, nil
		if ready(second, coin2, START2_CREDITS, boot) then
			field2, cost2 = start({second}, inputs)
		end
		if field2 then
			result.start2_field, result.start2_cost = field2, cost2
		end
	end
	-- Later steps may have settled an "any" encoding.
	result.encoding = chosen.encoding
	result.status, result.reason, result.method = "success", "", method
	return result
end

local worker = coroutine.create(function()
	local ok, result = xpcall(discover, debug.traceback)
	if not ok then
		print("discover.lua: " .. result)
		result = {status = "error", reason = "script"}
	end
	report(result)
end)

local function frame()
	if coroutine.status(worker) == "suspended" then
		assert(coroutine.resume(worker))
	end
end

if emu.add_machine_frame_notifier then
	subscription = emu.add_machine_frame_notifier(frame)
else
	emu.register_frame(frame)
end
