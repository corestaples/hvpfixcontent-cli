<?php
// This file is part of Moodle - http://moodle.org/
//
// This CLI tool is licensed the same as mod_hvp: GNU GPL v3 or later.

/**
 * fix_subcontent.php
 *
 * Headless, resumable, checkpointed command-line equivalent of
 *   /mod/hvp/library_list.php?fix_subcontent=1
 *
 * Repairs the mod_hvp 1.28.2 content-upgrade defect (h5p/moodle-mod_hvp
 * issues #632, #633, PR #642, feature request #660) where nested/subcontent
 * H5P libraries were left stranded at their old version after a content
 * upgrade, producing:
 *
 * Built for: Moodle 4.5.12 (2024100712), Ubuntu 24.04 LTS, PHP 8.3.32, MySQL 8.0.46, mod_hvp 1.28.4+.
 * 
 * NO GUARANTEES - `Experimental`
 * 
 * ALWAYS, ALWAYS, ALWAYS - Make a backup/snapshot of your entire database first to err on the side of caution.
 * 
 *   "The version of the H5P library H5P.Column used in this content is not
 *    valid. Content contains H5P.Column 1.18, but it should be H5P.Column
 *    1.22."
 *
 * ARCHITECTURE (see docs/FIX_SUBCONTENT_CLI_MANUAL.md for full detail):
 *
 *   1. This PHP process bootstraps Moodle, resolves which hvp.id values to
 *      touch (--all / --category / --course / --id / --cmid, optionally
 *      narrowed by --library), and reads every installed library's latest
 *      version + upgrades.js source straight out of hvp_libraries / Moodle's
 *      file API.
 *   2. It spawns ONE Node.js subprocess (cli/runner/upgrade-runner.js) and
 *      hands it that library/version/upgrade-script data once via an
 *      "init" message.
 *   3. For each content record, PHP decodes json_content, sends it to the
 *      Node subprocess as a "job", and gets back the recursively upgraded
 *      parameter tree (or a reason it was skipped/errored). Node does the
 *      actual JS upgrade-hook execution; PHP never runs untrusted JS itself.
 *   4. Before writing anything, PHP snapshots the original row to
 *      {backupdir}/{runid}/{id}.json.bak. Then it updates hvp.json_content,
 *      clears hvp.filtered (so Moodle's own H5PCore::filterParameters()
 *      cache/dependency rebuild kicks in), and optionally forces that
 *      rebuild immediately with --warm-cache.
 *   5. Progress is checkpointed to disk after every batch, so killing this
 *      process (Ctrl+C, OOM, server reboot) and re-running with --resume
 *      picks up where it left off instead of starting over.
 *   6. A CSV report row is written for every single content record touched,
 *      suitable for attaching to a change request.
 *
 * USAGE EXAMPLES: see docs/FIX_SUBCONTENT_CLI_MANUAL.md and
 * examples/example-commands.sh. Quick start:
 *
 *   # Preview only - makes NO changes, still spawns Node and reports what
 *   # WOULD happen:
 *   php mod/hvp/cli/fix_subcontent.php --course=42 --dry-run
 *
 *   # Actually apply the fix for one course:
 *   php mod/hvp/cli/fix_subcontent.php --course=42 --apply
 *
 *   # Whole site, one content type at a time (recommended for large sites -
 *   # see docs for why), resumable:
 *   php mod/hvp/cli/fix_subcontent.php --all --library=H5P.CoursePresentation --apply --resume
 *
 * @package    mod_hvp
 * @subpackage cli
 * @copyright  2026 Dave S. AS <corestaples@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->dirroot . '/mod/hvp/locallib.php');
require_once(__DIR__ . '/classes/target_resolver.php');
require_once(__DIR__ . '/classes/checkpoint_manager.php');
require_once(__DIR__ . '/classes/backup_manager.php');
require_once(__DIR__ . '/classes/report_writer.php');
require_once(__DIR__ . '/classes/node_bridge.php');

use mod_hvp\cli\target_resolver;
use mod_hvp\cli\checkpoint_manager;
use mod_hvp\cli\backup_manager;
use mod_hvp\cli\report_writer;
use mod_hvp\cli\node_bridge;

// ---------------------------------------------------------------------------
// 1. Argument parsing
// ---------------------------------------------------------------------------

[$options, $unrecognised] = cli_get_params(
    [
        'help'            => false,
        'all'             => false,
        'category'        => '',
        'course'          => '',
        'id'              => '',
        'cmid'            => '',
        'library'         => '',
        'libraryid'       => '',
        'apply'           => false,
        'dry-run'         => false,
        'resume'          => false,
        'batch-size'      => 200,
        'max-content'     => 0,     // 0 = unlimited
        'node-path'       => 'node',
        'node-timeout'    => 30,
        'backup-dir'      => '',
        'checkpoint-dir'  => '',
        'checkpoint-file' => '',
        'log-file'        => '',
        'csv'             => '',
        'warm-cache'      => true,
        'clear-checkpoint' => false,
        'list-libraries'  => false,
        'restore-run'     => '',
        'restore-id'      => '',
        'yes'             => false,
    ],
    [
        'h' => 'help',
    ]
);

if ($options['help'] || !empty($unrecognised)) {
    print_help();
    exit($unrecognised ? 1 : 0);
}

global $DB;

// ---------------------------------------------------------------------------
// 2. Standalone utility modes (don't touch content, exit immediately)
// ---------------------------------------------------------------------------

if ($options['list-libraries']) {
    list_libraries();
    exit(0);
}

if ($options['restore-run'] !== '') {
    run_restore($options);
    exit(0);
}

// ---------------------------------------------------------------------------
// 3. Validate target selection and mode
// ---------------------------------------------------------------------------

$filterkeys = ['all', 'category', 'course', 'id', 'cmid'];
$hasfilter = false;
foreach ($filterkeys as $k) {
    if (!empty($options[$k])) {
        $hasfilter = true;
    }
}
if (!$hasfilter) {
    cli_error(
        "No target selected. Pass exactly one of --all, --category=<id>, --course=<id>,\n" .
        "--id=<hvpid>, or --cmid=<cmid> (comma-separated lists are allowed for course/id/cmid).\n" .
        "Run with --help for the full option list and examples."
    );
}

if ($options['apply'] && $options['dry-run']) {
    cli_error('Pass either --apply or --dry-run, not both.');
}
if ($options['library'] !== '' && $options['libraryid'] !== '') {
    cli_error(
        "Pass either --library=<machinename> or --libraryid=<id>, not both.\n" .
        "--libraryid pins an exact installed version (hvp_libraries.id); --library matches " .
        "any installed version of that machine name. Use --list-libraries to look up a " .
        "specific library's id if you want --libraryid."
    );
}
if (!$options['apply'] && !$options['dry-run']) {
    // Safety default: dry-run unless the operator explicitly opts in to writing.
    $options['dry-run'] = true;
    cli_writeln('[info] Neither --apply nor --dry-run given - defaulting to --dry-run (no changes will be made).');
}
$isdryrun = (bool) $options['dry-run'];

// ---------------------------------------------------------------------------
// 4. Paths and defaults
// ---------------------------------------------------------------------------

$backupdir = $options['backup-dir'] !== '' ? $options['backup-dir'] : ($CFG->dataroot . '/hvp_fix_subcontent/backups');
$checkpointdir = $options['checkpoint-dir'] !== '' ? $options['checkpoint-dir'] : ($CFG->dataroot . '/hvp_fix_subcontent/checkpoints');
$logfile = $options['log-file'] !== '' ? $options['log-file'] : ($CFG->dataroot . '/hvp_fix_subcontent/logs/fix_subcontent_' . date('Ymd_His') . '.log');
$csvfile = $options['csv'] !== '' ? $options['csv'] : ($CFG->dataroot . '/hvp_fix_subcontent/reports/fix_subcontent_' . date('Ymd_His') . '.csv');
$runid = 'run_' . date('Ymd_His') . '_' . substr(md5(uniqid('', true)), 0, 6);

foreach ([$backupdir, $checkpointdir, dirname($logfile), dirname($csvfile)] as $dir) {
    if (!is_dir($dir)) {
        mkdir($dir, 0770, true);
    }
}

$logfh = fopen($logfile, 'a');
function logline(string $msg) {
    global $logfh;
    $line = '[' . date('c') . '] ' . $msg;
    cli_writeln($line);
    if (is_resource($logfh)) {
        fwrite($logfh, $line . "\n");
    }
}

logline("fix_subcontent CLI starting. run_id={$runid} mode=" . ($isdryrun ? 'DRY-RUN' : 'APPLY'));
logline('Options: ' . json_encode(array_filter($options, function ($v) {
    return $v !== '' && $v !== false;
})));

// ---------------------------------------------------------------------------
// 5. Resolve targets
// ---------------------------------------------------------------------------

$resolver = new target_resolver($DB);
$filteroptions = [
    'all' => (bool) $options['all'],
    'category' => split_csv_ints($options['category']),
    'course' => split_csv_ints($options['course']),
    'id' => split_csv_ints($options['id']),
    'cmid' => split_csv_ints($options['cmid']),
    'library' => $options['library'] !== '' ? $options['library'] : null,
    'libraryid' => split_csv_ints($options['libraryid']),
];

$targetids = $resolver->resolve($filteroptions);
logline('Resolved ' . count($targetids) . ' target content record(s).');

if (empty($targetids)) {
    logline('Nothing to do - no content matched the given filters. Exiting.');
    exit(0);
}

if ((int) $options['max-content'] > 0) {
    $targetids = array_slice($targetids, 0, (int) $options['max-content']);
    logline('--max-content applied: limiting to first ' . count($targetids) . ' id(s).');
}

// ---------------------------------------------------------------------------
// 6. Checkpoint / resume
// ---------------------------------------------------------------------------

$signature = json_encode([$filteroptions, $isdryrun ? 'dryrun' : 'apply']);
$checkpoint = new checkpoint_manager(
    $checkpointdir,
    $signature,
    $options['checkpoint-file'] !== '' ? $options['checkpoint-file'] : null
);

if ($options['clear-checkpoint']) {
    $checkpoint->delete();
    logline('Checkpoint cleared at operator request: ' . $checkpoint->get_path());
    $checkpoint = new checkpoint_manager(
        $checkpointdir, $signature,
        $options['checkpoint-file'] !== '' ? $options['checkpoint-file'] : null
    );
}

if ($checkpoint->exists() && !$options['resume'] && !$options['clear-checkpoint']) {
    cli_error(
        "A checkpoint already exists for this exact command at:\n  " . $checkpoint->get_path() . "\n" .
        "Pass --resume to continue it, or --clear-checkpoint to discard it and start fresh."
    );
}

// Duplicate-execution safeguard: refuse to proceed if another process is
// already running this exact command (same target filters + mode). Without
// this, two concurrent invocations - two operators, or an overlapping cron
// double-fire - would race each other: both would read the same records,
// both would upgrade them, and whichever writes last wins, silently
// discarding the other's backup/checkpoint bookkeeping for those ids.
if (!$checkpoint->acquire_lock()) {
    cli_error(
        "Another instance of this exact command (same target filters + mode) appears to " .
        "already be running - refusing to start a second one against the same records.\n" .
        "Lock file: " . $checkpoint->get_path() . ".lock\n" .
        "If you're certain no other instance is actually running (e.g. a previous run " .
        "crashed hard enough to leave the lock file behind), remove that .lock file manually " .
        "and try again."
    );
}
register_shutdown_function(function () use ($checkpoint) {
    $checkpoint->release_lock();
});

if ($options['resume']) {
    $before = count($targetids);
    $doneids = $checkpoint->get_done_ids();
    $targetids = array_values(array_diff($targetids, $doneids));
    logline("--resume: excluded " . ($before - count($targetids)) . " already-completed id(s) " .
        "from checkpoint " . $checkpoint->get_path() . '. ' . count($targetids) . ' remaining.');
    if (empty($targetids)) {
        logline('Nothing left to resume - all matching content already processed. Exiting.');
        exit(0);
    }
}

// ---------------------------------------------------------------------------
// 7. Confirm before a live, non-dry-run, non-resumed large operation
// ---------------------------------------------------------------------------

if (!$isdryrun && !$options['yes'] && count($targetids) > 50) {
    cli_writeln('');
    cli_writeln("You are about to APPLY changes to " . count($targetids) . " content record(s).");
    cli_writeln("A pre-mutation backup will be written to: {$backupdir}/{$runid}/");
    cli_writeln("Type 'yes' to continue, anything else to abort:");
    $confirm = trim(fgets(STDIN));
    if (strtolower($confirm) !== 'yes') {
        cli_writeln('Aborted by operator.');
        exit(1);
    }
}

// ---------------------------------------------------------------------------
// 8. Build the library/version/upgrade-script map for the Node runner
// ---------------------------------------------------------------------------

[$latestversions, $upgradescripts] = build_library_data();
logline('Loaded ' . count($latestversions) . ' installed library version(s), ' .
    count($upgradescripts) . ' with an upgrades.js.');

// ---------------------------------------------------------------------------
// 9. Spawn the Node runner
// ---------------------------------------------------------------------------

$runnerscript = __DIR__ . '/runner/upgrade-runner.js';
try {
    $bridge = new node_bridge($options['node-path'], $runnerscript, (int) $options['node-timeout']);
    $ready = $bridge->init($latestversions, $upgradescripts);
    logline('Node runner ready: ' . json_encode($ready));
}
catch (\Throwable $e) {
    cli_error(
        "Could not start the Node.js runner: " . $e->getMessage() . "\n\n" .
        "Prerequisites: Node.js >= 18 must be installed and reachable via --node-path " .
        "(default 'node'). Verify with:\n" .
        "  node --version\n" .
        "  node " . $runnerscript . "/../selftest.js\n" .
        "See docs/FIX_SUBCONTENT_CLI_MANUAL.md 'Prerequisites' section."
    );
}

// ---------------------------------------------------------------------------
// 10. Main processing loop
// ---------------------------------------------------------------------------

$backups = new backup_manager($backupdir, $runid);
$report = new report_writer($csvfile);

$moduleid = $DB->get_field('modules', 'id', ['name' => 'hvp']);
$core = \mod_hvp\framework::instance();

$stats = ['written' => 0, 'wouldwrite' => 0, 'skipped' => 0, 'errored' => 0];
$batchsize = max(1, (int) $options['batch-size']);
$batches = array_chunk($targetids, $batchsize);
$totalbatches = count($batches);

logline('Beginning processing: ' . count($targetids) . " id(s) in {$totalbatches} batch(es) of up to {$batchsize}.");

foreach ($batches as $batchindex => $batchids) {
    logline('--- Batch ' . ($batchindex + 1) . "/{$totalbatches} (" . count($batchids) . ' item(s)) ---');

    [$insql, $params] = $DB->get_in_or_equal($batchids, SQL_PARAMS_NAMED);
    $sql = "SELECT h.id, h.course, h.name, h.json_content, h.main_library_id, h.filtered, h.timemodified,
                   l.machine_name AS root_machine_name,
                   l.major_version AS root_major, l.minor_version AS root_minor
              FROM {hvp} h
              JOIN {hvp_libraries} l ON l.id = h.main_library_id
             WHERE h.id {$insql}
          ORDER BY h.id ASC";
    $records = $DB->get_records_sql($sql, $params);

    foreach ($batchids as $id) {
        if (!isset($records[$id])) {
            logline("[WARN] id {$id} not found (deleted since target resolution?) - skipping.");
            continue;
        }
        $record = $records[$id];
        $rootlib = $record->root_machine_name . ' ' . $record->root_major . '.' . $record->root_minor;

        $decoded = json_decode($record->json_content, true);
        if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
            logline("[ERROR] id {$id} ({$record->name}): json_content does not parse as JSON " .
                '(' . json_last_error_msg() . '). Skipped - needs manual investigation, ' .
                'possibly the pre-existing data-loss pattern from #632. NOT touched.');
            $checkpoint->record_errored($id);
            $report->write_row([
                'id' => $id, 'course' => $record->course, 'name' => $record->name,
                'root_library' => $rootlib, 'status' => 'error',
                'error' => 'json_content not valid JSON: ' . json_last_error_msg(),
            ]);
            $stats['errored']++;
            continue;
        }

        try {
            $result = $bridge->process($id, $decoded);
        }
        catch (\Throwable $e) {
            logline("[ERROR] id {$id} ({$record->name}): Node bridge failure: " . $e->getMessage());
            $checkpoint->record_errored($id);
            $report->write_row([
                'id' => $id, 'course' => $record->course, 'name' => $record->name,
                'root_library' => $rootlib, 'status' => 'error', 'error' => $e->getMessage(),
            ]);
            $stats['errored']++;
            continue;
        }

        if (empty($result['ok'])) {
            logline("[ERROR] id {$id} ({$record->name}): " . ($result['error'] ?? 'unknown runner error'));
            $checkpoint->record_errored($id);
            $report->write_row([
                'id' => $id, 'course' => $record->course, 'name' => $record->name,
                'root_library' => $rootlib, 'status' => 'error',
                'error' => $result['error'] ?? 'unknown',
            ]);
            $stats['errored']++;
            continue;
        }

        if (!empty($result['skipped'])) {
            logline("[SKIP]  id {$id} ({$record->name}): " . implode(' ', $result['warnings'] ?? []));
            $checkpoint->record_skipped($id);
            $report->write_row([
                'id' => $id, 'course' => $record->course, 'name' => $record->name,
                'root_library' => $rootlib, 'status' => 'skipped', 'changed' => false,
                'warnings' => $result['warnings'] ?? [],
            ]);
            $stats['skipped']++;
            continue;
        }

        if (empty($result['changed'])) {
            // Already correct - nothing to do, not an error.
            $checkpoint->record_skipped($id);
            $report->write_row([
                'id' => $id, 'course' => $record->course, 'name' => $record->name,
                'root_library' => $rootlib, 'status' => 'skipped', 'changed' => false,
            ]);
            $stats['skipped']++;
            continue;
        }

        // At this point: subcontent WAS upgraded and is ready to write.
        if ($isdryrun) {
            logline("[DRY]   id {$id} ({$record->name}, {$rootlib}): would upgrade subcontent " .
                (empty($result['warnings']) ? '' : '(' . implode(' ', $result['warnings']) . ')'));
            $checkpoint->record_written($id); // tracked under the dry-run-specific checkpoint signature
            $report->write_row([
                'id' => $id, 'course' => $record->course, 'name' => $record->name,
                'root_library' => $rootlib, 'status' => 'would-write', 'changed' => true,
                'warnings' => $result['warnings'] ?? [],
            ]);
            $stats['wouldwrite']++;
            continue;
        }

        try {
            // Concurrency safeguard (per PM84's review of this feature: "concurrent
            // editing and duplicate execution need explicit safeguards"). A long run
            // can take hours; a teacher may open the H5P editor and save a change to
            // this exact activity between when we SELECTed it for this batch and now.
            // Re-check timemodified immediately before writing - if it moved, someone
            // else's edit is newer than the parameter tree we upgraded, and writing our
            // result would silently clobber it. This is a check-then-act pattern (not a
            // DB-level lock), so it narrows the race window rather than eliminating it
            // outright - Moodle's $DB API has no portable "UPDATE ... WHERE timemodified
            // = :x, tell me if a row matched" primitive to close it completely - but it
            // converts what was an unconditional overwrite into a guarded one.
            $currenttimemodified = $DB->get_field('hvp', 'timemodified', ['id' => $id]);
            if ($currenttimemodified !== false && (int) $currenttimemodified !== (int) $record->timemodified) {
                logline("[SKIP]  id {$id} ({$record->name}): modified concurrently " .
                    "(timemodified changed from {$record->timemodified} to {$currenttimemodified} " .
                    "since this run selected it) - NOT overwriting. Will be picked up fresh on the next run.");
                $checkpoint->record_errored($id); // retryable: next run re-reads the NEW content and re-upgrades it
                $report->write_row([
                    'id' => $id, 'course' => $record->course, 'name' => $record->name,
                    'root_library' => $rootlib, 'status' => 'skipped-concurrent-edit', 'changed' => false,
                    'warnings' => ['Content was modified by someone/something else during this run; skipped to avoid overwriting their change.'],
                ]);
                $stats['errored']++;
                continue;
            }

            $backups->backup($record);

            $newjson = json_encode($result['params'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $DB->update_record('hvp', (object) [
                'id' => $id,
                'json_content' => $newjson,
                'filtered' => '',
                'timemodified' => time(),
            ]);

            if ($options['warm-cache']) {
                // Force Moodle's own H5PCore to rebuild the filtered cache and
                // hvp_contents_libraries dependency rows right now, so any
                // remaining problem surfaces during this maintenance run
                // instead of on a student's next page view.
                $freshcontent = $core->loadContent($id);
                $core->filterParameters($freshcontent);
            }

            logline("[FIXED] id {$id} ({$record->name}, {$rootlib})" .
                (empty($result['warnings']) ? '' : ' - ' . implode(' ', $result['warnings'])));
            $checkpoint->record_written($id);
            $report->write_row([
                'id' => $id, 'course' => $record->course, 'name' => $record->name,
                'root_library' => $rootlib, 'status' => 'written', 'changed' => true,
                'warnings' => $result['warnings'] ?? [],
            ]);
            $stats['written']++;
        }
        catch (\Throwable $e) {
            logline("[ERROR] id {$id} ({$record->name}): failed while writing: " . $e->getMessage());
            $checkpoint->record_errored($id);
            $report->write_row([
                'id' => $id, 'course' => $record->course, 'name' => $record->name,
                'root_library' => $rootlib, 'status' => 'error', 'error' => $e->getMessage(),
            ]);
            $stats['errored']++;
        }
    }

    $checkpoint->flush();
    logline('Checkpoint saved: ' . json_encode($checkpoint->summary()));
}

$bridge->shutdown();
$report->close();

// ---------------------------------------------------------------------------
// 11. Summary
// ---------------------------------------------------------------------------

logline('=== RUN COMPLETE ===');
logline('Mode: ' . ($isdryrun ? 'DRY-RUN (no changes made)' : 'APPLY'));
logline('Written:      ' . $stats['written']);
logline('Would-write:  ' . $stats['wouldwrite'] . ' (dry-run only)');
logline('Skipped:      ' . $stats['skipped'] . ' (already correct, or unresolvable - see warnings)');
logline('Errored:      ' . $stats['errored'] . ' (see log/CSV, safe to --resume and retry)');
logline('Log file:     ' . $logfile);
logline('CSV report:   ' . $csvfile);
if (!$isdryrun && $stats['written'] > 0) {
    logline('Backups:      ' . $backups->get_run_dir());
}
logline('Checkpoint:   ' . $checkpoint->get_path() .
    ($stats['errored'] === 0 ? ' (safe to delete with --clear-checkpoint)' : ' (KEEP - retry with --resume)'));

fclose($logfh);
exit($stats['errored'] > 0 ? 2 : 0);

// =============================================================================
// Helper functions
// =============================================================================

/**
 * Query hvp_libraries for the latest installed version of every machine name,
 * and pull upgrades.js source (if any) for each of those latest versions via
 * Moodle's standard file API.
 *
 * @return array [latestVersions, upgradeScripts]
 */
function build_library_data(): array {
    global $DB;

    $rows = $DB->get_records('hvp_libraries', null, '', 'id, machine_name, major_version, minor_version, patch_version');

    $latest = [];
    foreach ($rows as $row) {
        $name = $row->machine_name;
        $candidate = [
            'major' => (int) $row->major_version,
            'minor' => (int) $row->minor_version,
            'patch' => (int) $row->patch_version,
        ];
        if (!isset($latest[$name]) || version_is_newer($candidate, $latest[$name])) {
            $latest[$name] = $candidate;
        }
    }

    $context = \context_system::instance();
    $fs = get_file_storage();
    $scripts = [];

    foreach ($latest as $name => $version) {
        $folder = \H5PCore::libraryToFolderName([
            'machineName' => $name,
            'majorVersion' => $version['major'],
            'minorVersion' => $version['minor'],
        ]);
        $path = '/' . $folder . '/';
        $file = $fs->get_file($context->id, 'mod_hvp', 'libraries', 0, $path, 'upgrades.js');
        if ($file) {
            $scripts[$name] = $file->get_content();
        }
    }

    return [$latest, $scripts];
}

function version_is_newer(array $a, array $b): bool {
    if ($a['major'] !== $b['major']) {
        return $a['major'] > $b['major'];
    }
    if ($a['minor'] !== $b['minor']) {
        return $a['minor'] > $b['minor'];
    }
    return $a['patch'] > $b['patch'];
}

/**
 * @param string $csv comma-separated ints, may be empty
 * @return int[]
 */
function split_csv_ints(string $csv): array {
    $csv = trim($csv);
    if ($csv === '') {
        return [];
    }
    // Note: deliberately NOT using array_filter() here. array_filter()'s default
    // callback removes every falsy value, including integer 0 - and while no
    // Moodle id in this tool's own use (course/category/cmid/hvp id) is ever
    // legitimately 0, silently swallowing a value the operator typed is exactly
    // the kind of surprising behaviour this tool should never have. We only
    // drop genuinely empty segments (e.g. the blank between "5,,10"'s commas).
    $result = [];
    foreach (explode(',', $csv) as $v) {
        $v = trim($v);
        if ($v !== '') {
            $result[] = (int) $v;
        }
    }
    return $result;
}

/**
 * --list-libraries: quick read-only audit, no Node/DB writes. Shows every
 * installed library, its latest version, whether it has an upgrades.js, and
 * how many content records currently reference an OLDER version of it as
 * their root library (mirrors the audit script pattern from GitHub issue
 * #632's comments, but read via Moodle's own $DB API).
 */
function list_libraries(): void {
    global $DB;

    [$latest, $scripts] = build_library_data();

    cli_writeln(str_pad('MACHINE NAME', 40) . str_pad('LATEST', 10) . str_pad('UPGRADES.JS', 14) . 'STALE ROOT REFS');
    cli_writeln(str_repeat('-', 80));

    ksort($latest);
    foreach ($latest as $name => $version) {
        $verstr = "{$version['major']}.{$version['minor']}";
        $haslibraryscript = isset($scripts[$name]) ? 'yes' : 'no';

        $stalecount = (int) $DB->get_field_sql(
            "SELECT COUNT(h.id)
               FROM {hvp} h
               JOIN {hvp_libraries} l ON l.id = h.main_library_id
              WHERE l.machine_name = :name
                AND (l.major_version < :major
                     OR (l.major_version = :major2 AND l.minor_version < :minor))",
            [
                'name' => $name,
                'major' => $version['major'], 'major2' => $version['major'],
                'minor' => $version['minor'],
            ]
        );

        cli_writeln(str_pad($name, 40) . str_pad($verstr, 10) . str_pad($haslibraryscript, 14) . $stalecount);
    }

    cli_writeln('');
    cli_writeln('Note: "STALE ROOT REFS" only counts content whose ROOT library (main_library_id)');
    cli_writeln('is behind the latest installed version. It does NOT detect stale SUBCONTENT');
    cli_writeln('references (the actual fix_subcontent bug) - those live inside json_content and');
    cli_writeln('can only be found by decoding and walking each record, which is what a real');
    cli_writeln('(non---list-libraries) run of this tool does.');
}

/**
 * --restore-run=<runid>: restore every backed-up record from a previous run's
 * backup directory back to its pre-mutation state. Optionally narrowed with
 * --restore-id=<comma-separated hvp ids>.
 */
function run_restore(array $options): void {
    global $DB, $CFG;

    $backupdir = $options['backup-dir'] !== '' ? $options['backup-dir'] : ($CFG->dataroot . '/hvp_fix_subcontent/backups');
    $runid = $options['restore-run'];
    $manager = new backup_manager($backupdir, $runid);

    $ids = $options['restore-id'] !== '' ? split_csv_ints($options['restore-id']) : $manager->list_backed_up_ids();

    if (empty($ids)) {
        cli_error("No backups found for run '{$runid}' under {$backupdir}. Check the run id and --backup-dir.");
    }

    cli_writeln("About to RESTORE " . count($ids) . " content record(s) from backup run '{$runid}'.");
    cli_writeln("This will overwrite the CURRENT json_content/main_library_id/filtered with the");
    cli_writeln("pre-mutation snapshot taken before that run modified them.");
    if (!$options['yes']) {
        cli_writeln("Type 'yes' to continue, anything else to abort:");
        $confirm = trim(fgets(STDIN));
        if (strtolower($confirm) !== 'yes') {
            cli_writeln('Aborted by operator.');
            return;
        }
    }

    $restored = 0;
    foreach ($ids as $id) {
        if (!$manager->has_backup($id)) {
            cli_writeln("[SKIP] id {$id}: no backup found in this run.");
            continue;
        }
        $backup = $manager->load($id);
        $fields = $backup['fields'];

        $DB->update_record('hvp', (object) [
            'id' => $id,
            'json_content' => $fields['json_content'],
            'main_library_id' => $fields['main_library_id'],
            'filtered' => $fields['filtered'],
            'timemodified' => time(),
        ]);
        cli_writeln("[RESTORED] id {$id} (backed up " . $backup['backed_up_at'] . ')');
        $restored++;
    }

    cli_writeln("Done. Restored {$restored}/" . count($ids) . ' record(s).');
    cli_writeln('Remember to purge caches afterwards: php admin/cli/purge_caches.php');
}

function print_help(): void {
    echo <<<EOT
fix_subcontent.php - CLI/cron-capable repair tool for the mod_hvp 1.28.2
subcontent version-lock defect (h5p/moodle-mod_hvp #632, #633, #642, #660).

USAGE:
  php mod/hvp/cli/fix_subcontent.php <target> [<mode>] [options]

TARGET (exactly one required, unless --list-libraries / --restore-run):
  --all                        Every H5P activity on the site.
  --category=<id>[,<id>...]    All courses in these categories (incl. subcategories).
  --course=<id>[,<id>...]      These specific courses.
  --id=<hvpid>[,<hvpid>...]    Specific hvp activity record id(s) (the 'hvp' table PK).
  --cmid=<cmid>[,<cmid>...]    Specific course_modules id(s) (the id you see in the
                                Moodle activity URL, e.g. .../mod/hvp/view.php?id=CMID).

  --library=<machinename>      Narrow any of the above to content whose ROOT
                                content type matches, e.g. H5P.InteractiveBook.
                                Matches ANY installed version of that machine name.
                                (Combine with any target above. Mutually exclusive
                                with --libraryid.)
  --libraryid=<id>[,<id>...]   Narrow any of the above to content whose ROOT
                                content type is exactly this hvp_libraries.id row
                                (i.e. one specific machine name AND version, not
                                just any version of that machine name). Safer than
                                --library when you want to target one precise
                                version - e.g. if two versions of the same content
                                type are installed side by side, --libraryid lets
                                you pick exactly one. Find the id via
                                --list-libraries or a direct query against
                                hvp_libraries. (Combine with any target above.
                                Mutually exclusive with --library.)

MODE (default: --dry-run):
  --dry-run                    Report what would change; makes NO database writes.
  --apply                      Actually write the fix to the database.

RESUME / CHECKPOINTING:
  --resume                     Skip ids already completed by a previous run of
                                this exact command (matched by a hash of your
                                target + mode options).
  --clear-checkpoint            Discard any existing checkpoint for this command
                                and start fresh.
  --checkpoint-dir=<path>       Default: moodledata/hvp_fix_subcontent/checkpoints
  --checkpoint-file=<path>      Use an exact checkpoint file path instead of the
                                auto-derived one.

BACKUP / RECOVERY:
  --backup-dir=<path>           Default: moodledata/hvp_fix_subcontent/backups
  --restore-run=<runid>         Restore every record backed up during a previous
                                --apply run (see the "Backups:" line it printed).
  --restore-id=<id>[,<id>...]   Narrow --restore-run to specific hvp ids.

REPORTING:
  --log-file=<path>             Default: moodledata/hvp_fix_subcontent/logs/...
  --csv=<path>                  Default: moodledata/hvp_fix_subcontent/reports/...
  --list-libraries               Read-only audit: list installed libraries, whether
                                they have an upgrades.js, and how many content
                                records have a stale ROOT library reference.
                                Makes no changes; ignores target/mode options.

PERFORMANCE / SAFETY:
  --batch-size=<n>              Content records per DB batch (default 200).
  --max-content=<n>             Stop after N content records (0 = unlimited; useful
                                for a small pilot run before going site-wide).
  --node-path=<path>            Path to the node binary (default: "node").
  --node-timeout=<seconds>      Per-item timeout waiting on the Node runner (default 30).
  --warm-cache=1|0               Immediately rebuild Moodle's filtered-params cache
                                 and library-dependency rows after each fix (default
                                 1/on). Pass --warm-cache=0 to skip this and defer
                                 that rebuild to each activity's next page view -
                                 faster for very large runs, but problems surface
                                 for a real visitor instead of during maintenance.
  --yes                         Don't ask for interactive confirmation before a
                                large --apply run or a --restore-run.

  -h, --help                    This help.

EXAMPLES:
  # Pilot: dry-run one course first
  php mod/hvp/cli/fix_subcontent.php --course=42 --dry-run

  # Apply to that course
  php mod/hvp/cli/fix_subcontent.php --course=42 --apply

  # Whole-site Course Presentation repair (12k+ instances), resumable, in the
  # background via nohup/cron:
  php mod/hvp/cli/fix_subcontent.php --all --library=H5P.CoursePresentation \\
      --apply --resume --batch-size=500

  # Interactive Book / Branching Scenario / Column / Question Set / Interactive
  # Video - same pattern, one content type at a time:
  php mod/hvp/cli/fix_subcontent.php --all --library=H5P.InteractiveBook --apply --resume
  php mod/hvp/cli/fix_subcontent.php --all --library=H5P.BranchingScenario --apply --resume
  php mod/hvp/cli/fix_subcontent.php --all --library=H5P.QuestionSet --apply --resume
  php mod/hvp/cli/fix_subcontent.php --all --library=H5P.InteractiveVideo --apply --resume

  # Audit only, no changes:
  php mod/hvp/cli/fix_subcontent.php --list-libraries

  # Target one EXACT installed version precisely (safer than --library when
  # multiple versions of the same content type are installed side by side -
  # find the id from the --list-libraries output above or hvp_libraries directly):
  php mod/hvp/cli/fix_subcontent.php --all --libraryid=42 --apply --resume

  # Something went wrong - roll a run back:
  php mod/hvp/cli/fix_subcontent.php --restore-run=run_20260920_101500_ab12cd

See docs/FIX_SUBCONTENT_CLI_MANUAL.md for the full operations manual.

EOT;
}
