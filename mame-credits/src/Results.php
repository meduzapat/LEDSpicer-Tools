<?php

declare(strict_types=1);

namespace LEDSpicerTools\MameCredits;

/**
 * The results file of one MAME version: one row per set, resumable, sorted by set on save.
 */
final class Results {

	/// Stable column order; values a run does not resolve stay empty.
	public const COLUMNS = [
		'set', 'status', 'reason', 'family', 'method', 'driver_status', 'bios',
		'cpu', 'space', 'address', 'size', 'encoding', 'max_credits', 'coins_per_credit', 'credit_scope',
		'start_mode', 'start1_field', 'start1_cost', 'start2_field', 'start2_cost',
		'freeplay', 'freeplay_default', 'dips', 'lockout_suspected', 'auto_start', 'power_cycle', 'candidates', 'verified',
		'elapsed',
	];

	/** @var array<string, array<string, string>> rows keyed by set, then column. */
	private array $rows = [];

	/** @var resource append handle, every finished row is on disk immediately. */
	private $journal;

	public function __construct(private readonly string $path) {
		if (is_file($path))
			$this->rows = self::read($path);
		else
			file_put_contents($path, Tsv::line(self::COLUMNS));
		$this->journal = fopen($path, 'a');
	}

	/**
	 * Rows of a results file without opening it for writing, e.g. another MAME version's.
	 *
	 * @return array<string, array<string, string>>
	 */
	public static function read(string $path): array {
		$rows = [];
		foreach (Tsv::read($path, self::COLUMNS) as $row)
			$rows[$row[0]] = array_combine(self::COLUMNS, $row);
		return $rows;
	}

	public function status(string $set): ?string {
		return $this->rows[$set]['status'] ?? null;
	}

	/**
	 * @return array<string, string>|null
	 */
	public function row(string $set): ?array {
		return $this->rows[$set] ?? null;
	}

	/**
	 * @return array<string, array<string, string>>
	 */
	public function rows(): array {
		return $this->rows;
	}

	/**
	 * Stores a row; a later row for the same set replaces the earlier one.
	 *
	 * @param array<string, string> $values known columns only, the rest are left empty.
	 */
	public function add(array $values): void {
		$unknown = array_diff_key($values, array_flip(self::COLUMNS));
		if ($unknown)
			throw new \UnexpectedValueException('Unknown result columns: ' . implode(', ', array_keys($unknown)));
		$row = array_merge(array_fill_keys(self::COLUMNS, ''), $values);
		$this->rows[$row['set']] = $row;
		fwrite($this->journal, Tsv::line($row));
	}

	/**
	 * Forgets a set's row, e.g. one that is skipped now; the file changes on save().
	 */
	public function remove(string $set): void {
		unset($this->rows[$set]);
	}

	/**
	 * Rewrites the file sorted and without superseded rows.
	 */
	public function save(): void {
		fclose($this->journal);
		ksort($this->rows, SORT_STRING);
		Tsv::write($this->path, self::COLUMNS, $this->rows);
		$this->journal = fopen($this->path, 'a');
	}

	/**
	 * @return array<string, int> row count per "status:reason".
	 */
	public function summary(): array {
		$counts = [];
		foreach ($this->rows as $row) {
			$key          = "{$row['status']}:{$row['reason']}";
			$counts[$key] = ($counts[$key] ?? 0) + 1;
		}
		ksort($counts);
		return $counts;
	}
}
