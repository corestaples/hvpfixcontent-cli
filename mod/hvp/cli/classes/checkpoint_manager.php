<?php
// This file is part of Moodle - http://moodle.org/
//
// This CLI tool is licensed the same as mod_hvp: GNU GPL v3 or later.
/**
 * checkpoint_manager.php
 *
 * Built for: Moodle 4.5.12 (2024100712), Ubuntu 24.04 LTS, PHP 8.3.32, MySQL 8.0.46, mod_hvp 1.28.4+.
 * 
 * NO GUARANTEES - `Experimental`
 * 
 * ALWAYS, ALWAYS, ALWAYS - Make a backup/snapshot of your entire database first to err on the side of caution.
 * 
 * Progress tracking for the fix_subcontent CLI.
 *
 * Design notes (why this shape, not a "lastId" cursor):
 *   PM84's review of this feature request specifically called out that a
 *   single "last completed id" cursor is NOT sufficient once you consider
 *   resuming an interrupted run: content ids are not necessarily processed
 *   in strictly increasing order once filters like --course/--category/
 *   --library are combined, and a future parallel-worker version of this
 *   tool could finish ids out of order entirely. So instead of a cursor we
 *   keep an explicit SET of "done" ids (processed=ok, skipped=ok-but-not-
 *   written, or errored=needs-retry) written after every batch. Resuming
 *   simply subtracts "done" ids from the freshly resolved target list.
 *
 * The checkpoint file is named deterministically from a hash of the run's
 * *inputs* (target filters + mode + library filter), so re-running the same
 * logical command with --resume automatically finds the right file, while
 * a genuinely different command gets its own checkpoint and never silently
 * cross-contaminates progress from an unrelated run.
 *
 * File format: JSON, written atomically (write to temp file + rename) so a
 * crash mid-write can never leave a torn/corrupt checkpoint on disk.
 *
 * @package    mod_hvp
 * @subpackage cli
 * @copyright  2026 Dave S. AS <corestaples@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_hvp\cli;

defined('MOODLE_INTERNAL') || defined('CLI_SCRIPT') || die('Direct access not permitted');

class checkpoint_manager {

    /** @var string absolute path to the checkpoint JSON file */
    protected $path;

    /** @var array in-memory state, mirrors what's on disk */
    protected $state;

    /** @var resource|null open handle on the lock file while acquire_lock() holds it */
    protected $lockhandle = null;

    /**
     * @param string $dir directory to store checkpoint files in (created if missing)
     * @param string $signature stable string describing this run's target filters + mode,
     *                          used to derive the checkpoint filename when none is given
     * @param string|null $explicitpath if given, use this exact path instead of deriving one
     */
    public function __construct(string $dir, string $signature, ?string $explicitpath = null) {
        if ($explicitpath !== null) {
            $this->path = $explicitpath;
        }
        else {
            if (!is_dir($dir)) {
                mkdir($dir, 0770, true);
            }
            $hash = substr(sha1($signature), 0, 16);
            $this->path = rtrim($dir, '/') . "/checkpoint_{$hash}.json";
        }

        $this->state = $this->load();
    }

    public function get_path(): string {
        return $this->path;
    }

    /**
     * Acquire an exclusive, non-blocking lock tied to this checkpoint's signature,
     * so a second concurrent invocation of the SAME logical command (same target
     * filters + mode - e.g. someone re-running the same --all --library=... command
     * while the first is still going, or an overlapping cron double-fire) refuses to
     * start instead of racing the first run for the same records. This directly
     * addresses PM84's review comment that "concurrent editing and duplicate
     * execution need explicit safeguards."
     *
     * The lock is held for the lifetime of the PHP process (released automatically
     * on exit, including a crash, since flock()'d file handles are released by the
     * OS when the process dies) and is separate from the per-record concurrent-edit
     * check in fix_subcontent.php, which guards against a *user* editing content,
     * not against two copies of this *tool* running at once.
     *
     * @return bool true if the lock was acquired, false if another process already
     *              holds it (i.e. this exact command is already running)
     */
    public function acquire_lock(): bool {
        $lockpath = $this->path . '.lock';
        $this->lockhandle = fopen($lockpath, 'c');
        if ($this->lockhandle === false) {
            throw new \moodle_exception("Could not open lock file for writing: {$lockpath}");
        }
        return flock($this->lockhandle, LOCK_EX | LOCK_NB);
    }

    public function release_lock(): void {
        if (is_resource($this->lockhandle)) {
            flock($this->lockhandle, LOCK_UN);
            fclose($this->lockhandle);
            $this->lockhandle = null;
        }
    }

    public function __destruct() {
        // Belt-and-suspenders: flock()'d handles are released by the OS when the
        // process exits regardless, but close it explicitly if the caller forgot.
        $this->release_lock();
    }

    /**
     * @return bool true if a checkpoint file already existed on disk (i.e. this
     *              looks like a resume rather than a fresh run)
     */
    public function exists(): bool {
        return file_exists($this->path);
    }

    protected function load(): array {
        if (!file_exists($this->path)) {
            return $this->fresh_state();
        }
        $raw = file_get_contents($this->path);
        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || !isset($decoded['done'])) {
            // Corrupt/unrecognised checkpoint - never silently discard user data;
            // refuse to proceed rather than guessing.
            throw new \moodle_exception(
                'Checkpoint file exists but could not be parsed: ' . $this->path .
                ' - inspect it manually, or pass a different --checkpoint-file / delete it ' .
                'if you are sure it is safe to start fresh.'
            );
        }
        return $decoded;
    }

    protected function fresh_state(): array {
        return [
            'version' => 1,
            'created' => date('c'),
            'updated' => date('c'),
            'done' => [
                'written' => [],  // ids that were successfully upgraded and written (or would be, in dry-run)
                'skipped' => [],  // ids intentionally left alone (already correct, or unresolvable reference)
                'errored' => [],  // ids that failed and should be retried on next run
            ],
            'stats' => [
                'batches' => 0,
                'total_processed' => 0,
            ],
        ];
    }

    /**
     * @return int[] every id already accounted for (written OR skipped), i.e.
     *               safe to exclude from a resumed run's target list. Errored
     *               ids are deliberately NOT included here, so a --resume
     *               automatically retries anything that previously failed.
     */
    public function get_done_ids(): array {
        return array_map('intval', array_merge(
            $this->state['done']['written'],
            $this->state['done']['skipped']
        ));
    }

    public function record_written(int $id): void {
        $this->state['done']['written'][] = $id;
        $this->prune('errored', $id);
    }

    public function record_skipped(int $id): void {
        $this->state['done']['skipped'][] = $id;
        $this->prune('errored', $id);
    }

    public function record_errored(int $id): void {
        // Avoid duplicate accumulation across repeated retries within one run.
        if (!in_array($id, $this->state['done']['errored'], true)) {
            $this->state['done']['errored'][] = $id;
        }
    }

    protected function prune(string $bucket, int $id): void {
        $this->state['done'][$bucket] = array_values(array_diff($this->state['done'][$bucket], [$id]));
    }

    /**
     * Persist current state to disk atomically. Call this after every batch,
     * not after every single record, to keep I/O overhead sane on large runs.
     */
    public function flush(): void {
        $this->state['updated'] = date('c');
        $this->state['stats']['batches']++;
        $this->state['stats']['total_processed'] =
            count($this->state['done']['written']) +
            count($this->state['done']['skipped']) +
            count($this->state['done']['errored']);

        $tmp = $this->path . '.tmp' . getmypid();
        file_put_contents($tmp, json_encode($this->state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        rename($tmp, $this->path); // atomic on the same filesystem
    }

    public function summary(): array {
        return [
            'written' => count($this->state['done']['written']),
            'skipped' => count($this->state['done']['skipped']),
            'errored' => count($this->state['done']['errored']),
        ];
    }

    /**
     * Delete the checkpoint file. Call this ONLY after a fully successful,
     * non-dry-run completion with zero remaining errored ids - the whole
     * point of this file is to survive exactly the crash that would make you
     * want it gone, so deletion is opt-in and explicit.
     */
    public function delete(): void {
        if (file_exists($this->path)) {
            unlink($this->path);
        }
    }
}
