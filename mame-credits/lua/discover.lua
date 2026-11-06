-- Discovery for one set, run by MAME through -autoboot_script.
-- Writes key=value lines to $MAMECREDITS_OUT and exits MAME.

-- MAME runs the autoboot script again on every machine reset; the first run owns the search.
if MAMECREDITS_STARTED then
	return
end
MAMECREDITS_STARTED = true

local Search = dofile(debug.getinfo(1, "S").source:match("^@(.-)[^/]*$") .. "search.lua")

-- Emulated seconds.
local IDLE     = 1.0  -- quiet window that exposes self changing bytes
local HOLD     = 0.1  -- coin switch closed
local SETTLE   = 1.0  -- after the coin, before reading the result
local BOOT_MAX = 90   -- coins inserted until a counter is confirmed
-- Longer than the time Search's MAX_SPACING coins take: slow timers that look like
-- "+1 every few coins" change in it.
local LONG_IDLE = 10

local CONFIRM_CHANGES = 3  -- credit steps a candidate needs to count as found
local ENCODE_CREDITS  = 10 -- credit count that separates BCD (0x10) from binary (0x0a)

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

local function field(token)
	for _, port in pairs(ioport.ports) do
		for _, candidate in pairs(port.fields) do
			if ioport:input_type_to_token(candidate.type) == token then
				return candidate
			end
		end
	end
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
					list[#list + 1] = {
						cpu = tag, space = name, first = entry.address_start, last = entry.address_end,
						rank = (tag == ":maincpu" and 0 or 2) + (entry.write.handlertype == "ram" and 0 or 1),
						read = function() return space:read_range(entry.address_start, entry.address_end, 8) end,
					}
				end
			end
		end
	end
	for tag, share in pairs(machine.memory.shares) do
		if not mapped[tag:match("[^:]+$")] then
			list[#list + 1] = {
				cpu = tag, space = "share", first = 0, last = share.size - 1, rank = 4,
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

local function pulse(switch)
	switch:set_value(1)
	wait(HOLD)
	switch:set_value(0)
end

local function encodingName(encoding)
	return encoding.bin and encoding.bcd and "any" or encoding.bcd and "bcd" or "bin"
end

local function decode(state)
	return encodingName(state.encoding) == "bcd" and Search.bcdDecode(state.value) or state.value
end

local function credits(state)
	return state.encoding.bcd or state.encoding.bin
end

-- Most plausible candidate: fewest credits per step (coinage defaults to 1 coin, 1 credit),
-- then the region rank, then the lowest address.
local function best(list, confirmed)
	table.sort(confirmed, function(a, b)
		local ra, oa = Search.split(a.candidate)
		local rb, ob = Search.split(b.candidate)
		if credits(a.state) ~= credits(b.state) then
			return credits(a.state) < credits(b.state)
		end
		if list[ra].rank ~= list[rb].rank then
			return list[ra].rank < list[rb].rank
		end
		return list[ra].first + oa < list[rb].first + ob
	end)
	return confirmed[1]
end

local function discover()
	local coin = field("COIN1")
	if not coin then
		return {status = "found", reason = "no_coin_field"}
	end
	local list   = regions()
	local search = Search.new()
	local before = snapshot(list)

	local function idle(seconds)
		wait(seconds)
		local quiet = snapshot(list)
		search:idle(before, quiet)
		before = quiet
	end

	-- One cycle: idle window, then a coin.
	local function cycle()
		idle(IDLE)
		pulse(coin)
		wait(SETTLE)
		local after = snapshot(list)
		search:coin(before, after)
		before = after
	end

	repeat
		cycle()
		if #search:confirmed(CONFIRM_CHANGES) > 0 then
			idle(LONG_IDLE)
		end
	until #search:confirmed(CONFIRM_CHANGES) > 0 or emu.time() > BOOT_MAX
	if #search:confirmed(CONFIRM_CHANGES) == 0 then
		return {status = "found", reason = "no_reaction"}
	end

	-- More credits until BCD and binary differ, or the game stops accepting them.
	local saturated = false
	while not saturated do
		local leader = best(list, search:confirmed(CONFIRM_CHANGES))
		if decode(leader.state) >= ENCODE_CREDITS then
			break
		end
		local saved = search:save()
		for _ = 1, leader.state.spacing do
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
	local confirmed = search:confirmed(CONFIRM_CHANGES)
	if #confirmed == 0 then
		return {status = "found", reason = "no_reaction"}
	end
	local chosen = best(list, confirmed)
	local region, offset = Search.split(chosen.candidate)
	return {
		status = "found", reason = "coins_only",
		cpu = list[region].cpu, space = list[region].space,
		address = string.format("%x", list[region].first + offset - 1), size = 1,
		encoding = encodingName(chosen.state.encoding),
		max_credits = saturated and decode(chosen.state) or "",
		coins_per_credit = chosen.state.spacing .. "/" .. credits(chosen.state),
		candidates = #confirmed,
	}
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
