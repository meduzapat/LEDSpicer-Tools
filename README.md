# LEDSpicer-Tools

Standalone tools for the [LEDSpicer](https://github.com/meduzapat/LEDSpicer) project.
Each tool lives in its own directory and is independent of LEDSpicer's code.

| Tool | Description |
|---|---|
| [mame-credits](mame-credits/) | Discovers per-game credit counters, start inputs and free play by running MAME headless. |

## Branches

| Branch | Purpose |
|---|---|
| `main` | Releases (tagged through GitHub Releases). |
| `development` | Trunk. Work branches off here and merges back through pull requests. |

## Tests

```bash
composer install
vendor/bin/phpunit
```

Tests mirror the tool directories under `tests/`; reusable mocks are in `tests/mocks/`, helpers in `tests/tools/`.

## License

MIT, see [LICENSE](LICENSE). Generated data files are MIT as well.
