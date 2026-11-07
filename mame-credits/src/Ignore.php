<?php

declare(strict_types=1);

namespace LEDSpicerTools\MameCredits;

/**
 * Sets and drivers not worth discovering, with the cause they are skipped for, see ignore.txt.
 */
final class Ignore {

	/** @var array<string, string> cause keyed by set name or driver source file. */
	private array $causes = [];

	public function __construct(string $path) {
		foreach (file($path, FILE_IGNORE_NEW_LINES) as $number => $line) {
			$tokens = preg_split('/\s+/', trim($line), -1, PREG_SPLIT_NO_EMPTY);
			if (!$tokens || str_starts_with($tokens[0], '#'))
				continue;
			if (count($tokens) !== 2)
				throw new \UnexpectedValueException("$path:" . ($number + 1) . ': expected <set or driver> <cause>');
			$this->causes[$tokens[0]] = $tokens[1];
		}
	}

	/**
	 * Cause for skipping a machine (its name, its parent or its driver), null when it is not ignored.
	 */
	public function cause(Machine $machine): ?string {
		return $this->causes[$machine->name] ?? $this->causes[$machine->cloneof] ?? $this->causes[$machine->sourcefile] ?? null;
	}
}
