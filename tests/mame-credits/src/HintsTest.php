<?php

declare(strict_types=1);

namespace LEDSpicerTools\MameCredits;

use LEDSpicerTools\Tests\TempDirectory;
use PHPUnit\Framework\TestCase;

final class HintsTest extends TestCase {

	use TempDirectory;

	private static function machine(string $name, string $cloneof, string $romof, bool $isBios = false): Machine {
		return new Machine($name, '', $cloneof, $romof, '', 'good', 2, 2, $isBios, false, false, true, []);
	}

	public function testFamilyLocationNeedsEnoughAgreeingParents(): void {
		$machines = ['bios' => self::machine('bios', '', '', true)];
		foreach (['p1', 'p2', 'p3', 'p4'] as $name)
			$machines[$name] = self::machine($name, '', 'bios');
		$machines['c1'] = self::machine('c1', 'p1', 'p1');
		$results = new Results("$this->temp/r.tsv");
		$hints   = new Hints($machines, $this->temp, 'mame_0999', $results);
		$this->assertSame('bios', $hints->family($machines['c1']));
		foreach (['p1', 'p2', 'c1'] as $name) {
			$row = ['set' => $name, 'status' => 'success', 'cpu' => ':maincpu', 'space' => 'program', 'address' => 'd00034', 'encoding' => 'bcd'];
			$results->add($row);
			$hints->record($results->row($name));
		}
		$this->assertSame('', $hints->for($machines['p4'], $results), 'a clone does not vote');
		$results->add(['set' => 'p3', 'status' => 'success', 'cpu' => ':maincpu', 'space' => 'program', 'address' => 'd00034', 'encoding' => 'bcd']);
		$hints->record($results->row('p3'));
		$this->assertSame('family,:maincpu,program,d00034,bcd', $hints->for($machines['p4'], $results));
	}
}
