<?php

namespace deit\process;
use \deit\stream\StringOutputStream;

/**
 * Process test
 * @author James Newell <james@digitaledgeit.com.au>
 */
class ProcessTest extends \PHPUnit_Framework_TestCase {

	public function test_exitSuccess() {

		$exitCode = Process::exec(
			'ping -c 5 google.com',
			[
				'stdout' => $stdout = new StringOutputStream(),
				'stderr' => $stderr = new StringOutputStream(),
			]
		);

		$this->assertEquals(
			"PING google.com",
			substr($stdout, 0, 15)
		);
		$this->assertEquals(
			"",
			(string) $stderr
		);
		$this->assertEquals(0, $exitCode);

	}

	public function test_exitFailure() {

		$exitCode = Process::exec(
			'ping -c 5 i-dont-exist.example.com',
			[
				'stdout' => $stdout = new StringOutputStream(),
				'stderr' => $stderr = new StringOutputStream(),
			]
		);

		$this->assertEquals(
			"",
			(string) $stdout
		);
		$this->assertEquals(
			"ping: cannot resolve i-dont-exist.example.com: Unknown host\n",
			(string) $stderr
		);
		$this->assertNotEquals(0, $exitCode);

	}

	//TODO: test when the stdout takes a little while to generate and the error stream is empty

}
 