<?php

declare(strict_types=1);

namespace LEDSpicerTools\MameCredits;

/**
 * The MAME binary: identity, user configuration and per-set command lines.
 */
final class Mame {

	/// MAME options that keep every run headless, deterministic and away from the user's setup.
	private const RUN_OPTIONS = [
		'-noreadconfig', '-noplugins',
		'-video', 'none', '-sound', 'none', '-nothrottle', '-output', 'none',
		'-keyboardprovider', 'none', '-mouseprovider', 'none', '-lightgunprovider', 'none',
		'-joystickprovider', 'none', '-midiprovider', 'none',
	];

	/// Writable directories redirected into the per-run temporary directory.
	private const RUN_DIRECTORIES = ['nvram', 'cfg', 'diff'];

	/// Full build string, e.g. "0.264 (mame0264-140-gd062c313a35-dirty)".
	public readonly string $build;

	/// Release number, e.g. "0.264".
	public readonly string $version;

	/// Per-version folder name for results and cache, e.g. "mame_0264".
	public readonly string $folder;

	/// Effective rompath from the user's configuration, environment variables expanded:
	/// MAME expands them when reading its ini files, never on the command line.
	public readonly string $rompath;

	public function __construct(public readonly string $binary) {
		$this->build   = trim(self::capture([$binary, '-version']));
		$this->version = explode(' ', $this->build)[0];
		$this->folder  = 'mame_' . str_replace('.', '', $this->version);
		preg_match('/^rompath\s+(.*)$/m', self::capture([$binary, '-showconfig']), $match);
		$this->rompath = preg_replace_callback(
			'/\$(?:\{(\w+)\}|(\w+))/',
			fn(array $m): string => (string) getenv($m[1] !== '' ? $m[1] : $m[2]),
			trim($match[1])
		);
	}

	/**
	 * Writes the complete -listxml output to a file.
	 */
	public function writeListXml(string $path): void {
		self::run([$this->binary, '-listxml'], ['file', $path, 'w']);
	}

	/**
	 * Command line for one discovery run.
	 *
	 * @param string[] $extra user supplied MAME arguments.
	 * @return string[]
	 */
	public function command(Machine $machine, string $tempDir, string $script, int $seconds, array $extra): array {
		$command = [$this->binary, $machine->name, ...self::RUN_OPTIONS, '-rompath', $this->rompath, '-str', (string) $seconds];
		foreach (self::RUN_DIRECTORIES as $directory)
			array_push($command, "-{$directory}_directory", "$tempDir/$directory");
		if ($machine->bios !== '')
			array_push($command, '-bios', $machine->bios);
		return [...$command, '-autoboot_script', $script, ...$extra];
	}

	/**
	 * @return string[]
	 */
	public function rompathDirectories(): array {
		return explode(';', $this->rompath);
	}

	private static function capture(array $command): string {
		$output = tempnam(sys_get_temp_dir(), 'mame-credits-');
		self::run($command, ['file', $output, 'w']);
		$content = file_get_contents($output);
		unlink($output);
		return $content;
	}

	private static function run(array $command, array $stdout): void {
		$process = proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => $stdout, 2 => ['file', '/dev/null', 'w']], $pipes);
		$code    = proc_close($process);
		if ($code !== 0)
			throw new \RuntimeException(implode(' ', $command) . " failed with exit code $code");
	}
}
