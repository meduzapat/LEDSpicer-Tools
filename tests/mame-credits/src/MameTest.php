<?php

declare(strict_types=1);

namespace LEDSpicerTools\MameCredits;

use PHPUnit\Framework\TestCase;

final class MameTest extends TestCase {

	private static function mame(): Mame {
		return new Mame(__DIR__ . '/../../mocks/mame');
	}

	public function testIdentityAndRompath(): void {
		$mame = self::mame();
		$this->assertSame('0.999 (mock)', $mame->build);
		$this->assertSame('0.999', $mame->version);
		$this->assertSame('mame_0999', $mame->folder);
		$this->assertStringEndsWith(';$MOCK_MISSING_DIR', $mame->rompath);
	}

	public function testRompathExpandsVariables(): void {
		putenv('MOCK_MISSING_DIR=/somewhere');
		$this->assertSame('/somewhere', self::mame()->rompathDirectories()[1]);
		putenv('MOCK_MISSING_DIR');
	}

	public function testCommand(): void {
		$machine = Machine::fromRow(['ok', '', '', '', 'bios2', 'good', '2', '2', '0', '0', '0', '1']);
		$command = self::mame()->command($machine, '/t', 's.lua', 30, ['-extra']);
		$this->assertSame('ok', $command[1]);
		$this->assertContains('-noreadconfig', $command);
		$this->assertContains('/t/nvram', $command);
		$this->assertSame(['-bios', 'bios2'], array_slice($command, array_search('-bios', $command), 2));
		$this->assertSame(['-autoboot_script', 's.lua', '-extra'], array_slice($command, -3));
	}

	public function testFailingBinaryFails(): void {
		$this->expectException(\RuntimeException::class);
		new Mame('false');
	}
}
