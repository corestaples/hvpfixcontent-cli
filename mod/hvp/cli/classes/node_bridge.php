<?php
// This file is part of Moodle - http://moodle.org/
//
// This CLI tool is licensed the same as mod_hvp: GNU GPL v3 or later.
/**
 * node_bridge.php
 *
 * Built for: Moodle 4.5.12 (2024100712), Ubuntu 24.04 LTS, PHP 8.3.32, MySQL 8.0.46, mod_hvp 1.28.4+.
 * 
 * NO GUARANTEES - `Experimental`
 * 
 * ALWAYS, ALWAYS, ALWAYS - Make a backup/snapshot of your entire database first to err on the side of caution.
 * 
 * Spawns mod/hvp/cli/runner/upgrade-runner.js ONCE via proc_open(), sends it
 * the "init" payload (every installed library's latest version + any
 * upgrades.js source found for it), and then exposes a simple
 * process(id, params): array method that the main CLI loop calls once per
 * content record.
 *
 * Why a long-lived subprocess instead of spawning node per content item:
 * the H5P plugin's own issue #660 documents a Course Presentation library
 * with 12,229 content instances. At even 30ms of process-spawn overhead
 * per item that is over 6 minutes of pure fork/exec cost before any real
 * work happens, and this scales badly across the "Numbers" section of #660
 * (accordion, audio recorder, branching scenario, ...). One persistent
 * process amortises Node startup + upgrades.js compilation to effectively
 * zero after the first init.
 *
 * All communication is newline-delimited JSON (NDJSON), matching
 * upgrade-runner.js's protocol. This class only knows the wire format; it
 * has zero knowledge of Moodle's schema, keeping it independently testable
 * and (per PM84's architecture proposal) reusable outside mod_hvp if H5P
 * core ever wants to adopt the same runner for another integration.
 *
 * @package    mod_hvp
 * @subpackage cli
 * @copyright  2026 Dave S. AS <corestaples@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_hvp\cli;

defined('MOODLE_INTERNAL') || defined('CLI_SCRIPT') || die('Direct access not permitted');

class node_bridge {

    /** @var resource */
    protected $process;

    /** @var array resource[] indexed by pipe number */
    protected $pipes;

    /** @var bool */
    protected $initialized = false;

    /** @var int seconds to wait for a response to any single message before treating it as fatal */
    protected $timeout;

    /**
     * @param string $nodebinary path to the node executable, e.g. "node" or "/usr/bin/node"
     * @param string $runnerscript absolute path to upgrade-runner.js
     * @param int $timeout per-message read timeout in seconds
     */
    public function __construct(string $nodebinary, string $runnerscript, int $timeout = 30) {
        if (!file_exists($runnerscript)) {
            throw new \moodle_exception("Runner script not found: {$runnerscript}");
        }

        $this->timeout = $timeout;

        $descriptors = [
            0 => ['pipe', 'r'], // child stdin
            1 => ['pipe', 'w'], // child stdout
            2 => ['pipe', 'w'], // child stderr
        ];

        $cmd = escapeshellarg($nodebinary) . ' ' . escapeshellarg($runnerscript);
        $this->process = proc_open($cmd, $descriptors, $this->pipes, dirname($runnerscript));

        if (!is_resource($this->process)) {
            throw new \moodle_exception(
                "Failed to spawn Node runner. Checked binary: {$nodebinary}. " .
                "Run 'which node' / '{$nodebinary} --version' manually to diagnose, " .
                "or pass --node-path=/full/path/to/node."
            );
        }

        // Non-blocking reads on stdout/stderr let us implement a real timeout
        // instead of hanging forever if the runner wedges.
        stream_set_blocking($this->pipes[1], false);
        stream_set_blocking($this->pipes[2], false);
    }

    /**
     * Send the init payload built from Moodle's hvp_libraries table.
     *
     * @param array $latestversions machineName => ['major'=>int,'minor'=>int,'patch'=>int]
     * @param array $upgradescripts machineName => raw upgrades.js source (only for
     *                              libraries that actually ship one)
     * @return array the runner's "ready" response, e.g. librariesKnown/upgradeScriptsLoaded
     */
    public function init(array $latestversions, array $upgradescripts): array {
        $libraries = [];
        foreach ($upgradescripts as $machinename => $source) {
            $libraries[$machinename] = ['upgradeScript' => $source];
        }

        $response = $this->send_and_wait([
            'type' => 'init',
            'latestVersions' => $latestversions,
            'libraries' => $libraries,
        ]);

        if (($response['type'] ?? null) !== 'ready') {
            throw new \moodle_exception('Node runner did not acknowledge init: ' . json_encode($response));
        }

        $this->initialized = true;
        return $response;
    }

    /**
     * Process one content record's parameter tree.
     *
     * @param int $id hvp.id, echoed back so callers can match responses to requests
     * @param mixed $decodedparams the ALREADY JSON-DECODED json_content (assoc array or object)
     * @return array runner result: ok, changed, skipped, params, warnings, error
     */
    public function process(int $id, $decodedparams): array {
        if (!$this->initialized) {
            throw new \moodle_exception('node_bridge::process() called before init()');
        }

        $response = $this->send_and_wait([
            'type' => 'job',
            'id' => $id,
            'params' => $decodedparams,
        ]);

        if (($response['type'] ?? null) !== 'result' || (int) ($response['id'] ?? -1) !== $id) {
            throw new \moodle_exception(
                "Protocol desync: expected result for id {$id}, got: " . json_encode($response)
            );
        }

        return $response;
    }

    public function is_alive(): bool {
        if (!is_resource($this->process)) {
            return false;
        }
        $status = proc_get_status($this->process);
        return $status['running'] ?? false;
    }

    /**
     * Cleanly shut down the runner subprocess.
     */
    public function shutdown(): void {
        if (!is_resource($this->process)) {
            return;
        }
        if ($this->is_alive()) {
            try {
                $this->send_and_wait(['type' => 'shutdown'], 5);
            }
            catch (\Throwable $e) {
                // Best effort - fall through to a hard terminate below.
            }
        }
        foreach ($this->pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        if ($this->is_alive()) {
            proc_terminate($this->process);
        }
        proc_close($this->process);
    }

    public function __destruct() {
        $this->shutdown();
    }

    // -------------------------------------------------------------------
    // Wire protocol internals
    // -------------------------------------------------------------------

    /** @var string leftover partial line across reads (stdout is non-blocking) */
    protected $readbuffer = '';

    /**
     * @param array $message will be JSON-encoded and newline-terminated
     * @param int|null $timeoutoverride
     * @return array decoded JSON response (first full line received)
     */
    protected function send_and_wait(array $message, ?int $timeoutoverride = null): array {
        $encoded = json_encode($message, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($encoded === false) {
            throw new \moodle_exception('Failed to JSON-encode message to Node runner: ' . json_last_error_msg());
        }

        $written = fwrite($this->pipes[0], $encoded . "\n");
        if ($written === false) {
            $this->drain_stderr_and_die('Failed to write to Node runner stdin (process likely died)');
        }

        return $this->read_one_line($timeoutoverride ?? $this->timeout);
    }

    protected function read_one_line(int $timeout): array {
        $deadline = microtime(true) + $timeout;

        while (true) {
            // Do we already have a full line buffered?
            $pos = strpos($this->readbuffer, "\n");
            if ($pos !== false) {
                $line = substr($this->readbuffer, 0, $pos);
                $this->readbuffer = substr($this->readbuffer, $pos + 1);
                $line = trim($line);
                if ($line === '') {
                    continue; // Skip stray blank lines, keep waiting.
                }
                $decoded = json_decode($line, true);
                if (!is_array($decoded)) {
                    $this->drain_stderr_and_die("Node runner produced non-JSON stdout line: {$line}");
                }
                return $decoded;
            }

            if (microtime(true) > $deadline) {
                $this->drain_stderr_and_die(
                    "Timed out after {$timeout}s waiting for Node runner response. " .
                    "This can mean a single content item's upgrade hook hung " .
                    "(rare, but possible with a buggy third-party upgrades.js)."
                );
            }

            if (!$this->is_alive()) {
                $this->drain_stderr_and_die('Node runner process exited unexpectedly');
            }

            $read = [$this->pipes[1]];
            $write = null;
            $except = null;
            $ready = @stream_select($read, $write, $except, 0, 200000); // 200ms poll

            if ($ready > 0) {
                $chunk = fread($this->pipes[1], 65536);
                if ($chunk !== false && $chunk !== '') {
                    $this->readbuffer .= $chunk;
                }
            }
        }
    }

    protected function drain_stderr_and_die(string $message): void {
        $stderr = '';
        if (is_resource($this->pipes[2])) {
            $chunk = fread($this->pipes[2], 65536);
            if ($chunk !== false) {
                $stderr .= $chunk;
            }
        }
        throw new \moodle_exception($message . ($stderr !== '' ? "\n--- runner stderr ---\n{$stderr}" : ''));
    }
}
