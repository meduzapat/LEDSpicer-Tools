<?php

declare(strict_types=1);

namespace LEDSpicerTools\MameCredits;

/**
 * One system from `mame -listxml`, reduced to what discovery needs.
 */
final class Machine {

	/// Serialized field order in the index cache.
	public const FIELDS = [
		'name', 'sourcefile', 'cloneof', 'romof', 'bios', 'driver_status',
		'players', 'coins', 'isbios', 'isdevice', 'ismechanical', 'runnable',
	];

	public function __construct(
		public readonly string $name,
		public readonly string $sourcefile,
		public readonly string $cloneof,
		public readonly string $romof,
		public readonly string $bios,
		public readonly string $driverStatus,
		public readonly int $players,
		public readonly int $coins,
		public readonly bool $isBios,
		public readonly bool $isDevice,
		public readonly bool $isMechanical,
		public readonly bool $isRunnable,
	) {}

	/**
	 * Reason this machine is not worth running, null when it must be run.
	 */
	public function skipReason(): ?string {
		return match (true) {
			$this->isBios       => 'bios',
			$this->isDevice     => 'device',
			$this->isMechanical => 'mechanical',
			!$this->isRunnable  => 'not_runnable',
			$this->coins === 0  => 'no_coins',
			default             => null,
		};
	}

	/**
	 * Builds a machine from a `<machine>` element of -listxml.
	 */
	public static function fromXml(\DOMElement $node): self {
		$bios   = '';
		$driver = '';
		$input  = null;
		foreach ($node->childNodes as $child) {
			if (!$child instanceof \DOMElement)
				continue;
			match ($child->tagName) {
				'biosset' => $bios = $child->getAttribute('default') === 'yes' ? $child->getAttribute('name') : $bios,
				'driver'  => $driver = $child->getAttribute('status'),
				'input'   => $input = $child,
				default   => null,
			};
		}
		return new self(
			$node->getAttribute('name'),
			$node->getAttribute('sourcefile'),
			$node->getAttribute('cloneof'),
			$node->getAttribute('romof'),
			$bios,
			$driver,
			(int) $input?->getAttribute('players'),
			(int) $input?->getAttribute('coins'),
			$node->getAttribute('isbios') === 'yes',
			$node->getAttribute('isdevice') === 'yes',
			$node->getAttribute('ismechanical') === 'yes',
			$node->getAttribute('runnable') !== 'no',
		);
	}

	/**
	 * @param string[] $row values in FIELDS order.
	 */
	public static function fromRow(array $row): self {
		return new self(
			$row[0], $row[1], $row[2], $row[3], $row[4], $row[5],
			(int) $row[6], (int) $row[7],
			$row[8] === '1', $row[9] === '1', $row[10] === '1', $row[11] === '1',
		);
	}

	/**
	 * @return string[] values in FIELDS order.
	 */
	public function toRow(): array {
		return [
			$this->name, $this->sourcefile, $this->cloneof, $this->romof, $this->bios, $this->driverStatus,
			(string) $this->players, (string) $this->coins,
			(string) (int) $this->isBios, (string) (int) $this->isDevice,
			(string) (int) $this->isMechanical, (string) (int) $this->isRunnable,
		];
	}
}
