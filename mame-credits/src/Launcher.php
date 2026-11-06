<?php

declare(strict_types=1);

namespace LEDSpicerTools\MameCredits;

/**
 * Selects sets, runs them in parallel through MAME and records every outcome.
 */
final class Launcher {

	/// Seconds between process polls.
	private const POLL_INTERVAL = 0.1;

	private bool $interrupted = false;

	private string
		$resultsDir,
		$tempRoot;

	public function __construct(
		private readonly Options $options,
		private readonly Mame $mame,
		private readonly string $script,
		private readonly Overrides $overrides,
	) {
		$this->resultsDir = "{$options->data}/results/{$mame->folder}";
		$this->tempRoot   = sys_get_temp_dir() . '/mame-credits-' . getmypid();
	}

	/**
	 * @return int process exit code.
	 */
	public function run(): int {
		$begin = microtime(true);
		$cache = "{$this->options->data}/cache/{$this->mame->folder}";
		foreach ([$cache, "$this->resultsDir/logs", $this->tempRoot] as $directory) {
			if (!is_dir($directory))
				mkdir($directory, 0755, true);
		}

		$machines = Index::load($this->mame, $cache);
		$results  = new Results("$this->resultsDir/results.tsv");
		$queue    = [];
		foreach ($this->select($machines) as $machine) {
			if (!$this->options->force && !$this->isRetry($results->status($machine->name)))
				continue;
			$reason = $machine->skipReason();
			if ($reason === null)
				$queue[] = $machine;
			else
				$results->add(['set' => $machine->name, 'status' => Status::Skipped->value, 'reason' => $reason, 'mame_version' => $this->mame->build]);
		}

		pcntl_async_signals(true);
		foreach ([SIGINT, SIGTERM] as $signal)
			pcntl_signal($signal, fn() => $this->interrupted = true);

		$this->runQueue($queue, $results);
		$results->save();
		rmdir($this->tempRoot);

		foreach ($results->summary() as $key => $count)
			printf("%8d  %s\n", $count, $key);
		printf("Elapsed %.1fs%s\n", microtime(true) - $begin, $this->interrupted ? ', interrupted' : '');
		return $this->interrupted ? 130 : 0;
	}

	/**
	 * Whether a set with this previous status runs again; null means never run.
	 */
	private function isRetry(?string $status): bool {
		return $status === null || ($this->options->retryErrors && $status === Status::Error->value);
	}

	/**
	 * Requested machines, unknown names are an error.
	 *
	 * @param array<string, Machine> $machines
	 * @return Machine[]
	 */
	private function select(array $machines): array {
		if ($this->options->sets) {
			$unknown = array_diff($this->options->sets, array_keys($machines));
			if ($unknown)
				throw new \InvalidArgumentException('Unknown sets: ' . implode(', ', $unknown));
			$machines = array_intersect_key($machines, array_flip($this->options->sets));
		}
		if ($this->options->present) {
			$present  = $this->presentSets();
			$machines = array_filter(
				$machines,
				fn(Machine $m): bool => isset($present[$m->name]) || isset($present[$m->cloneof])
			);
		}
		return array_values($machines);
	}

	/**
	 * Names found in the rompath as archives or directories; clones of merged sets live in the parent's.
	 *
	 * @return array<string, true>
	 */
	private function presentSets(): array {
		$present = [];
		foreach ($this->mame->rompathDirectories() as $directory) {
			if (!is_dir($directory))
				continue;
			foreach (array_diff(scandir($directory), ['.', '..']) as $entry)
				$present[pathinfo($entry, PATHINFO_FILENAME)] = true;
		}
		return $present;
	}

	/**
	 * @param Machine[] $queue
	 */
	private function runQueue(array $queue, Results $results): void {
		$total   = count($queue);
		$done    = 0;
		$running = [];
		while (($queue && !$this->interrupted) || $running) {
			while ($queue && !$this->interrupted && count($running) < $this->options->jobs)
				$running[] = $this->start(array_shift($queue));
			usleep((int) (self::POLL_INTERVAL * 1e6));
			foreach ($running as $index => $job) {
				if ($this->interrupted)
					$job->kill();
				$row = $job->poll();
				if ($row === null)
					continue;
				unset($running[$index]);
				// interrupted runs are incomplete, they are run again next time
				if (!$this->interrupted) {
					$this->finish($job, $row, $results);
					printf("[%d/%d] %s %s:%s %ss\n", ++$done, $total, $row['set'], $row['status'], $row['reason'], $row['elapsed']);
				}
				self::remove($job->tempDir);
			}
		}
	}

	private function start(Machine $machine): Job {
		$tempDir = "$this->tempRoot/{$machine->name}";
		return new Job(
			$machine,
			$tempDir,
			$this->mame->command($machine, $tempDir, $this->script, $this->options->seconds, $this->options->extra),
			$this->options->timeout
		);
	}

	/**
	 * Records the row; MAME's log is kept for errors only.
	 *
	 * @param array<string, string> $row
	 */
	private function finish(Job $job, array $row, Results $results): void {
		$results->add([...$this->overrides->apply($row), 'mame_version' => $this->mame->build]);
		$log = "$this->resultsDir/logs/{$row['set']}.log";
		if ($row['status'] === Status::Error->value)
			rename($job->logFile(), $log);
		elseif (is_file($log))
			unlink($log);
	}

	private static function remove(string $path): void {
		if (is_dir($path) && !is_link($path)) {
			foreach (array_diff(scandir($path), ['.', '..']) as $entry)
				self::remove("$path/$entry");
			rmdir($path);
		}
		else {
			unlink($path);
		}
	}
}
