<?php

declare(strict_types=1);

namespace LEDSpicerTools\MameCredits;

/**
 * Reviewed per-set result values for what discovery cannot measure, see config/overrides.txt.
 */
final class Overrides {

	private Rules $rules;

	public function __construct(string $path) {
		$this->rules = new Rules($path, true);
	}

	/**
	 * The row with the machine's overrides; a discovered row becomes success.
	 *
	 * @param array<string, string> $row
	 * @return array<string, string>
	 */
	public function apply(array $row, Machine $machine): array {
		$values = $this->rules->lookup($machine);
		if (!$values || !in_array($row['status'], [Status::Found->value, Status::Success->value], true))
			return $row;
		return [...$row, ...$values, 'status' => Status::Success->value, 'reason' => 'override'];
	}
}
