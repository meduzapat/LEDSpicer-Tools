-- Credit counter search, independent of MAME: works on RAM snapshots.
-- A snapshot is a list of strings, one per scanned region.
-- A candidate is a byte that stays still while idle and steps a constant number of credits
-- every `spacing` coins: the coinage is `spacing` coins for `step` credits.
-- Encodings: binary, packed BCD, or one decimal digit per byte ("digit", wraps 9 -> 0 into a
-- neighbouring tens byte).

local Search = {}
Search.__index = Search

-- Bytes compared at once when looking for changes.
local CHUNK = 64

-- Highest coins per step expected; a candidate that does not step again within it is dropped.
local MAX_SPACING = 4
Search.MAX_SPACING = MAX_SPACING

-- Memory bounds for boards with tens of megabytes of RAM, where coins may change millions of bytes:
-- a region changing more bytes than CHANGED_MAX in one coin seeds nothing for that coin, and seeding
-- stops at CANDIDATES_MAX candidates (about 150 MB of Lua tables).
local CHANGED_MAX    = 2000000
local CANDIDATES_MAX = 1000000

-- Highest credits per step expected, also the most a start may take.
local MAX_STEP = 9
Search.MAX_STEP = MAX_STEP

-- Offsets (1 based) where two equally long strings differ, nil when more than `limit` do.
function Search.diff(a, b, limit)
	local changed = {}
	for first = 1, #a, CHUNK do
		local last = first + CHUNK - 1
		if a:sub(first, last) ~= b:sub(first, last) then
			for offset = first, math.min(last, #a) do
				if a:byte(offset) ~= b:byte(offset) then
					if #changed == limit then
						return nil
					end
					changed[#changed + 1] = offset
				end
			end
		end
	end
	return changed
end

function Search.isBcd(value)
	return value & 0x0f <= 9 and value >> 4 <= 9
end

function Search.bcdDecode(value)
	return (value >> 4) * 10 + (value & 0x0f)
end

-- "any" (binary and BCD both explain every step seen), "bcd", "digits" or "bin" for an encoding set.
function Search.encodingName(encoding)
	return encoding.bin and encoding.bcd and "any" or encoding.bcd and "bcd" or encoding.digit and "digits" or "bin"
end

-- Credits a byte holds under an encoding name.
function Search.decode(value, name)
	return name == "bcd" and Search.bcdDecode(value) or value
end

local function step(credits)
	return credits >= 1 and credits <= MAX_STEP and credits or nil
end

-- Credits added by old -> new per encoding, e.g. {bin = 7, bcd = 1} for 0x09 -> 0x10.
local function steps(old, new)
	return {
		bin   = step((new - old) & 0xff),
		bcd   = Search.isBcd(old) and Search.isBcd(new) and step(Search.bcdDecode(new) - Search.bcdDecode(old)) or nil,
		digit = old <= 9 and new <= 9 and step((new - old) % 10) or nil,
	}
end

function Search.new()
	return setmetatable({candidates = {}, coins = 0}, Search)
end

local function key(region, offset)
	return region << 32 | offset
end

function Search.split(candidate)
	return candidate >> 32, candidate & 0xffffffff
end

-- Whether old -> new loses credits under one of the encodings still possible.
local function loses(encoding, old, new)
	local lost = {
		bin   = old - new,
		bcd   = Search.isBcd(new) and Search.bcdDecode(old) - Search.bcdDecode(new),
		digit = new <= 9 and old - new,
	}
	for name in pairs(encoding) do
		if lost[name] and lost[name] > 0 and lost[name] <= MAX_STEP then
			return true
		end
	end
	return false
end

-- Drops candidates that changed without input, except a candidate stepped `changes` times that lost
-- credits: the game started by itself (a start timer ran out) and the candidate is marked autoStart.
function Search:idle(before, after, changes)
	for candidate, state in pairs(self.candidates) do
		local region, offset = Search.split(candidate)
		local new = after[region]:byte(offset)
		if before[region]:byte(offset) ~= new then
			if state.changes >= changes and loses(state.encoding, state.value, new) then
				state.value, state.autoStart = new, true
			else
				self.candidates[candidate] = nil
			end
		end
	end
end

-- Adds every byte that stepped credits and is not a candidate yet, within the memory bounds.
function Search:seed(before, after)
	local count = self:count()
	for region = 1, #before do
		for _, offset in ipairs(Search.diff(before[region], after[region], CHANGED_MAX) or {}) do
			local candidate = key(region, offset)
			local encoding  = steps(before[region]:byte(offset), after[region]:byte(offset))
			if next(encoding) and not self.candidates[candidate] then
				if count == CANDIDATES_MAX then
					return
				end
				self.candidates[candidate] = {value = after[region]:byte(offset), last = self.coins, changes = 1, encoding = encoding}
				count = count + 1
			end
		end
	end
end

-- Applies one coin: advances or drops candidates, then seeds new ones.
function Search:coin(before, after)
	self.coins = self.coins + 1
	for candidate, state in pairs(self.candidates) do
		local region, offset = Search.split(candidate)
		local new     = after[region]:byte(offset)
		local elapsed = self.coins - state.last
		if new == state.value then
			if elapsed >= (state.spacing or MAX_SPACING) then
				self.candidates[candidate] = nil
			end
		else
			local encoding = steps(state.value, new)
			for name, credits in pairs(encoding) do
				if state.encoding[name] ~= credits then
					encoding[name] = nil
				end
			end
			if not next(encoding) or (state.spacing or elapsed) ~= elapsed then
				self.candidates[candidate] = nil
			else
				state.spacing, state.value, state.last, state.encoding = elapsed, new, self.coins, encoding
				state.changes = state.changes + 1
			end
		end
	end
	self:seed(before, after)
end

function Search:count()
	local count = 0
	for _ in pairs(self.candidates) do
		count = count + 1
	end
	return count
end

-- Candidates that stepped at least `changes` times, as a list of {candidate, state}.
function Search:confirmed(changes)
	local list = {}
	for candidate, state in pairs(self.candidates) do
		if state.changes >= changes then
			list[#list + 1] = {candidate = candidate, state = state}
		end
	end
	return list
end

-- Shallow copy of the candidate states, to undo coins that hit the credit limit.
function Search:save()
	local copy = {}
	for candidate, state in pairs(self.candidates) do
		copy[candidate] = {
			value = state.value, last = state.last, changes = state.changes, spacing = state.spacing, autoStart = state.autoStart,
			encoding = {bin = state.encoding.bin, bcd = state.encoding.bcd, digit = state.encoding.digit},
		}
	end
	return {candidates = copy, coins = self.coins}
end

function Search:restore(saved)
	self.candidates, self.coins = saved.candidates, saved.coins
end

return Search
