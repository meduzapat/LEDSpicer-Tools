<?php

declare(strict_types=1);

namespace LEDSpicerTools\MameCredits;

use LEDSpicerTools\Tests\TempDirectory;
use PHPUnit\Framework\TestCase;

final class IndexTest extends TestCase {

	use TempDirectory;

	public function testBuildsThenReadsCache(): void {
		$mame     = new Mame(__DIR__ . '/../../mocks/mame');
		$machines = Index::load($mame, $this->temp);
		$this->assertCount(14, $machines);
		$this->assertSame('bios2', $machines['ok']->bios);
		$this->assertSame(['index.tsv'], array_values(array_diff(scandir($this->temp), ['.', '..'])));
		$this->assertEquals($machines, Index::load($mame, $this->temp));
	}
}
