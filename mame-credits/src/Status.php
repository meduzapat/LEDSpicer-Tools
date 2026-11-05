<?php

declare(strict_types=1);

namespace LEDSpicerTools\MameCredits;

enum Status: string {
	case Error   = 'error';
	case Found   = 'found';
	case Success = 'success';
	case Skipped = 'skipped';
}
