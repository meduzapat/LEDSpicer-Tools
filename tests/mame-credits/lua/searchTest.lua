dofile(debug.getinfo(1, "S").source:match("^@(.-)[^/]*$") .. "../../tools/test.lua")
local Search = dofile(here() .. "../../../mame-credits/lua/search.lua")

-- One region snapshot with byte 2 set to `value` among fixed neighbours.
local function snap(value)
	return {string.char(0x55, value, 0xaa)}
end

-- Feeds coins: each value is the byte after one coin, with a quiet idle in between.
local function feed(search, values)
	local before = snap(values[1])
	for index = 2, #values do
		local after = snap(values[index])
		search:idle(before, before)
		search:coin(before, after)
		before = after
	end
	return search
end

local function only(search)
	local list = search:confirmed(3)
	assert(#list == 1, "expected one confirmed candidate, got " .. #list)
	return list[1].state
end

test("diff finds changes across chunks", function()
	local a, b = string.rep("x", 200), string.rep("x", 70) .. "y" .. string.rep("x", 129)
	local changed = Search.diff(a, b)
	assert(#changed == 1 and changed[1] == 71)
end)

test("bcd helpers", function()
	assert(Search.isBcd(0x99) and not Search.isBcd(0x0a))
	assert(Search.bcdDecode(0x42) == 42)
end)

test("encoding names and decoding", function()
	assert(Search.encodingName({bin = 1, bcd = 1}) == "any")
	assert(Search.encodingName({bcd = 1}) == "bcd" and Search.encodingName({bin = 1}) == "bin")
	assert(Search.decode(0x12, "bcd") == 12 and Search.decode(0x12, "any") == 0x12)
end)

test("one credit per coin, BCD told apart at ten", function()
	local state = only(feed(Search.new(), {0x07, 0x08, 0x09, 0x10, 0x11}))
	assert(state.spacing == 1 and state.encoding.bcd == 1 and not state.encoding.bin)
end)

test("ambiguous encoding below ten", function()
	local state = only(feed(Search.new(), {0, 1, 2, 3}))
	assert(state.encoding.bin == 1 and state.encoding.bcd == 1)
end)

test("two coins per credit", function()
	local state = only(feed(Search.new(), {0, 1, 1, 2, 2, 3}))
	assert(state.spacing == 2)
end)

test("two credits per coin", function()
	assert(only(feed(Search.new(), {0, 2, 4, 6})).encoding.bin == 2)
end)

test("missed step drops", function()
	assert(#feed(Search.new(), {0, 1, 2, 2}):confirmed(1) == 0)
end)

test("changing step drops", function()
	assert(#feed(Search.new(), {0, 1, 2, 4}):confirmed(2) == 0)
end)

test("stale seed expires", function()
	assert(feed(Search.new(), {0, 1, 1, 1, 1, 1}):count() == 0)
end)

test("idle change drops", function()
	local search = feed(Search.new(), {0, 1})
	search:idle(snap(1), snap(2))
	assert(search:count() == 0)
end)

test("restore undoes coins", function()
	local search = feed(Search.new(), {0, 1, 2, 3})
	local saved  = search:save()
	feed(search, {3, 3, 3, 3, 3})
	search:restore(saved)
	assert(only(search).value == 3)
end)

done()
