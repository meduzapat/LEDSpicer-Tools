# Notes for the LEDSpicer MAME plugin

What discovery found that the runtime plugin (reading `credits.dat`) must handle.

## What LEDSpicer receives

Coin interactions only, per player; game states (demo, in game, continue, game over) are a possible
later version: the counter cannot tell demo from in game with 0 credits.

| Event | Source | Lights |
|---|---|---|
| `coin` | The COINn input itself, desirable but optional | — |
| `credit` | The credit counter goes up: enough coins paid a play | Coin slot stops blinking (or keeps, user mode); Start blinks |
| `game_started` | The counter goes down: Start pressed, or a timer started the game | Start stops blinking |

Two flows, told apart by `start_mode` in `credits.dat`:

- `input`: coin(s) → credit → Start (or the timer, `auto_start=yes`).
- `coin`: coin(s) → the game starts at the price, Start never blinks. Auto starting games are single
  player or have one coin slot per player, so the player is the slot's.

`credit_buys=extend` games (coins add health or time: Gauntlet, PlayChoice-10) never spend a credit at
start: the join input in `credits.dat` gives `game_started`. Known from testing only (overrides).
Coin slots are shared or per player (`credits1=`/`credits2=`), and continues may cost less than a play.

## Findings

- **Read the credits at launch, never assume 0.** Many games keep credits in NVRAM (EEPROM, CMOS,
  battery RAM) across sessions (Shadow Dancer, Neo Geo). Report the current count in the launch message.
- **`game_started` comes from the credit counter going down**, not from an input: games also start
  by themselves when a countdown runs out (`auto_start=yes`, e.g. Psychic 5, PGM), and a start press
  without credits starts nothing.
- **Locations:** `space=share` means `cpu` is a memory share tag and `address` an offset in it
  (`manager.machine.memory.shares[tag]:read_u8(offset)`); a memory mapper moves those at runtime.
  `space=ram` means `cpu` is a RAM device tag (main RAM a CPU installs at startup, PlayStation based
  boards) and `address` an offset: `emu.item(manager.machine.devices[tag].items["0/m_pointer"]):read(offset)`.
- **Encodings:** `bin`, `bcd`, `any` (never seen above 9: read as binary), `digits` / `digits_le`
  (one decimal digit per byte, size 2).
- **Per player:** `credits1=`/`credits2=` when each player has a counter (TMNT); coinage can differ per
  slot (`coinage=1/1|1/2`, Neo Geo MVS).
- **Launch message DIPs:** read the switches in `dips=` live and report their setting names (free play,
  coinage, cabinet, coin slots, players). Settings kept in EEPROM/NVRAM menus are not readable that way.
