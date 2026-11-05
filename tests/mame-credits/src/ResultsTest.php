<?php

declare(strict_types=1);

namespace LEDSpicerTools\MameCredits;

use LEDSpicerTools\Tests\TempDirectory;
use PHPUnit\Framework\TestCase;

final class ResultsTest extends TestCase {

	use TempDirectory;

	public function testRowsSurviveWithoutSave(): void {
		$results = new Results("$this->temp/r.tsv");
		$results->add(['set' => 'b', 'status' => 'found', 'reason' => 'x']);
		$this->assertTrue((new Results("$this->temp/r.tsv"))->has('b'));
	}

	public function testSaveSortsAndKeepsLatestRow(): void {
		$results = new Results("$this->temp/r.tsv");
		$results->add(['set' => 'b', 'status' => 'error', 'reason' => 'timeout']);
		$results->add(['set' => 'a', 'status' => 'skipped', 'reason' => 'bios']);
		$results->add(['set' => 'b', 'status' => 'found', 'reason' => 'boot_only']);
		$results->save();
		$rows = Tsv::read("$this->temp/r.tsv", Results::COLUMNS);
		$this->assertSame(['a', 'b'], array_column($rows, 0));
		$this->assertSame(['found:boot_only' => 1, 'skipped:bios' => 1], (new Results("$this->temp/r.tsv"))->summary());
	}

	public function testUnknownColumnFails(): void {
		$this->expectException(\UnexpectedValueException::class);
		(new Results("$this->temp/r.tsv"))->add(['set' => 'a', 'colour' => 'red']);
	}
}
