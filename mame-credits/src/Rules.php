<?php

declare(strict_types=1);

namespace LEDSpicerTools\MameCredits;

/**
 * A config file of per-set rules: "<key> <tokens>" per line, "#" starts a comment.
 * A key is a set, a parent or a driver source file; a set's values merge its driver's, then its parent's,
 * then its own, the most specific winning. Tokens are column=value pairs, or one word stored as "cause".
 */
final class Rules {

	/** @var array<string, array<string, string>> values keyed by rule key, then name. */
	private array $entries = [];

	public function __construct(string $path, bool $pairs) {
		foreach (file($path, FILE_IGNORE_NEW_LINES) as $number => $line) {
			$tokens = preg_split('/\s+/', trim($line), -1, PREG_SPLIT_NO_EMPTY);
			if (!$tokens || str_starts_with($tokens[0], '#'))
				continue;
			$key   = array_shift($tokens);
			$where = "$path:" . ($number + 1);
			if (!$pairs) {
				if (count($tokens) !== 1)
					throw new \UnexpectedValueException("$where: expected <key> <cause>");
				$this->entries[$key] = ['cause' => $tokens[0]];
				continue;
			}
			foreach ($tokens as $token) {
				if (!str_contains($token, '='))
					throw new \UnexpectedValueException("$where: expected name=value, got $token");
				[$name, $value]             = explode('=', $token, 2);
				$this->entries[$key][$name] = $value;
			}
		}
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
