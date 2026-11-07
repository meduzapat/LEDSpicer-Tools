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

	private function path(): string {
		return "$this->temp/results/mame_0999/results.tsv";
	}

	private function discover(string ...$args): array {
		$command = [PHP_BINARY, __DIR__ . '/../../../mame-credits/discover.php', '--data', $this->temp, '--timeout', '1', ...$args];
		$process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, null, [
			...getenv(),
			'PATH' => realpath(__DIR__ . '/../../mocks') . ':' . getenv('PATH'),
		]);
		stream_get_contents($pipes[1]);
		$this->assertSame(0, proc_close($process));
		$rows = [];
		foreach (Tsv::read($this->path(), Results::COLUMNS) as $row)
			$rows[$row[0]] = "$row[1]:$row[2]";
		return $rows;
	}

	public function testRunsAndClassifiesEverySet(): void {
		$this->assertSame([
			'crash'    => 'error:fatal',
			'deaf'     => 'found:needs_setup',
			'hang'     => 'error:timeout',
			'missing'  => 'error:missing_files',
			'noresult' => 'error:timeout',
			'nvinit'   => 'found:boot_only',
			'ok'       => 'success:',
			'okclone'  => 'success:',
		], $this->discover());
		$this->assertFileExists("$this->temp/results/mame_0999/logs/crash.log");
	}

	public function testNowSkippedSetsLeaveTheResults(): void {
		$this->discover('--sets', 'ok');
		$rows = Results::read($this->path());
		Tsv::write($this->path(), Results::COLUMNS, [...$rows, 'poker' => [...$rows['ok'], 'set' => 'poker']]);
		$this->assertSame(['ok' => 'success:'], $this->discover('--sets', 'ok,poker'));
	}

	public function testPresentKeepsSetsAndClonesFoundInRompath(): void {
		$this->assertSame(
			['crash' => 'error:fatal', 'hang' => 'error:timeout', 'ok' => 'success:', 'okclone' => 'success:'],
			$this->discover('--present')
		);
	}

	/**
	 * @param array<string, string> $values columns to set in a stored row
	 */
	private function edit(string $set, array $values): void {
		$rows = Results::read($this->path());
		$rows[$set] = [...$rows[$set], ...$values];
		Tsv::write($this->path(), Results::COLUMNS, $rows);
	}

	private function column(string $set, string $column): string {
		return Results::read($this->path())[$set][$column];
	}

	public function testResumeSkipsKnownSets(): void {
		$this->discover('--sets', 'ok');
		$this->edit('ok', ['reason' => 'kept']);
		$this->assertSame(['ok' => 'success:kept', 'okclone' => 'success:'], $this->discover('--sets', 'ok,okclone'));
	}

	public function testRetryErrorsRunsOnlyFailedSets(): void {
		$this->discover('--sets', 'ok,missing');
		$this->edit('ok', ['reason' => 'kept']);
		$this->edit('missing', ['reason' => 'stale']);
		$this->assertSame(['missing' => 'error:missing_files', 'ok' => 'success:kept'], $this->discover('--sets', 'ok,missing', '--retry-errors'));
	}

	public function testCloneWaitsForItsParentAndTakesItsHint(): void {
		$this->discover('--sets', 'ok,okclone', '--jobs', '2');
		$this->assertSame('search', $this->column('ok', 'method'));
		$this->assertSame('parent', $this->column('okclone', 'method'));
		$this->assertSame('ok', $this->column('okclone', 'family'));
	}

	public function testVerifyConfirmsTheStoredLocationOnly(): void {
		$this->discover('--sets', 'ok,okclone');
		$this->edit('okclone', ['address' => '99']);
		$this->discover('--verify');
		$this->assertSame('yes', $this->column('ok', 'verified'));
		$this->assertSame('no', $this->column('okclone', 'verified'));
		$this->assertSame('99', $this->column('okclone', 'address'));
	}

	public function testOtherVersionsVerifiedResultIsTheFirstHint(): void {
		mkdir("$this->temp/results/mame_0998", 0755, true);
		$row = array_fill_keys(Results::COLUMNS, '');
		Tsv::write("$this->temp/results/mame_0998/results.tsv", Results::COLUMNS, [
			[...$row, 'set' => 'ok', 'status' => 'success', 'cpu' => ':maincpu', 'space' => 'program', 'address' => '10', 'encoding' => 'bin', 'verified' => 'yes'],
		]);
		$this->discover('--sets', 'ok');
		$this->assertSame('previous_version', $this->column('ok', 'method'));
	}
}
