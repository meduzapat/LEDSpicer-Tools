<?php

declare(strict_types=1);

namespace LEDSpicerTools\MameCredits;

/**
 * A config file of per-set rules: "<key> <tokens>" per line, "#" starts a comment.
 * A key is a set, a parent or a driver source file; a set's values merge its driver's, then its parent's,
 * then its own, the most specific winning. Tokens are column=value pairs, or one word stored as "cause".
 * A key may end in "@<constraint>" for MAME versions: 0.264, >0.264, >=0.264, <0.270, <=0.270 or
 * 0.250-0.264 (inclusive). Lines for other versions are dropped at load; a versioned line's values go
 * over its key's general ones.
 */
final class Rules {

	/** @var array<string, array<string, string>> values keyed by rule key, then name. */
	private array $entries = [];

	/**
	 * @param string $version MAME release, e.g. "0.264".
	 */
	public function __construct(string $path, bool $pairs, string $version) {
		$general = $versioned = [];
		foreach (file($path, FILE_IGNORE_NEW_LINES) as $number => $line) {
			$tokens = preg_split('/\s+/', trim($line), -1, PREG_SPLIT_NO_EMPTY);
			if (!$tokens || str_starts_with($tokens[0], '#'))
				continue;
			$where = "$path:" . ($number + 1);
			[$key, $constraint] = explode('@', array_shift($tokens), 2) + [1 => null];
			if ($constraint !== null && !self::matches($constraint, $version, $where))
				continue;
			$values = [];
			if (!$pairs) {
				if (count($tokens) !== 1)
					throw new \UnexpectedValueException("$where: expected <key> <cause>");
				$values['cause'] = $tokens[0];
			}
			foreach ($pairs ? $tokens : [] as $token) {
				if (!str_contains($token, '='))
					throw new \UnexpectedValueException("$where: expected name=value, got $token");
				[$name, $value] = explode('=', $token, 2);
				$values[$name]  = $value;
			}
			if ($constraint === null)
				$general[$key] = [...$general[$key] ?? [], ...$values];
			else
				$versioned[$key] = [...$versioned[$key] ?? [], ...$values];
		}
		foreach (array_keys($general + $versioned) as $key)
			$this->entries[$key] = [...$general[$key] ?? [], ...$versioned[$key] ?? []];
	}

	/**
	 * Whether a version constraint holds for the MAME release.
	 */
	private static function matches(string $constraint, string $version, string $where): bool {
		if (preg_match('/^(\d+\.\d+)-(\d+\.\d+)$/', $constraint, $range) && version_compare($range[1], $range[2], '<=')) {
			return version_compare($version, $range[1], '>=') && version_compare($version, $range[2], '<=');
		}
		if (preg_match('/^(>=|<=|>|<)?(\d+\.\d+)$/', $constraint, $bound))
			return version_compare($version, $bound[2], $bound[1] ?: '==');
		throw new \UnexpectedValueException("$where: bad version constraint @$constraint");
	}

	/**
	 * Merged values for a machine, empty when no rule names it.
	 *
	 * @return array<string, string>
	 */
	public function lookup(Machine $machine): array {
		return [
			...$this->entries[$machine->sourcefile] ?? [],
			...$this->entries[$machine->cloneof] ?? [],
			...$this->entries[$machine->name] ?? [],
		];
	}

	/**
	 * @return array<string, array<string, string>>
	 */
	public function entries(): array {
		return $this->entries;
	}
}
