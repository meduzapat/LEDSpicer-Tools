<?php

declare(strict_types=1);

namespace LEDSpicerTools\MameCredits;

use LEDSpicerTools\Tests\TempDirectory;
use PHPUnit\Framework\TestCase;

final class OverridesTest extends TestCase {

	use TempDirectory;

	private function overrides(string $content): Overrides {
		file_put_contents("$this->temp/o.txt", $content);
		return new Overrides("$this->temp/o.txt");
	}

	private static function machine(string $name, string $cloneof = ''): Machine {
		return new Machine($name, 'x/drv.cpp', $cloneof, $cloneof, '', 'good', 2, 2, false, false, false, true, []);
	}

	public function testDiscoveredRowBecomesSuccess(): void {
		$row = $this->overrides("# note\n\ngame start_mode=coin\n")->apply(['set' => 'game', 'status' => 'found', 'reason' => 'coin_buys_play'], self::machine('game'));
		$this->assertSame(['set' => 'game', 'status' => 'success', 'reason' => 'override', 'start_mode' => 'coin'], $row);
	}

	public function testErrorsAndOtherSetsStay(): void {
		$overrides = $this->overrides("game start_mode=coin\n");
		$error     = ['set' => 'game', 'status' => 'error', 'reason' => 'missing_files'];
		$other     = ['set' => 'other', 'status' => 'found', 'reason' => 'no_start'];
		$this->assertSame($error, $overrides->apply($error, self::machine('game')));
		$this->assertSame($other, $overrides->apply($other, self::machine('other')));
	}

	public function testClonesInheritTheirParent(): void {
		$overrides = $this->overrides("game start_mode=coin\nclone2 start_mode=input\n");
		$this->assertSame('coin', $overrides->apply(['set' => 'clone1', 'status' => 'found', 'reason' => ''], self::machine('clone1', 'game'))['start_mode']);
		$this->assertSame('input', $overrides->apply(['set' => 'clone2', 'status' => 'found', 'reason' => ''], self::machine('clone2', 'game'))['start_mode']);
	}

	public function testMalformedTokenFails(): void {
		$this->expectException(\UnexpectedValueException::class);
		$this->overrides("game start_mode\n");
	}
}
