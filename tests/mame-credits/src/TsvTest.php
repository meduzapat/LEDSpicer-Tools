<?php

declare(strict_types=1);

namespace LEDSpicerTools\MameCredits;

use LEDSpicerTools\Tests\TempDirectory;
use PHPUnit\Framework\TestCase;

final class TsvTest extends TestCase {

	use TempDirectory;

	public function testRoundTrip(): void {
		Tsv::write("$this->temp/a.tsv", ['x', 'y'], [['1', ''], ['3', '4']]);
		$this->assertSame([['1', ''], ['3', '4']], Tsv::read("$this->temp/a.tsv", ['x', 'y']));
	}

	public function testHeaderMismatchFails(): void {
		Tsv::write("$this->temp/a.tsv", ['x', 'y'], []);
		$this->expectException(\UnexpectedValueException::class);
		Tsv::read("$this->temp/a.tsv", ['x', 'z']);
	}

	public function testColumnCountMismatchFails(): void {
		file_put_contents("$this->temp/a.tsv", "x\ty\n1\n");
		$this->expectException(\UnexpectedValueException::class);
		Tsv::read("$this->temp/a.tsv", ['x', 'y']);
	}
}
