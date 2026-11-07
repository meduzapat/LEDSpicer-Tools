<?php

declare(strict_types=1);

namespace LEDSpicerTools\MameCredits;

use LEDSpicerTools\Tests\TempDirectory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class JobTest extends TestCase {

	use TempDirectory;

	private function runJob(string $set, int $timeout): array {
		$machine = Machine::fromRow([$set, '', '', '', '', 'good', '1', '1', '0', '0', '0', '1', '']);
		$job     = new Job($machine, "$this->temp/$set", [__DIR__ . '/../../mocks/mame', $set], [], $timeout);
		while (($row = $job->poll()) === null)
			usleep(10000);
		return $row;
	}

	public static function outcomes(): array {
		return [
			['ok', 'success', ''],
			['missing', 'error', 'missing_files'],
			['crash', 'error', 'fatal'],
			['noresult', 'error', 'timeout'],
		];
	}

	#[DataProvider('outcomes')]
	public function testOutcome(string $set, string $status, string $reason): void {
		$row = $this->runJob($set, 10);
		$this->assertSame([$set, $status, $reason], [$row['set'], $row['status'], $row['reason']]);
	}

	public function testWallClockTimeoutKills(): void {
		$row = $this->runJob('hang', 1);
		$this->assertSame('timeout', $row['reason']);
		$this->assertLessThan(5, (float) $row['elapsed']);
	}
}
