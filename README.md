# mod_hvp fix_subcontent CLI

A headless, resumable, checkpointed command-line equivalent of
`/mod/hvp/library_list.php?fix_subcontent=1`, for repairing the mod_hvp
1.28.2 subcontent version-lock defect
([h5p/moodle-mod_hvp#632](https://github.com/h5p/moodle-mod_hvp/issues/632),
[#633](https://github.com/h5p/moodle-mod_hvp/pull/633),
[#642](https://github.com/h5p/moodle-mod_hvp/pull/642),
[#660](https://github.com/h5p/moodle-mod_hvp/issues/660)).

Built for: Moodle 4.5.12 (2024100712), Ubuntu 24.04 LTS, PHP 8.3.32, MySQL
8.0.46, mod_hvp 1.28.4+.

**NO GUARANTEES - `Experimental`**

`@package   mod_hvp`

`@subpackage cli`

@copyright  2026 [Contact Dave S.](mailto:corestaples@gmail.com)

`@license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later`

**Start here: [`docs/FIX_SUBCONTENT_CLI_MANUAL.md`](docs/FIX_SUBCONTENT_CLI_MANUAL.md)**
— full architecture, install steps, prerequisites, command reference,
dry-run/live-run walkthroughs, checkpoint/resume, backup/disaster-recovery,
per-content-type examples, and troubleshooting.

## Layout

```
mod/hvp/cli/
  fix_subcontent.php          Main Moodle CLI entry point
  classes/
    target_resolver.php       --all/--category/--course/--id/--cmid/--library -> content ids
    checkpoint_manager.php    Resumable done/skipped/errored tracking
    backup_manager.php        Pre-mutation .json.bak snapshots + restore
    report_writer.php         Streaming CSV report
    node_bridge.php           PHP <-> Node NDJSON protocol bridge
  runner/
    upgrade-runner.js         Node subprocess: runs the actual upgrade hooks
    logic-content-upgrade.js  H5P's own recursive upgrade engine (unmodified)
    selftest.js               Standalone test - no Moodle/DB needed
    package.json

docs/
  FIX_SUBCONTENT_CLI_MANUAL.md   The full manual (read this first)

examples/
  example-commands.sh            Copy-pasteable command reference
```

## 60-second start

```bash
cd /path/to/moodle
cp -r /path/to/this/package/mod/hvp/cli mod/hvp/cli

node mod/hvp/cli/runner/selftest.js          # confirm Node side works
php mod/hvp/cli/fix_subcontent.php --list-libraries   # confirm PHP/Moodle side works

php mod/hvp/cli/fix_subcontent.php --course=42 --dry-run   # preview
php mod/hvp/cli/fix_subcontent.php --course=42 --apply     # apply
```

See the manual for everything else, including how to scope a run across an
entire site safely (`--library=...` one content type at a time), resume an
interrupted run (`--resume`), and roll back if needed (`--restore-run=...`).

## License

This tool is written for and ships alongside mod_hvp; use/distribute it
under the same license as mod_hvp itself (GNU GPL v3 or later).
`runner/logic-content-upgrade.js` is H5P's own upstream code, used
unmodified.
