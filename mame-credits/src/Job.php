<?php

declare(strict_types=1);

namespace LEDSpicerTools\MameCredits;

/**
 * One headless MAME process for one set, in its own temporary directory.
 */
final class Job {

	/// Reasons for MAME exit codes, see src/emu/main.h.
	private const EXIT_REASONS = [
		1 => 'validity',
		2 => 'missing_files',
		3 => 'fatal',
		4 => 'device',
		5 => 'no_such_system',
		6 => 'config',
	];

	/// Seconds between SIGTERM and SIGKILL on timeout.
	private const KILL_GRACE = 5;

	/** @var resource */
	private $process;

	private float
		$started,
		$killAt;

	private bool $timedOut = false;

	/**
	 * @param string[] $command full MAME command line.
	 * @param array<string, string> $env variables for discover.lua.
	 */
	public function __construct(
		public readonly Machine $machine,
		public readonly string $tempDir,
		private readonly array $command,
		private readonly array $env,
		private readonly int $timeout,
		public readonly bool $powerCycled = false,
	) {
		if (!$powerCycled)
			mkdir($tempDir, 0700, true);
		$this->started = microtime(true);
		$this->killAt  = $this->started + $timeout;
		$this->process = proc_open(
			$command,
			[0 => ['file', '/dev/null', 'r'], 1 => ['file', "$tempDir/mame.log", 'w'], 2 => ['redirect', 1]],
			$pipes,
			null,
			[...getenv(), ...$env, 'MAMECREDITS_OUT' => $this->resultFile()]
		);
	}

	/**
	 * Result row once MAME has exited, null while it still runs.
	 *
	 * @return array<string, string>|null
	 */
	public function poll(): ?array {
		$status = proc_get_status($this->process);
		if ($status['running']) {
			if (microtime(true) >= $this->killAt)
				$this->kill();
			return null;
		}
		proc_close($this->process);
		$row = $this->row($status['signaled'] ? -1 : $status['exitcode']);
		$row['elapsed'] = sprintf('%.1f', microtime(true) - $this->started);
		return $row;
	}

	/**
	 * The same run again on the NVRAM this one saved, like switching the cabinet off and on:
	 * games that initialize their NVRAM on first boot (robotron) only work from the second, and
	 * discover.lua lets it boot before coins (altbeast counts boot coins up to its credit limit).
	 */
	public function powerCycle(): self {
		unlink($this->resultFile());
		// Without hints: a hint can lead the first run astray (a parent's coin total, cencourt).
		$env = [...array_diff_key($this->env, ['MAMECREDITS_HINTS' => true]), 'MAMECREDITS_POWER_CYCLED' => '1'];
		return new self($this->machine, $this->tempDir, $this->command, $env, $this->timeout, true);
	}

	/**
	 * Stops MAME, used on timeout and on user interrupt.
	 */
	public function kill(): void {
		if (!$this->timedOut) {
			$this->timedOut = true;
			$this->killAt   = microtime(true) + self::KILL_GRACE;
			proc_terminate($this->process, SIGTERM);
		}
		elseif (microtime(true) >= $this->killAt) {
			proc_terminate($this->process, SIGKILL);
		}
	}

	public function resultFile(): string {
		return "$this->tempDir/result";
	}

	public function logFile(): string {
		return "$this->tempDir/mame.log";
	}

	/**
	 * @return array<string, string>
	 */
	private function row(int $exitCode): array {
		$row = ['set' => $this->machine->name, 'driver_status' => $this->machine->driverStatus, 'bios' => $this->machine->bios];
		if ($this->timedOut)
			return [...$row, 'status' => Status::Error->value, 'reason' => 'timeout'];
		if ($exitCode !== 0)
			return [...$row, 'status' => Status::Error->value, 'reason' => self::EXIT_REASONS[$exitCode] ?? "exit_$exitCode"];
		// discover.lua reports its own errors; no result means the emulated time cap (-str) ended the run.
		if (!is_file($this->resultFile()))
			return [...$row, 'status' => Status::Error->value, 'reason' => 'timeout'];
		return [...$row, ...self::parse($this->resultFile())];
	}

	/**
	 * Reads discover.lua's key=value lines.
	 *
	 * @return array<string, string>
	 */
	private static function parse(string $path): array {
		$values = [];
		foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
			[$key, $value] = explode('=', $line, 2);
			$values[$key]  = $value;
		}
		return $values;
	}
}
