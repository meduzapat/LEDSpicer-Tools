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

-- Emulated seconds.
local IDLE         = 1.0  -- quiet window that exposes self changing bytes
local HOLD         = 0.1  -- coin switch closed
local SETTLE       = 1.0  -- after the coin, before reading the result
local START_HOLD   = 0.5  -- start button pressed; shorter presses are missed (tmnt)
local START_SETTLE = 3.0  -- after the start button, before reading the result
local BOOT_MAX     = 90   -- coins inserted until a counter is confirmed, also after a reset
-- Longer than the time Search's MAX_SPACING coins take: slow timers that look like
-- "+1 every few coins" change in it.
local LONG_IDLE = 10

local CONFIRM_CHANGES = 3  -- credit steps a candidate needs to count as found
local ENCODE_CREDITS  = 10 -- credit count that separates BCD (0x10) from binary (0x0a)
local MAX_BUTTONS     = 8  -- player buttons tried as start when there is no start input
local START2_CREDITS  = 4  -- credits needed before the second player start

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

-- Inputs that may start a game for a player: its start input, then its buttons in order.
local function startInputs(player)
	local inputs = {field("START" .. player)}
	for number = 1, MAX_BUTTONS do
		inputs[#inputs + 1] = field("P" .. player .. "_BUTTON" .. number)
	end
	return inputs
end

-- Readable RAM of every address space, each share once; shares no space maps are read directly.
-- A map names shares relative to the device owning the map, so shares match by their last tag part.
local function regions()
	local list, mapped = {}, {}
	for tag, device in pairs(machine.devices) do
		for name, space in pairs(device.spaces) do
			for _, entry in ipairs(space.map.entries) do
				local share = entry.share and entry.share:match("[^:]+$")
				if entry.read.handlertype == "ram" and not (share and mapped[share]) then
					if share then
						mapped[share] = true
					end
					local first = entry.address_start
					list[#list + 1] = {
						cpu = tag, space = name, first = first,
						rank = (tag == ":maincpu" and 0 or 2) + (entry.write.handlertype == "ram" and 0 or 1),
						read = function() return space:read_range(first, entry.address_end, 8) end,
						peek = function(offset) return space:read_u8(first + offset - 1) end,
					}
				end
			end
		end
	end
	for tag, share in pairs(machine.memory.shares) do
		if not mapped[tag:match("[^:]+$")] then
			list[#list + 1] = {
				cpu = tag, space = "share", first = 0, rank = 4,
				read = function()
					local parts = {}
					for first = 0, share.size - 1, 4096 do
						local bytes = {}
						for offset = first, math.min(first + 4095, share.size - 1) do
							bytes[#bytes + 1] = share:read_u8(offset)
						end
						parts[#parts + 1] = string.char(table.unpack(bytes))
					end
					return table.concat(parts)
				end,
				peek = function(offset) return share:read_u8(offset - 1) end,
			}
		end
	end
	return list
end

local function snapshot(list)
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

-- A found counter: where it is and how it counts.
local function counter(list, candidate, state)
	local index, offset = Search.split(candidate)
	local region = list[index]
	return {
		candidate = candidate, region = region, offset = offset, address = region.first + offset - 1,
		encoding = Search.encodingName(state.encoding), credits = state.encoding.bcd or state.encoding.bin,
		spacing = state.spacing,
	}
end

local function value(found)
	return Search.decode(found.region.peek(found.offset), found.encoding)
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
		search:idle(before, quiet)
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
	local saturated = false
	while not saturated do
		local leader = found()[1]
		if value(leader) >= ENCODE_CREDITS then
			break
		end
		local saved = search:save()
		for _ = 1, leader.spacing do
			cycle()
		end
		-- The leader stopping while coins go in is the credit limit, not a wrong candidate.
		if not search.candidates[leader.candidate] then
			search:restore(saved)
			saturated = true
		end
	end

	-- Candidates confirmed while counting up to ENCODE_CREDITS have not seen a long idle yet.
	idle(LONG_IDLE)
	return found(), saturated, boot
end

-- Credits `coin` adds to a counter over the counter's coins per step; slots may differ (rodland).
local function adds(found, coin)
	local start = value(found)
	for _ = 1, found.spacing do
		press(coin, HOLD)
		wait(SETTLE + IDLE)
	end
	return value(found) - start
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

-- Credits a counter lost from byte `old`; a counter still "any" takes the encoding that explains
-- the smaller loss (0x10 -> 0x09 is 1 credit in BCD, 7 in binary).
local function spent(found, old)
	local new = found.region.peek(found.offset)
	if found.encoding ~= "any" then
		return Search.decode(old, found.encoding) - Search.decode(new, found.encoding)
	end
	local bin, bcd = old - new, Search.isBcd(old) and Search.isBcd(new) and Search.bcdDecode(old) - Search.bcdDecode(new)
	if bcd and bcd > 0 and bcd < bin then
		found.encoding = "bcd"
		return bcd
	end
	return bin
end

-- Presses each input until counters lose credits; returns the input token, the cost and the counters that paid.
-- Without a loss, returns whether any input changed a counter at all.
local function start(counters, inputs)
	local changed = false
	for _, input in ipairs(inputs) do
		local before = {}
		for index, found in ipairs(counters) do
			before[index] = found.region.peek(found.offset)
		end
		press(input, START_HOLD)
		wait(START_SETTLE)
		local paid, cost = {}, nil
		for index, found in ipairs(counters) do
			changed = changed or found.region.peek(found.offset) ~= before[index]
			local loss = spent(found, before[index])
			if loss > 0 and (cost == nil or loss == cost) then
				cost, paid[#paid + 1] = loss, found
			end
		end
		if cost then
			return token(input), cost, paid
		end
	end
	return nil, changed
end

local function discover()
	local coins = {field("COIN1"), field("COIN2")}
	if not coins[1] then
		return {status = "found", reason = "no_coin_field"}
	end
	local result = {freeplay = "none", lockout_suspected = "no"}

	local switches = freePlaySwitches()
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

	local list = regions()
	local counters, saturated, boot = findCounters(list, coins[1])
	if #counters == 0 then
		machine.options.entries.coin_lockout:value(false)
		counters, saturated, boot = findCounters(list, coins[1])
		result.lockout_suspected = #counters > 0 and "yes" or "no"
	end
	if #counters == 0 then
		result.status, result.reason = "found", "no_reaction"
		return result
	end

	local token, cost, paid = start(counters, startInputs(1))
	paid = token and paid
	local chosen = (paid or counters)[1]
	result.cpu, result.space = chosen.region.cpu, chosen.region.space
	result.address, result.size, result.encoding = string.format("%x", chosen.address), 1, chosen.encoding
	result.max_credits      = saturated and value(chosen) or ""
	result.coins_per_credit = chosen.spacing .. "/" .. chosen.credits
	result.candidates       = #(paid or counters)
	-- Coins that only ever add (health, play time) and inputs that never touch them: no credit is spent.
	if not token then
		result.status, result.reason = "found", cost and "no_start" or "coin_buys_play"
		return result
	end
	result.start1_field, result.start1_cost = token, cost

	-- Second player: shared credits, or its own counter fed by its own coin slot.
	result.credit_scope = "shared"
	local second, coin2 = chosen, coins[1]
	local added = coins[2] and adds(chosen, coins[2])
	if added and added > 0 then
		result.coins_per_credit = result.coins_per_credit .. "|" .. chosen.spacing .. "/" .. added
	elseif added then
		local own = findCounters(list, coins[2])
		if #own > 0 then
			result.credit_scope, second, coin2 = "per_player", own[1], coins[2]
			result.address          = result.address .. string.format("|%x", second.address)
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
	result.status, result.reason, result.method = "success", "", "search"
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
