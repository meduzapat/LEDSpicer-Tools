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

	private Hints $hints;

	private string
		$resultsDir,
		$tempRoot;

	public function __construct(
		private readonly Options $options,
		private readonly Mame $mame,
		private readonly string $script,
		private readonly Overrides $overrides,
		private readonly Rules $ignore,
		private readonly Tuning $tuning,
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

		$machines    = Index::load($this->mame, $cache);
		$results     = new Results("$this->resultsDir/results.tsv");
		if ($this->options->dat) {
			Dat::write("$this->resultsDir/credits.dat", $results->rows(), $machines, $this->mame->build);
			echo "$this->resultsDir/credits.dat\n";
			rmdir($this->tempRoot);
			return 0;
		}
		$this->hints = new Hints($machines, "{$this->options->data}/results", $this->mame->folder, $results);
		$queue       = [];
		// Skipped sets are counted, not stored: their reason comes from the index on every run.
		// Keys of numeric set names ("1942") are integers: names come from the rows.
		foreach ($results->rows() as $row) {
			if ($this->skipReason($machines[$row['set']]) !== null)
				$results->remove($row['set']);
		}
		$skipped = [];
		foreach ($this->select($machines) as $machine) {
			$reason = $this->skipReason($machine);
			if ($reason !== null) {
				$key           = Status::Skipped->value . ":$reason";
				$skipped[$key] = ($skipped[$key] ?? 0) + 1;
			}
			elseif ($this->isQueued($results->row($machine->name))) {
				$queue[] = $machine;
			}
		}

		pcntl_async_signals(true);
		foreach ([SIGINT, SIGTERM] as $signal)
			pcntl_signal($signal, fn() => $this->interrupted = true);

		// Parents first: their results become their clones' hints.
		usort($queue, fn(Machine $a, Machine $b): int => ($a->cloneof !== '') <=> ($b->cloneof !== ''));
		$this->runQueue($queue, $results);
		$results->save();
		rmdir($this->tempRoot);

		$summary = [...$results->summary(), ...$skipped];
		ksort($summary);
		foreach ($summary as $key => $count)
			printf("%8d  %s\n", $count, $key);
		printf("Elapsed %.1fs%s\n", microtime(true) - $begin, $this->interrupted ? ', interrupted' : '');
		return $this->interrupted ? 130 : 0;
	}

	private function skipReason(Machine $machine): ?string {
		return $machine->skipReason($this->options->status) ?? $this->ignore->lookup($machine)['cause'] ?? null;
	}

	/**
	 * Whether a set with this previous result (null: never run) runs; --verify runs unverified successes only.
	 *
	 * @param array<string, string>|null $row
	 */
	private function isQueued(?array $row): bool {
		if ($this->options->verify)
			return $row !== null && $row['status'] === Status::Success->value && $row['verified'] !== 'yes';
		return $row === null
			|| $this->options->force
			|| ($this->options->retryErrors && $row['status'] === Status::Error->value);
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
			while (!$this->interrupted && count($running) < $this->options->jobs && ($next = self::next($queue, $running)) !== null)
				$running[] = $this->start($next, $results);
			usleep((int) (self::POLL_INTERVAL * 1e6));
			foreach ($running as $index => $job) {
				if ($this->interrupted)
					$job->kill();
				$row = $job->poll();
				if ($row === null)
					continue;
				unset($running[$index]);
				// Also when coins went in but no credit was spent: coins during boot may have confused the search.
				if (!$this->interrupted && !$job->powerCycled && in_array($row['reason'], ['no_reaction', 'coin_buys_play', 'no_start'], true)) {
					$running[] = $job->powerCycle();
					continue;
				}
				// Still deaf after a power cycle: waiting for a first-boot setup (calibration, settings), or broken.
				if ($job->powerCycled && $row['reason'] === 'no_reaction')
					$row['reason'] = 'needs_setup';
				// interrupted runs are incomplete, they are run again next time
				if (!$this->interrupted) {
					$this->finish($job, $row, $results);
					printf("[%d/%d] %s %s:%s %ss\n", ++$done, $total, $row['set'], $row['status'], $row['reason'], $row['elapsed']);
				}
				self::remove($job->tempDir);
			}
		}
	}

	/**
	 * Takes the first queued set whose parent is neither queued nor running, null when all wait.
	 *
	 * @param Machine[] $queue
	 * @param Job[] $running
	 */
	private static function next(array &$queue, array $running): ?Machine {
		$pending = array_flip(array_map(fn(Machine $m): string => $m->name, $queue));
		foreach ($running as $job)
			$pending[$job->machine->name] = true;
		foreach ($queue as $index => $machine) {
			if (!isset($pending[$machine->cloneof])) {
				unset($queue[$index]);
				return $machine;
			}
		}
		return null;
	}

	private function start(Machine $machine, Results $results): Job {
		$tempDir = "$this->tempRoot/{$machine->name}";
		$env     = $this->options->verify
			? ['MAMECREDITS_MODE' => 'verify', 'MAMECREDITS_HINTS' => 'verify,' . Hints::location($results->row($machine->name))]
			: ['MAMECREDITS_HINTS' => $this->hints->for($machine, $results)];
		[$seconds, $timeout] = $this->tuning->limits($machine, $this->options->seconds, $this->options->timeout);
		return new Job(
			$machine,
			$tempDir,
			$this->mame->command($machine, $tempDir, $this->script, $seconds, [...$this->tuning->arguments($machine), ...$this->options->extra]),
			[...Mame::RUN_ENV, ...$env, 'MAMECREDITS_TUNING' => $this->tuning->lua($machine)],
			$timeout
		);
	}

	/**
	 * Records the row; MAME's log is kept for errors only.
	 *
	 * @param array<string, string> $row
	 */
	private function finish(Job $job, array $row, Results $results): void {
		if ($this->options->verify) {
			// The stored result stands; the run only confirms it, at the same location.
			$stored = $results->row($row['set']);
			$row    = $this->overrides->apply($row, $job->machine);
			$same   = $row['status'] === Status::Success->value && Hints::location($row) === Hints::location($stored);
			$results->add([...$stored, 'verified' => $same ? 'yes' : 'no']);
			return;
		}
		$family = $this->hints->family($job->machine);
		$row    = [
			...$this->overrides->apply($row, $job->machine),
			'family'      => $family !== '' ? $family : $job->machine->cloneof,
			'power_cycle' => $job->powerCycled ? 'yes' : 'no',
		];
		$this->hints->record($row);
		$results->add($row);
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
