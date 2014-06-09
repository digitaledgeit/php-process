<?php

use deit\platform\System;
use deit\process\Process;

include __DIR__.'/bootstrap.php';

if (System::isWin()) {
	$cmd = 'ping 10.0.0.1';
} else {
	$cmd = 'ping -c 4 10.0.0.1';
}

$exitCode = Process::exec($cmd, [
	'stdout' => function($data) {
		echo 'OUT: '.$data.PHP_EOL;
	},
	'stderr' => function($data) {
		echo 'ERR: '.$data.PHP_EOL;
	}
]);

var_dump($exitCode);