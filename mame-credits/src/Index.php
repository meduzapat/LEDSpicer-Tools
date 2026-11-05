<?php

declare(strict_types=1);

namespace LEDSpicerTools\MameCredits;

/**
 * All systems known to one MAME version, cached as TSV because -listxml is slow and large.
 */
final class Index {

	/**
	 * @return array<string, Machine> keyed by set name, in -listxml order.
	 */
	public static function load(Mame $mame, string $cacheDir): array {
		$path = "$cacheDir/index.tsv";
		if (!is_file($path))
			self::build($mame, $path);
		$machines = [];
		foreach (Tsv::read($path, Machine::FIELDS) as $row) {
			$machine = Machine::fromRow($row);
			$machines[$machine->name] = $machine;
		}
		return $machines;
	}

	private static function build(Mame $mame, string $path): void {
		$xml = "$path.xml";
		$mame->writeListXml($xml);
		$reader = \XMLReader::open($xml);
		$rows   = [];
		while ($reader->read()) {
			if ($reader->nodeType === \XMLReader::ELEMENT && $reader->name === 'machine')
				$rows[] = Machine::fromXml($reader->expand())->toRow();
		}
		$reader->close();
		unlink($xml);
		Tsv::write($path, Machine::FIELDS, $rows);
	}
}
