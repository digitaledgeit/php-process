<?php

namespace deit\process;

use deit\stream\StreamUtil;
use deit\stream\InputStream;
use deit\stream\OutputStream;
use deit\stream\PhpInputStream;
use deit\stream\PhpOutputStream;
use deit\platform\System as OS;

/**
 * Process
 * @author  James Newell <james@digitaledgeit.com.au>
 */
class Process {

	const EXIT_SUCCESS  = 0;
	const EXIT_FAILURE  = 1;

	const PIPE_STDIN    = 0;
	const PIPE_STDOUT   = 1;
	const PIPE_STDERR   = 2;

	/**
	 * Executes the specified command and returns a process object without waiting for the command to finish
	 * @param   string              $command    The command
	 * @param   mixed[string]       $options    The options
	 * @return  Process
	 */
	public static function spawn($command, array $options = array()) {
		return new self($command, $options);
	}

	/**
	 * Executes the specified command, waits for it to finish and returns the exit code
	 *  - Redirects stdout and stderr to the specified streams
	 * @param   string              $command    The command
	 * @param   mixed[string]       $options    The options
	 * @return  int                             The exit code
	 * @throws
	 */
	public static function exec($command, array $options = array()) {
		$spawn = self::spawn($command, $options);

		if (isset($options['stdin'])) {
			if (!$options['stdin'] instanceof InputStream) {
				throw new ProcessException("Invalid stream provided for redirecting process input.");
			}
			StreamUtil::copy($options['stdin'], $spawn->getInputStream());
		}

		if (isset($options['stdout'])) {
			if (!$options['stdout'] instanceof OutputStream) {
				throw new ProcessException("Invalid stream provided for redirecting process output.");
			}
			StreamUtil::copy($spawn->getOutputStream(), $options['stdout']);
		}

		if (isset($options['stderror'])) {
			if (!$options['stderror'] instanceof OutputStream) {
				throw new ProcessException("Invalid stream provided for redirecting process errors.");
			}
			StreamUtil::copy($spawn->getErrorStream(), $options['stderror']);
		}

		$spawn->wait(); //todo: allow the user to specify a timeout option

		return $spawn->getExitCode();
	}

	/**
	 * The process resource
	 * @var     resource
	 */
	private $process = false;

	/**
	 * The process pipes
	 * @var    resource[int]
	 */
	private $pipes = [];

	/**
	 * The process streams
	 * @var    InputStream[int]|OutputStream[int]
	 */
	private $streams = [];

	/**
	 * The process exit code
	 * @var    int
	 */
	private $exitCode = null;

	/**
	 * Constructs the process
	 * @param   string              $command    The command
	 * @param   mixed[string]       $options    The options
	 * @throws
	 */
	private function __construct($command, array $options = array()) {

		/**
		 * - string         $cwd - The initial working directory (must be an absolute path). Defaults to the working directory of the current process.
		 * - mixed[string]  $env - The environment variables. Defaults to the variables of the current process.
		 * - bool           $pty -
		 * -
		 */

		if (isset($options['cwd'])) {
			$cwd = (string) $options['cwd'];
		} else {
			$cwd = null;
		}

		if (isset($options['env'])) {
			$env = (array) $options['env'];
		} else {
			$env = null;
		}

		if (isset($options['pty'])) {
			$pty = (bool) $options['pty'];
		} else {
			$pty = false;
		}

		// --- setup process pipes ---

		if (isset($opt['pty']) && (bool) $opt['pty'] && !OS::isLinux()) {
			throw new ProcessException('PTY shells are not enabled on your OS.');
		}

		/**
		 * Reading from a pipe opened with proc_open hangs forever on Windows
		 * @see https://bugs.php.net/bug.php?id=51800
		 */
		if (OS::isWin()) {

			$spec = array(
				self::PIPE_STDIN  => array('pipe', 'r'),
				self::PIPE_STDOUT => tmpfile(),
				self::PIPE_STDERR => tmpfile()
			);

		} else {

			if ($pty) {

				$spec = array(
					self::PIPE_STDIN  => array('pty'),
					self::PIPE_STDOUT => array('pty'),
					self::PIPE_STDERR => array('pty')
				);

			} else {

				$spec = array(
					self::PIPE_STDIN  => array('pipe', 'r'),
					self::PIPE_STDOUT => array('pipe', 'w'),
					self::PIPE_STDERR => array('pipe', 'w')
				);

			}

		}

		// --- open the process ---

		if (($this->process = proc_open($command, $spec, $this->pipes, $cwd, $env)) === false) {
			throw new ProcessException('Unable to spawn process');
		}

		/**
		 * See windows comment above
		 */
		if (OS::isWin()) {
			$this->pipes[self::PIPE_STDOUT] = $spec[self::PIPE_STDOUT];
			$this->pipes[self::PIPE_STDERR] = $spec[self::PIPE_STDERR];
			fseek($this->pipes[self::PIPE_STDOUT], 0);
			fseek($this->pipes[self::PIPE_STDERR], 0);
		}

	}

	/**
	 * Gets whether the process is running
	 * @return    bool
	 */
	public function isRunning() {

		//check process is is running
		if ($this->process === false) {
			return false;
		}

		//check status returned
		if (($status = $this->status()) === false) {
			return false;
		}

		return $status['running'];
	}

	/**
	 * Gets the process ID
	 * @return    int
	 */
	public function getId() {

		//check status returned
		if (($status = $this->status()) === false) {
			return false;
		}

		return $status['pid'];
	}

	/**
	 * Gets the process STDIN stream
	 * @return    OutputStream
	 */
	public function getInputStream() {
		$this->assertIsOpen();
		if (!isset($this->streams[self::PIPE_STDIN])) {
			$this->streams[self::PIPE_STDIN] = new PhpOutputStream($this->pipes[self::PIPE_STDIN], false);
		}
		return $this->streams[self::PIPE_STDIN];
	}

	/**
	 * Gets the process STDOUT stream
	 * @return    InputStream
	 */
	public function getOutputStream() {
		$this->assertIsOpen();
		if (!isset($this->streams[self::PIPE_STDOUT])) {
			$this->streams[self::PIPE_STDOUT] = new PhpInputStream($this->pipes[self::PIPE_STDOUT], false);
		}
		return $this->streams[self::PIPE_STDOUT];
	}

	/**
	 * Gets the process STDERR stream
	 * @return    InputStream
	 */
	public function getErrorStream() {
		$this->assertIsOpen();
		if (!isset($this->streams[self::PIPE_STDERR])) {
			$this->streams[self::PIPE_STDERR] = new PhpInputStream($this->pipes[self::PIPE_STDERR], false);
		}
		return $this->streams[self::PIPE_STDERR];
	}

	/**
	 * Gets the process exit code
	 * @return  int
	 * @throws
	 */
	public function getExitCode() {
		$this->assertIsNotRunning();
		return $this->exitCode;
	}

	/**
	 * Sends the process a POSIX signal
	 * @param   int $signal
	 * @return  Process
	 */
	public function signal($signal) {
		return $this;
	}

	/**
	 * Asks the process to exit and returns immediately
	 * @return  Process
	 * @throws
	 */
	public function terminate() {

		if (!$this->isRunning()) {
			return $this;
		}

		if (!proc_terminate($this->process)) {
			throw new ProcessException("Unable to terminate process #{$this->getId()}");
		}

		return $this;
	}

	/**
	 * Forces the process to exit and returns immediately
	 * @return  Process
	 * @throws
	 */
	public function kill() {

		if (!$this->isRunning()) {
			return $this;
		}

		if (!proc_terminate($this->process, 9)) { //TODO: only works on *nix
			throw new ProcessException("Unable to kill process #{$this->getId()}");
		}

		return $this;
	}

	/**
	 * Waits for the process to exit
	 * @param   float       $timeout        The amount of seconds to wait
	 * @return  Process
	 */
	public function wait($timeout = null) {

		//check the process is running
		if (!$this->isRunning()) {
			$this->destroy();
			return $this;
		}

		if ($timeout === null) {
			$this->destroy();
		} else {

			$startTime = microtime(true);
			$period    = (float)$timeout < 1 ? (float)$timeout * 1000000 : 0.1 * 1000000;

			while (microtime(true) - $startTime < $timeout) {

				usleep($period);

				if (!$this->isRunning()) {
					$this->destroy();
					break;
				}

			}

		}

		return $this;
	}

	/**
	 * Gets the process status
	 * @see https://github.com/symfony/symfony/issues/5759
	 * @see https://bugs.php.net/bug.php?id=39992
	 * @return  mixed[string]|false
	 */
	private function status() {

		//check process is open
		if ($this->process !== false) {

			//gets the process status
			if (($status = proc_get_status($this->process)) === false) {
				return false;
			}

			//check for exit code
			if ($status['running'] === false && $status['exitcode'] != -1) {
				$this->exitCode = $status['exitcode'];
			}

			return $status;

		}

		return false;
	}

	/**
	 * Waits for the process to close and then destroys the process resource
	 * @throws
	 */
	private function destroy() {

		// --- close the pipes ---

		foreach ($this->streams as $stream) {
			if (!$stream->isClosed()) {
				$stream->close();
			}
		}

		foreach ($this->pipes as $pipe) {
			if (is_resource($pipe)) {
				fclose($pipe);
			}
		}

		// --- close the process ---

		$isRunning = $this->isRunning();
		$exitCode  = proc_close($this->process);

		if ($isRunning && $exitCode === -1) {
			throw new ProcessException("Unable to terminate process #{$this->getId()}");
		}

		// --- check the exit code ---

		if (is_null($this->exitCode) && $exitCode !== -1) {
			$this->exitCode = $exitCode;
		}

		// --- reset process class ---

		$this->process = false;
		$this->pipes   = null;
		$this->streams = null;

	}

	/**
	 * Asserts that the process is open
	 */
	private function assertIsOpen() {
		if ($this->process === false) {
			throw new ProcessException("Process #{$this->getId()} has exited.");
		}
	}

	/**
	 * Asserts that the process is not open
	 */
	private function assertIsNotOpen() {
		if ($this->process !== false) {
			throw new ProcessException("Process #{$this->getId()} is still open.");
		}
	}

	/**
	 * Asserts that the process is running
	 */
	private function assertIsRunning() {
		if (!$this->isRunning()) {
			throw new ProcessException("Process #{$this->getId()} is no longer running.");
		}
	}

	/**
	 * Asserts that the process is not running
	 */
	private function assertIsNotRunning() {
		if ($this->isRunning()) {
			throw new ProcessException("Process #{$this->getId()} is still running.");
		}
	}

	/**
	 * Destructs the process
	 */
	public function __destruct() {
		if ($this->process !== false) {
			if ($this->isRunning()) {
				$this->kill();
			}
			$this->destroy();
		}
	}

}