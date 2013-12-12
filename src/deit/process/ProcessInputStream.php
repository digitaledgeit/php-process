<?php

namespace deit\process;
use deit\stream\InputStream;
use deit\stream\PhpInputStream;
use deit\stream\RewindBeforeReadInputStream;

/**
 * Process input stream - Process input stream for Windows
 * @author James Newell <james@digitaledgeit.com.au>
 */
class ProcessInputStream implements InputStream {

	/**
	 * The stream
	 * @var     PhpInputStream
	 */
	private $stream;

	/**
	 * The process
	 * @var     Process
	 */
	private $process;

	/**
	 * Constructs the stream
	 * @param   PhpInputStream  $stream
	 * @param   Process         $process
	 */
	public function __construct(PhpInputStream $stream, Process $process) {
		$this->stream   = new RewindBeforeReadInputStream($stream);
		$this->process  = $process;
	}

	/**
	 * @inheritdoc
	 */
	public function end() {
		return $this->stream->end() && !$this->process->isRunning();
	}

	/**
	 * @inheritdoc
	 */
	public function read($count) {
		return $this->stream->read($count);
	}

}
 