<?php
// This file is part of Moodle - http://moodle.org/
//
// This CLI tool is licensed the same as mod_hvp: GNU GPL v3 or later.
/**
 * backup_manager.php
 *
  * Built for: Moodle 4.5.12 (2024100712), Ubuntu 24.04 LTS, PHP 8.3.32, MySQL 8.0.46, mod_hvp 1.28.4+.
 * 
 * NO GUARANTEES - `Experimental`
 * 
 * ALWAYS, ALWAYS, ALWAYS - Make a backup/snapshot of your entire database first to err on the side of caution.
 * 
 * Writes one pre-mutation JSON snapshot per content record BEFORE it is
 * modified, and can restore from those snapshots. Snapshots are plain files
 * on disk (not a DB table) deliberately: they must survive even if the
 * database itself is what you need to recover from, and a flat file is
 * trivially copyable off-box before a live run.
 *
 * Layout:
 *   {backupdir}/{runid}/{hvpid}.json.bak
 *
 * Each .json.bak contains the exact fields needed to fully restore a row:
 *   id, json_content, main_library_id, filtered, timemodified
 * plus a bit of metadata about why/when the backup was taken.
 *
 * @package    mod_hvp
 * @subpackage cli
 * @copyright  2026 Dave S. AS <corestaples@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_hvp\cli;

defined('MOODLE_INTERNAL') || defined('CLI_SCRIPT') || die('Direct access not permitted');

class backup_manager {

    /** @var string */
    protected $rundir;

    /** @var string */
    protected $runid;

    /**
     * @param string $basedir e.g. $CFG->dataroot . '/hvp_fix_subcontent_backups'
     * @param string $runid a stable identifier for this run, e.g. 'run_20260920_101500'
     */
    public function __construct(string $basedir, string $runid) {
        $this->runid = $runid;
        $this->rundir = rtrim($basedir, '/') . '/' . $runid;
        if (!is_dir($this->rundir)) {
            mkdir($this->rundir, 0770, true);
        }
    }

    public function get_run_dir(): string {
        return $this->rundir;
    }

    /**
     * Snapshot one content row before it is touched. Idempotent: calling this
     * twice for the same id in the same run will NOT overwrite the first
     * (genuinely original) snapshot - this matters for --resume, where a
     * retried id must still back up the ORIGINAL pre-run state, not whatever
     * partial state a previous interrupted attempt left behind.
     *
     * @param \stdClass $record must have id, json_content, main_library_id, filtered, timemodified
     */
    public function backup(\stdClass $record): void {
        $path = $this->path_for($record->id);
        if (file_exists($path)) {
            return; // Already backed up this run - never clobber the original snapshot.
        }

        $payload = [
            'hvp_id' => (int) $record->id,
            'backed_up_at' => date('c'),
            'run_id' => $this->runid,
            'fields' => [
                'json_content' => $record->json_content,
                'main_library_id' => (int) $record->main_library_id,
                'filtered' => $record->filtered,
                'timemodified' => (int) $record->timemodified,
            ],
        ];

        $tmp = $path . '.tmp' . getmypid();
        file_put_contents($tmp, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        rename($tmp, $path);
    }

    public function has_backup(int $id): bool {
        return file_exists($this->path_for($id));
    }

    /**
     * @param int $id
     * @return array{fields: array} decoded backup payload
     */
    public function load(int $id): array {
        $path = $this->path_for($id);
        if (!file_exists($path)) {
            throw new \moodle_exception("No backup found for hvp id {$id} at {$path}");
        }
        return json_decode(file_get_contents($path), true);
    }

    protected function path_for(int $id): string {
        return $this->rundir . "/{$id}.json.bak";
    }

    /**
     * List every id that has a backup in this run directory - used by the
     * standalone --restore-from operation.
     *
     * @return int[]
     */
    public function list_backed_up_ids(): array {
        $ids = [];
        foreach (glob($this->rundir . '/*.json.bak') as $file) {
            $ids[] = (int) basename($file, '.json.bak');
        }
        sort($ids, SORT_NUMERIC);
        return $ids;
    }
}
