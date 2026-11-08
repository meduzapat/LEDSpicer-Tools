<?php

declare(strict_types=1);

namespace LEDSpicerTools\MameCredits;

/**
 * Known counter locations to verify before searching, best first: the same set in another MAME
 * version, the parent, the BIOS family once PROMOTE parents agree, the parent in another version.
 */
final class Hints {

	/// Parents of a BIOS family that must agree before their counter is tried on the other members.
	public const PROMOTE = 3;

	/** @var list<array<string, array<string, string>>> rows of the other versions, newest first. */
	private array $previous = [];

	/** @var array<string, array<string, array<string, true>>> family, then location, then voting parents. */
	private array $votes = [];

	/**
	 * @param array<string, Machine> $machines
	 */
	public function __construct(private readonly array $machines, string $resultsRoot, string $current, Results $results) {
		$paths = glob("$resultsRoot/mame_*/results.tsv");
		rsort($paths);
		foreach ($paths as $path) {
			if (basename(dirname($path)) !== $current)
				$this->previous[] = Results::read($path);
		}
		foreach ($results->rows() as $row)
			$this->record($row);
	}

	/**
	 * BIOS a set belongs to through its romof chain, empty when none.
	 */
	public function family(Machine $machine): string {
		$romof = $this->machines[$machine->romof] ?? null;
		return $romof === null ? '' : ($romof->isBios ? $romof->name : $this->family($romof));
	}

	/**
	 * Counts a result's location for its family, when the set is a parent that succeeded.
	 *
	 * @param array<string, string> $row
	 */
	public function record(array $row): void {
		$machine  = $this->machines[$row['set']];
		$family   = $this->family($machine);
		$location = self::location($row);
		if ($location !== null && $family !== '' && $machine->cloneof === '')
			$this->votes[$family][$location][$machine->name] = true;
	}

	/**
	 * Hints for discover.lua, "method,cpu,space,address,encoding;...".
	 */
	public function for(Machine $machine, Results $results): string {
		$hints = [];
		foreach ($this->previous as $rows)
			$hints[] = self::hint('previous_version', $rows[$machine->name] ?? null, true);
		$hints[] = self::hint('parent', $results->row($machine->cloneof), false);
		foreach ($this->votes[$this->family($machine)] ?? [] as $location => $parents) {
			if (count($parents) >= self::PROMOTE)
				$hints[] = "family,$location";
		}
		foreach ($this->previous as $rows)
			$hints[] = self::hint('parent_previous', $rows[$machine->cloneof] ?? null, true);
		return implode(';', array_unique(array_filter($hints)));
	}

	/**
	 * "cpu,space,address,encoding" of a success row; the first address for per player counters.
	 *
	 * @param array<string, string> $row
	 */
	public static function location(array $row): ?string {
		if ($row['status'] !== Status::Success->value || $row['address'] === '')
			return null;
		return implode(',', [$row['cpu'], $row['space'], explode('|', $row['address'])[0], $row['encoding']]);
	}

	/**
	 * @param array<string, string>|null $row
	 */
	private static function hint(string $method, ?array $row, bool $verified): ?string {
		if ($row === null || ($verified && $row['verified'] !== 'yes'))
			return null;
		$location = self::location($row);
		return $location === null ? null : "$method,$location";
	}
}
