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
- PHP ≥ 8.1 CLI with the XMLReader, DOM and pcntl extensions.

## Usage

```bash
./discover.php --help
```

Runs every set with coins, or a list (`--sets a,b` or `--sets @file`), resumable: sets already in the results are skipped unless `--force` (or `--retry-errors` for failed ones).
Each run uses fresh temporary NVRAM/CFG directories and ignores the user's ini files and plugins; only the rompath is taken from MAME's configuration.
Arguments after `--` are passed to MAME.

`overrides.txt` holds reviewed behaviour discovery cannot measure (e.g. games where coins buy health);
it is applied over the discovered result of those sets.

## Output

Results are kept per MAME version, so runs with different versions never overwrite each other.
Earlier results (other versions, parent and BIOS-family sets) are used as hints and re-verified, never copied.

| File | Content |
|---|---|
| `results/mame_<version>/results.tsv` | One line per set: status, reason and discovered data. |
| `results/mame_<version>/credits.dat` | Verified entries only, consumed by the MAME plugin. |
| `results/mame_<version>/logs/<set>.log` | MAME output of sets that ended in error. |
| `cache/mame_<version>/index.tsv` | Systems from `mame -listxml`, built once per version. |

## License

MIT, see [LICENSE](../LICENSE).
