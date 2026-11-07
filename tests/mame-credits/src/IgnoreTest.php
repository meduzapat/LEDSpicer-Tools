<?php

declare(strict_types=1);

namespace LEDSpicerTools\MameCredits;

use LEDSpicerTools\Tests\TempDirectory;
use PHPUnit\Framework\TestCase;

final class IgnoreTest extends TestCase {

	use TempDirectory;

	private static function machine(string $name, string $cloneof, string $sourcefile): Machine {
		return new Machine($name, $sourcefile, $cloneof, $cloneof, '', 'good', 2, 2, false, false, false, true, []);
	}

	public function testSetParentAndDriverEntries(): void {
		file_put_contents("$this->temp/i.txt", "# note\n\nfruit   fruit_machine\nx/poker.cpp video_poker\n");
		$ignore = new Ignore("$this->temp/i.txt");
		$this->assertSame('fruit_machine', $ignore->cause(self::machine('fruit', '', 'x/a.cpp')));
		$this->assertSame('fruit_machine', $ignore->cause(self::machine('fruit2', 'fruit', 'x/a.cpp')));
		$this->assertSame('video_poker', $ignore->cause(self::machine('anything', '', 'x/poker.cpp')));
		$this->assertNull($ignore->cause(self::machine('game', '', 'x/a.cpp')));
	}

	public function testMalformedLineFails(): void {
		file_put_contents("$this->temp/i.txt", "fruit\n");
		$this->expectException(\UnexpectedValueException::class);
		new Ignore("$this->temp/i.txt");
	}
}
