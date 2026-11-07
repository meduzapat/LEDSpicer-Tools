<?php

declare(strict_types=1);

namespace LEDSpicerTools\MameCredits;

use LEDSpicerTools\Tests\TempDirectory;
use PHPUnit\Framework\TestCase;

final class DatTest extends TestCase {

	use TempDirectory;

	private static function row(string $set, string $family, string $address, string $verified = 'yes'): array {
		return [...array_fill_keys(Results::COLUMNS, ''), 'set' => $set, 'status' => 'success', 'family' => $family,
			'cpu' => ':maincpu', 'space' => 'program', 'address' => $address, 'size' => '1', 'encoding' => 'bcd',
			'start_mode' => 'input', 'start1_field' => 'START1', 'start1_cost' => '1', 'coins_per_credit' => '1/1',
			'verified' => $verified];
	}

	public function testFamiliesSharedEntriesAndVerifiedOnly(): void {
		$machines = ['bios' => new Machine('bios', '', '', '', '', 'good', 2, 2, true, false, false, true, [])];
		$rows     = [];
		foreach (['p1', 'p2', 'p3'] as $name) {
			$machines[$name] = new Machine($name, '', '', 'bios', '', 'good', 2, 2, false, false, false, true, []);
			$rows[$name]     = self::row($name, 'bios', 'd00034');
		}
		$machines['solo']  = new Machine('solo', '', '', '', '', 'good', 2, 2, false, false, false, true, []);
		$rows['solo']      = self::row('solo', '', '4e6e|4e6f');
		$rows['unchecked'] = self::row('unchecked', '', '1234', 'no');
		Dat::write("$this->temp/credits.dat", $rows, $machines, 'test');
		$dat = file_get_contents("$this->temp/credits.dat");
		$this->assertStringContainsString("@bios:\ncredits=:maincpu,program,d00034,1,bcd\n\n", $dat);
		$this->assertStringContainsString("p1:\np2:\np3:\nfamily=bios\nstart_mode=input\nstart1=START1,1\n", $dat);
		$this->assertStringContainsString("solo:\ncredits1=:maincpu,program,4e6e,1,bcd\ncredits2=:maincpu,program,4e6f,1,bcd\n", $dat);
		$this->assertStringNotContainsString('unchecked', $dat);
	}
}
