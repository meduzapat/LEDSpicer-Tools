<?php

declare(strict_types=1);

namespace LEDSpicerTools\MameCredits;

/**
 * Command line: `discover.php [options] [-- mame arguments]`.
 */
final class Options {

	public const USAGE = <<<'TXT'
Usage: discover.php [options] [-- extra MAME arguments]

  --sets LIST     comma separated sets, or @FILE with one set per line (default: all)
  --present       only sets (or their parent) found in the rompath
  --force         run sets that already have a result
  --retry-errors  run sets whose result is an error
  --verify        re-run successes not verified yet, checking only their own result
  --dat           write credits.dat from the verified successes, run nothing
  --jobs N        parallel MAME processes (default: CPU count)
  --timeout S     wall clock seconds per set (default: 600)
  --seconds S     emulated seconds per set (default: 300)
  --data DIR      directory for results/ and cache/ (default: current directory)
  --help          this text

TXT;

	/** @var string[] */
	public array
		$sets  = [],
		$extra = [];

	public bool
		$present     = false,
		$force       = false,
		$retryErrors = false,
		$verify      = false,
		$dat         = false,
		$help        = false;

	public int
		$jobs,
		$timeout = 600,
		$seconds = 300;

	public string $data = '.';

	/**
	 * @param string[] $args arguments without the program name.
	 */
	public function __construct(array $args) {
		$this->jobs = (int) shell_exec('nproc');
		while ($args) {
			$arg = array_shift($args);
			match ($arg) {
				'--'             => [$this->extra, $args] = [$args, []],
				'--sets'         => $this->sets = self::sets(self::value($arg, $args)),
				'--present'      => $this->present = true,
				'--force'        => $this->force = true,
				'--retry-errors' => $this->retryErrors = true,
				'--verify'       => $this->verify = true,
				'--dat'          => $this->dat = true,
				'--jobs'         => $this->jobs = self::number($arg, $args),
				'--timeout'      => $this->timeout = self::number($arg, $args),
				'--seconds'      => $this->seconds = self::number($arg, $args),
				'--data'         => $this->data = self::value($arg, $args),
				'--help'         => $this->help = true,
				default          => throw new \InvalidArgumentException("Unknown option: $arg"),
			};
		}
	}

	private static function value(string $option, array &$args): string {
		if (!$args)
			throw new \InvalidArgumentException("$option needs a value");
		return array_shift($args);
	}

	private static function number(string $option, array &$args): int {
		$value = self::value($option, $args);
		if (!ctype_digit($value) || $value === '0')
			throw new \InvalidArgumentException("$option needs a positive number");
		return (int) $value;
	}

	/**
	 * @return string[]
	 */
	private static function sets(string $value): array {
		$list = str_starts_with($value, '@')
			? file(substr($value, 1), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)
			: explode(',', $value);
		return array_map('trim', $list);
	}
}
