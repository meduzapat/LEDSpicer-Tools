<?php

declare(strict_types=1);

namespace LEDSpicerTools\Tests;

/**
 * A fresh directory per test, removed afterwards.
 */
trait TempDirectory {

	protected string $temp;

	protected function setUp(): void {
		$this->temp = sys_get_temp_dir() . '/mame-credits-test-' . bin2hex(random_bytes(4));
		mkdir($this->temp);
	}

	protected function tearDown(): void {
		exec('rm -rf ' . escapeshellarg($this->temp));
	}
}
