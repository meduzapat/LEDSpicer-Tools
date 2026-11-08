<?php

declare(strict_types=1);

namespace LEDSpicerTools\MameCredits;

use LEDSpicerTools\Tests\TempDirectory;
use PHPUnit\Framework\TestCase;

final class TuningTest extends TestCase {

	use TempDirectory;

	private function tuning(string $content): Tuning {
		file_put_contents("$this->temp/t.txt", $content);
		return new Tuning("$this->temp/t.txt", '0.264');
	}

	public function testLimitsAndLuaSettings(): void {
		$tuning = $this->tuning("x/slow.cpp seconds=900 timeout=1800 boot=300 quiet_boot=30\n");
		$slow   = new Machine('slow', 'x/slow.cpp', '', '', '', 'good', 2, 2, false, false, false, true, []);
		$other  = new Machine('other', 'x/fast.cpp', '', '', '', 'good', 2, 2, false, false, false, true, []);
		$this->assertSame([900, 1800], $tuning->limits($slow, 300, 600));
		$this->assertSame([300, 600], $tuning->limits($other, 300, 600));
		$this->assertSame('boot=300,quiet_boot=30', $tuning->lua($slow));
		$this->assertSame('', $tuning->lua($other));
	}

	public function testDrcOffAddsNodrc(): void {
		$tuning = $this->tuning("x/slow.cpp drc=0\n");
		$slow   = new Machine('slow', 'x/slow.cpp', '', '', '', 'good', 2, 2, false, false, false, true, []);
		$other  = new Machine('other', 'x/fast.cpp', '', '', '', 'good', 2, 2, false, false, false, true, []);
		$this->assertSame(['-nodrc'], $tuning->arguments($slow));
		$this->assertSame([], $tuning->arguments($other));
	}

	public function testUnknownOrNonNumericSettingFails(): void {
		foreach (["game speed=2\n", "game boot=fast\n"] as $content) {
			try {
				$this->tuning($content);
				$this->fail("accepted: $content");
			}
			catch (\UnexpectedValueException) {
				$this->addToAssertionCount(1);
			}
		}
	}
}
