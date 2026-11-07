<?php

declare(strict_types=1);

namespace LEDSpicerTools\MameCredits;

use LEDSpicerTools\Tests\TempDirectory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RulesTest extends TestCase {

	use TempDirectory;

	private function rules(string $content, bool $pairs, string $version = '0.264'): Rules {
		file_put_contents("$this->temp/r.txt", $content);
		return new Rules("$this->temp/r.txt", $pairs, $version);
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

	public static function constraints(): array {
		return [
			['0.264', true], ['0.263', false], ['>0.263', true], ['>0.264', false], ['>=0.264', true],
			['<0.270', true], ['<0.264', false], ['<=0.264', true], ['0.250-0.264', true], ['0.265-0.270', false],
		];
	}

	#[DataProvider('constraints')]
	public function testVersionConstraint(string $constraint, bool $applies): void {
		$rules = $this->rules("game@$constraint a=1\n", true);
		$this->assertSame($applies ? ['a' => '1'] : [], $rules->lookup(self::machine('game', '', 'x/drv.cpp')));
	}

	public function testVersionedValuesGoOverGeneralOnesWhateverTheOrder(): void {
		$rules = $this->rules("game@>=0.260 b=2\ngame a=1 b=1\n", true);
		$this->assertSame(['a' => '1', 'b' => '2'], $rules->lookup(self::machine('game', '', 'x/drv.cpp')));
	}

	public function testMalformedLinesFail(): void {
		foreach ([["game\n", false], ["game a b\n", false], ["game a\n", true], ["game@~0.26 a=1\n", true], ["game@0.270-0.250 a=1\n", true]] as [$content, $pairs]) {
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
