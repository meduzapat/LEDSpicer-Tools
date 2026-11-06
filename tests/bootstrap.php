<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

// Same policy as the tools: any warning is a failure.
set_error_handler(function (int $severity, string $message, string $file, int $line): never {
	throw new \ErrorException($message, 0, $severity, $file, $line);
});

putenv('MOCK_ROMPATH=' . __DIR__ . '/mocks/roms;$MOCK_MISSING_DIR');
