<?php

declare(strict_types=1);

namespace LEDSpicerTools\MameCredits;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MachineTest extends TestCase {

	private static function machine(string $name): Machine {
		$document = new \DOMDocument();
		$document->load(__DIR__ . '/../../mocks/listxml.xml');
		foreach ($document->getElementsByTagName('machine') as $node) {
			if ($node->getAttribute('name') === $name)
				return Machine::fromXml($node);
		}
		throw new \LogicException("$name not in fixture");
	}

	public function testFromXmlReadsDiscoveryFields(): void {
		$machine = self::machine('ok');
		$this->assertSame('bios2', $machine->bios);
		$this->assertSame('imperfect', $machine->driverStatus);
		$this->assertSame(2, $machine->coins);
		$this->assertSame('ok', self::machine('okclone')->cloneof);
	}

	public static function skipReasons(): array {
		return [
			['ok', null],
			['abios', 'bios'],
			['adevice', 'device'],
			['mech', 'mechanical'],
			['console', 'no_coins'],
			['broken', 'preliminary'],
			['poker', 'gambling'],
			['mahjong', 'mahjong'],
		];
	}

	#[DataProvider('skipReasons')]
	public function testSkipReason(string $name, ?string $reason): void {
		$this->assertSame($reason, self::machine($name)->skipReason('imperfect'));
	}

	public function testStatusLevel(): void {
		$this->assertSame('imperfect', self::machine('ok')->skipReason('good'));
		$this->assertNull(self::machine('broken')->skipReason('preliminary'));
	}

	public function testRowRoundTrip(): void {
		$machine = self::machine('ok');
		$this->assertEquals($machine, Machine::fromRow($machine->toRow()));
	}
}
