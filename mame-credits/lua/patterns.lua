-- Lower case Lua patterns for DIP switch names and settings, by capability.
-- Built from MAME's own strings: the shared DEF_STR table (src/emu/ioport.cpp) and the
-- variants drivers spell themselves; excludes are look-alikes with another meaning.
return {
	freeplay = {
		include = {"free ?play", "^free$"},
		exclude = {"freeze", "free game", "for free"},
	},
	off   = {"^off$", "^no$"},
	coin1 = {"^1 coins?/1 credits?$"},
	-- Switches reported at launch, by kind; a capture in a pattern names the coin slot ("%1" in the kind).
	dips = {
		{kind = "coinage", "^coinage$"},
		{kind = "coin_%1", "^coin ([a-d1-4])$"},
		{kind = "cabinet", "^cabinet$", "^cabinet type$"},
		{kind = "coin_slots", "^coin slots?$", "^coin chutes?$"},
		{kind = "players", "^players$", "^number of players$", "^maximum players$"},
	},
}
