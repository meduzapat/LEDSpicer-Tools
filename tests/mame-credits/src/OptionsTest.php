<?php

declare(strict_types=1);

namespace LEDSpicerTools\MameCredits;

use LEDSpicerTools\Tests\TempDirectory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OptionsTest extends TestCase {

	use TempDirectory;

	public function testDefaults(): void {
		$options = new Options([]);
		$this->assertSame([], $options->sets);
		$this->assertGreaterThan(0, $options->jobs);
		$this->assertFalse($options->force);
	}

	public function testValuesAndExtraArguments(): void {
		$options = new Options(['--sets', 'a, b', '--jobs', '3', '--force', '--', '-bios', 'x']);
		$this->assertSame(['a', 'b'], $options->sets);
		$this->assertSame(3, $options->jobs);
		$this->assertTrue($options->force);
		$this->assertSame(['-bios', 'x'], $options->extra);
	}

	public function testSetsFromFile(): void {
		file_put_contents("$this->temp/sets", "a\n\nb\n");
		$this->assertSame(['a', 'b'], (new Options(['--sets', "@$this->temp/sets"]))->sets);
	}

	public static function invalid(): array {
		return [[['--nope']], [['--jobs']], [['--jobs', '0']], [['--timeout', '-1']]];
	}

	#[DataProvider('invalid')]
	public function testInvalidArgumentsFail(array $args): void {
		$this->expectException(\InvalidArgumentException::class);
		new Options($args);
	}
}
