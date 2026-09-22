<?php
// This file is part of Moodle - http://moodle.org/
//
// This CLI tool is licensed the same as mod_hvp: GNU GPL v3 or later.
/**
 * report_writer.php
 *
 * Built for: Moodle 4.5.12 (2024100712), Ubuntu 24.04 LTS, PHP 8.3.32, MySQL 8.0.46, mod_hvp 1.28.4+.
 * 
 * NO GUARANTEES - `Experimental`
 * 
 * ALWAYS, ALWAYS, ALWAYS - Make a backup/snapshot of your entire database first to err on the side of caution.
 * 
 * Streams a per-record CSV row as each content item is processed (so a very
 * long run still leaves a useful partial report if interrupted), and can
 * print a final summary table to the console / log.
 *
 * CSV columns are intentionally attach-to-a-change-request friendly: a
 * reviewer should be able to open this in Excel/Sheets and immediately see
 * what happened to every single piece of content, without cross-referencing
 * anything else.
 *
 * @package    mod_hvp
 * @subpackage cli
 * @copyright  2026 Dave S. AS <corestaples@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_hvp\cli;

defined('MOODLE_INTERNAL') || defined('CLI_SCRIPT') || die('Direct access not permitted');

class report_writer {

    /** @var resource */
    protected $fh;

    /** @var string */
    protected $path;

    public function __construct(string $csvpath) {
        $this->path = $csvpath;
        $dir = dirname($csvpath);
        if (!is_dir($dir)) {
            mkdir($dir, 0770, true);
        }
        $isnew = !file_exists($csvpath);
        $this->fh = fopen($csvpath, 'a');
        if ($isnew) {
            fputcsv($this->fh, [
                'hvp_id', 'course_id', 'name', 'root_library', 'status', 'changed',
                'warnings', 'error', 'timestamp',
            ]);
        }
    }

    /**
     * @param array $row see keys used below; missing keys default sensibly
     */
    public function write_row(array $row): void {
        fputcsv($this->fh, [
            $row['id'] ?? '',
            $row['course'] ?? '',
            $row['name'] ?? '',
            $row['root_library'] ?? '',
            $row['status'] ?? '', // written|skipped|error|would-write (dry-run)
            isset($row['changed']) ? ($row['changed'] ? 'yes' : 'no') : '',
            isset($row['warnings']) ? implode(' | ', (array) $row['warnings']) : '',
            $row['error'] ?? '',
            date('c'),
        ]);
        fflush($this->fh);
    }

    public function get_path(): string {
        return $this->path;
    }

    public function close(): void {
        if (is_resource($this->fh)) {
            fclose($this->fh);
        }
    }

    public function __destruct() {
        $this->close();
    }
}
