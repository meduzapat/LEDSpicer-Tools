<?php

declare(strict_types=1);

namespace LEDSpicerTools\MameCredits;

/**
 * Tab separated files with a mandatory header row.
 */
final class Tsv {

	/**
	 * @param string[] $columns expected header; any other header is a format mismatch.
	 * @return list<string[]>
	 */
	public static function read(string $path, array $columns): array {
		$lines = file($path, FILE_IGNORE_NEW_LINES);
		if ($lines[0] !== implode("\t", $columns))
			throw new \UnexpectedValueException("$path: unexpected header");
		$rows = [];
		foreach (array_slice($lines, 1) as $number => $line) {
			$row = explode("\t", $line);
			if (count($row) !== count($columns))
				throw new \UnexpectedValueException("$path:" . ($number + 2) . ': wrong column count');
			$rows[] = $row;
		}
		return $rows;
	}

	/**
	 * Writes atomically: readers never see a partial file.
	 *
	 * @param string[] $columns
	 * @param iterable<string[]> $rows
	 */
	public static function write(string $path, array $columns, iterable $rows): void {
		$content = self::line($columns);
		foreach ($rows as $row)
			$content .= self::line($row);
		file_put_contents("$path.tmp", $content);
		rename("$path.tmp", $path);
	}

	/**
	 * @param string[] $row
	 */
	public static function line(array $row): string {
		return implode("\t", $row) . "\n";
	}
}
