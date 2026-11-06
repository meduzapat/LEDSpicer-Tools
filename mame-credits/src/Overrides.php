<?php

declare(strict_types=1);

namespace LEDSpicerTools\MameCredits;

/**
 * Reviewed per-set values for what discovery cannot measure, see overrides.txt.
 */
final class Overrides {

	/** @var array<string, array<string, string>> values keyed by set, then column. */
	private array $sets = [];

	public function __construct(string $path) {
		foreach (file($path, FILE_IGNORE_NEW_LINES) as $number => $line) {
			$tokens = preg_split('/\s+/', trim($line), -1, PREG_SPLIT_NO_EMPTY);
			if (!$tokens || str_starts_with($tokens[0], '#'))
				continue;
			$set = array_shift($tokens);
			foreach ($tokens as $token) {
				if (!str_contains($token, '='))
					throw new \UnexpectedValueException("$path:" . ($number + 1) . ": expected column=value, got $token");
				[$column, $value]          = explode('=', $token, 2);
				$this->sets[$set][$column] = $value;
			}
		}
	}

	/**
	 * The row with the set's overrides, or its parent's when the clone has none; a discovered row becomes success.
	 *
	 * @param array<string, string> $row
	 * @return array<string, string>
	 */
	public function apply(array $row, string $parent): array {
		$values = $this->sets[$row['set']] ?? $this->sets[$parent] ?? null;
		if ($values === null || !in_array($row['status'], [Status::Found->value, Status::Success->value], true))
			return $row;
		return [...$row, ...$values, 'status' => Status::Success->value, 'reason' => 'override'];
	}
}
