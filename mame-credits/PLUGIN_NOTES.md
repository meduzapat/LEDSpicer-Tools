# Notes for the LEDSpicer MAME plugin

What discovery found that the runtime plugin (reading `credits.dat`) must handle.

- **Read the credits at launch, never assume 0.** Many games keep credits in NVRAM (EEPROM, CMOS,
  battery RAM) across sessions (Shadow Dancer, Neo Geo). Report the current count in the launch message.
- **`game_started` comes from the credit counter going down**, not from an input: games also start
  by themselves when a countdown runs out (`auto_start=yes`, e.g. Psychic 5, PGM, Super Hang-On),
  and a start press without credits starts nothing. Exceptions by `start_mode`:
  - `coin`: the coin starts the game (Gun Fight);
  - `coin_buys_play`: coins buy health or time; the join input (from `credits.dat`) starts a player.
- **Locations:** `space=share` means `cpu` is a memory share tag and `address` an offset in it
  (`manager.machine.memory.shares[tag]:read_u8(offset)`); a memory mapper moves those at runtime.
- **Encodings:** `bin`, `bcd`, `any` (never seen above 9: read as binary), `digits` / `digits_le`
  (one decimal digit per byte, size 2).
- **Per player:** `credits1=`/`credits2=` when each player has a counter (TMNT); coinage can differ per
  slot (`coinage=1/1|1/2`, Neo Geo MVS).
- **Launch message DIPs:** read the switches in `dips=` live and report their setting names (free play,
  coinage, cabinet, coin slots, players). Settings kept in EEPROM/NVRAM menus are not readable that way.
