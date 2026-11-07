<?php

declare(strict_types=1);

namespace LEDSpicerTools\MameCredits;

use LEDSpicerTools\Tests\TempDirectory;
use PHPUnit\Framework\TestCase;

final class RulesTest extends TestCase {

	use TempDirectory;

	private function rules(string $content, bool $pairs): Rules {
		file_put_contents("$this->temp/r.txt", $content);
		return new Rules("$this->temp/r.txt", $pairs);
	}

	private static function machine(string $name, string $cloneof, string $sourcefile): Machine {
		return new Machine($name, $sourcefile, $cloneof, $cloneof, '', 'good', 2, 2, false, false, false, true, []);
	}

	public function testMostSpecificWinsPerValue(): void {
		$rules = $this->rules("# note\nx/drv.cpp a=1 b=1\nparent b=2 c=2\nclone c=3\n", true);
		$this->assertSame(['a' => '1', 'b' => '2', 'c' => '3'], $rules->lookup(self::machine('clone', 'parent', 'x/drv.cpp')));
		$this->assertSame([], $rules->lookup(self::machine('other', '', 'x/other.cpp')));
	}

	public function testOneWordIsTheCause(): void {
		$rules = $this->rules("x/poker.cpp video_poker\n", false);
		$this->assertSame(['cause' => 'video_poker'], $rules->lookup(self::machine('any', '', 'x/poker.cpp')));
	}

	public function testMalformedLinesFail(): void {
		foreach ([["game\n", false], ["game a b\n", false], ["game a\n", true]] as [$content, $pairs]) {
			try {
				$this->rules($content, $pairs);
				$this->fail("accepted: $content");
			}
			catch (\UnexpectedValueException) {
				$this->addToAssertionCount(1);
			}
		}
	}
}
