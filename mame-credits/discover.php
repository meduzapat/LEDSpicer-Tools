#!/usr/bin/env php
<?php

declare(strict_types=1);

namespace LEDSpicerTools\MameCredits;

// Any warning is a bug: crash loudly.
set_error_handler(function (int $severity, string $message, string $file, int $line): never {
	throw new \ErrorException($message, 0, $severity, $file, $line);
});

spl_autoload_register(function (string $class): void {
	require __DIR__ . '/src/' . substr($class, strlen(__NAMESPACE__) + 1) . '.php';
});

$options = new Options(array_slice($argv, 1));
if ($options->help) {
	echo Options::USAGE;
	exit(0);
}
$config   = __DIR__ . '/config';
$mame     = new Mame('mame');
$launcher = new Launcher(
	$options,
	$mame,
	__DIR__ . '/lua/discover.lua',
	new Overrides("$config/overrides.txt", $mame->version),
	new Rules("$config/ignore.txt", false, $mame->version),
	new Tuning("$config/tuning.txt", $mame->version)
);
exit($launcher->run());
