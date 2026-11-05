# mame-credits

Offline discovery of the data a MAME plugin needs to report coins, credits and starts to LEDSpicer.
For each set it runs MAME headless, inserts coins, presses starts and diffs RAM, then records:

- Credit counter location (CPU, address space, address), size and encoding (binary/BCD).
- Coins per credit, shared or per-player credits.
- Start input per player and its credit cost.
- Free play availability and default, suspected coin lockout.

All data comes from the tool's own dynamic discovery.

> Status: in development.

## Requirements

- Linux.
- MAME ≥ 0.227 on `PATH`.
- PHP ≥ 8.1 CLI with the XMLReader extension.

## Output

Results are kept per MAME version, so runs with different versions never overwrite each other.
Earlier results (other versions, parent and BIOS-family sets) are used as hints and re-verified, never copied.

| File | Content |
|---|---|
| `results/<mame version>/results.tsv` | One line per set: status, reason and discovered data. |
| `results/<mame version>/credits.dat` | Verified entries only, consumed by the MAME plugin. |

## License

MIT, see [LICENSE](../LICENSE).
