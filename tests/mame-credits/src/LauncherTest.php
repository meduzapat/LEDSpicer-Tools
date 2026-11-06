<?php

declare(strict_types=1);

namespace LEDSpicerTools\MameCredits;

use LEDSpicerTools\Tests\TempDirectory;
use PHPUnit\Framework\TestCase;

/**
 * End to end through discover.php with the mock MAME first on PATH.
 */
final class LauncherTest extends TestCase {

	use TempDirectory;

	private function discover(string ...$args): array {
		$command = [PHP_BINARY, __DIR__ . '/../../../mame-credits/discover.php', '--data', $this->temp, '--timeout', '1', ...$args];
		$process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, null, [
			...getenv(),
			'PATH' => realpath(__DIR__ . '/../../mocks') . ':' . getenv('PATH'),
		]);
		stream_get_contents($pipes[1]);
		$this->assertSame(0, proc_close($process));
		$rows = [];
		foreach (Tsv::read("$this->temp/results/mame_0999/results.tsv", Results::COLUMNS) as $row)
			$rows[$row[0]] = "$row[1]:$row[2]";
		return $rows;
	}

	public function testRunsAndClassifiesEverySet(): void {
		$this->assertSame([
			'abios'    => 'skipped:bios',
			'adevice'  => 'skipped:device',
			'console'  => 'skipped:no_coins',
			'crash'    => 'error:fatal',
			'hang'     => 'error:timeout',
			'mech'     => 'skipped:mechanical',
			'missing'  => 'error:missing_files',
			'noresult' => 'error:timeout',
			'ok'       => 'found:boot_only',
			'okclone'  => 'found:boot_only',
		], $this->discover());
		$this->assertFileExists("$this->temp/results/mame_0999/logs/crash.log");
	}

	public function testPresentKeepsSetsAndClonesFoundInRompath(): void {
		$this->assertSame(
			['crash' => 'error:fatal', 'hang' => 'error:timeout', 'ok' => 'found:boot_only', 'okclone' => 'found:boot_only'],
			$this->discover('--present')
		);
	}

	public function testResumeSkipsKnownSets(): void {
		$this->discover('--sets', 'ok');
		file_put_contents("$this->temp/results/mame_0999/results.tsv", str_replace('boot_only', 'kept', file_get_contents("$this->temp/results/mame_0999/results.tsv")));
		$this->assertSame(['ok' => 'found:kept', 'okclone' => 'found:boot_only'], $this->discover('--sets', 'ok,okclone'));
	}

	public function testRetryErrorsRunsOnlyFailedSets(): void {
		$this->discover('--sets', 'ok,missing');
		$path = "$this->temp/results/mame_0999/results.tsv";
		file_put_contents($path, str_replace(['boot_only', 'missing_files'], ['kept', 'stale'], file_get_contents($path)));
		$this->assertSame(['missing' => 'error:missing_files', 'ok' => 'found:kept'], $this->discover('--sets', 'ok,missing', '--retry-errors'));
	}
}
