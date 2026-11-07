<?php

declare(strict_types=1);

namespace LEDSpicerTools\MameCredits;

/**
 * Run settings for sets that need other than the defaults, see config/tuning.txt.
 * seconds and timeout replace the --seconds and --timeout limits; the rest reach discover.lua.
 */
final class Tuning {

	/// Settings discover.lua reads from MAMECREDITS_TUNING.
	private const LUA = ['boot', 'quiet_boot'];

	private Rules $rules;

	public function __construct(string $path) {
		$this->rules = new Rules($path, true);
		foreach ($this->rules->entries() as $key => $values) {
			foreach ($values as $name => $value) {
				if (!in_array($name, ['seconds', 'timeout', ...self::LUA], true) || !ctype_digit($value))
					throw new \UnexpectedValueException("$path: $key: unknown setting or not a number: $name=$value");
			}
		}
	}

	/**
	 * @return array{int, int} emulated seconds and wall clock timeout for the machine.
	 */
	public function limits(Machine $machine, int $seconds, int $timeout): array {
		$values = $this->rules->lookup($machine);
		return [(int) ($values['seconds'] ?? $seconds), (int) ($values['timeout'] ?? $timeout)];
	}

	/**
	 * MAMECREDITS_TUNING for discover.lua: "name=value,...".
	 */
	public function lua(Machine $machine): string {
		$values = array_intersect_key($this->rules->lookup($machine), array_flip(self::LUA));
		return implode(',', array_map(fn(string $name, string $value): string => "$name=$value", array_keys($values), $values));
	}
}
