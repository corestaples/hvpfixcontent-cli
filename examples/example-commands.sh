#!/bin/bash
# example-commands.sh
#
# Built for: Moodle 4.5.12 (2024100712), Ubuntu 24.04 LTS, PHP 8.3.32, MySQL 8.0.46, mod_hvp 1.28.4+.
#
# **NO GUARANTEES - `Experimental`**
#
#  ALWAYS, ALWAYS, ALWAYS - Make a backup/snapshot of your entire database first to err on the side of caution.
#
# Copy-pasteable reference for mod_hvp/cli/fix_subcontent.php.
# This file is documentation, NOT meant to be run top-to-bottom as-is -
# it applies real changes to real content. Read docs/FIX_SUBCONTENT_CLI_MANUAL.md
# first, then copy the individual command(s) you actually need.
#
# Run this script with --dry-run-all to execute every example in dry-run
# mode only, as a smoke test against your own site (still safe - dry-run
# never writes to the database):
#
#   bash examples/example-commands.sh --dry-run-all
#
# @package    mod_hvp
# @subpackage cli
# @copyright  2026 Dave S. AS <corestaples@gmail.com>
# @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
#
set -euo pipefail

MOODLE_ROOT="${MOODLE_ROOT:-/path/to/moodle}"
CLI="php ${MOODLE_ROOT}/mod/hvp/cli/fix_subcontent.php"

if [[ "${1:-}" == "--dry-run-all" ]]; then
  echo "=== Smoke-testing every example below in --dry-run mode ==="
  set -x
  $CLI --list-libraries
  $CLI --all --library=H5P.CoursePresentation --dry-run --max-content=10
  $CLI --all --library=H5P.InteractiveBook --dry-run --max-content=10
  $CLI --all --library=H5P.BranchingScenario --dry-run --max-content=10
  $CLI --all --library=H5P.Column --dry-run --max-content=10
  $CLI --all --library=H5P.QuestionSet --dry-run --max-content=10
  $CLI --all --library=H5P.InteractiveVideo --dry-run --max-content=10
  set +x
  echo "=== Smoke test complete - no changes were made (all --dry-run) ==="
  exit 0
fi

cat <<'EXAMPLES'
# ---------------------------------------------------------------------------
# 0. Prerequisites check
# ---------------------------------------------------------------------------
node mod/hvp/cli/runner/selftest.js
php mod/hvp/cli/fix_subcontent.php --list-libraries

# ---------------------------------------------------------------------------
# 1. Pilot: one course
# ---------------------------------------------------------------------------
php mod/hvp/cli/fix_subcontent.php --course=42 --dry-run
php mod/hvp/cli/fix_subcontent.php --course=42 --apply

# ---------------------------------------------------------------------------
# 2. One specific activity (by course_modules id from the view.php URL)
# ---------------------------------------------------------------------------
php mod/hvp/cli/fix_subcontent.php --cmid=8842 --dry-run
php mod/hvp/cli/fix_subcontent.php --cmid=8842 --apply --yes

# ---------------------------------------------------------------------------
# 3. A category tree (includes subcategories automatically)
# ---------------------------------------------------------------------------
php mod/hvp/cli/fix_subcontent.php --category=17 --dry-run
php mod/hvp/cli/fix_subcontent.php --category=17 --apply --resume

# ---------------------------------------------------------------------------
# 4. Site-wide, one content type at a time (recommended approach at scale -
#    see docs/FIX_SUBCONTENT_CLI_MANUAL.md \u00a712 for why)
# ---------------------------------------------------------------------------
php mod/hvp/cli/fix_subcontent.php --all --library=H5P.CoursePresentation --dry-run
php mod/hvp/cli/fix_subcontent.php --all --library=H5P.CoursePresentation --apply --resume --batch-size=500

php mod/hvp/cli/fix_subcontent.php --all --library=H5P.InteractiveBook --dry-run
php mod/hvp/cli/fix_subcontent.php --all --library=H5P.InteractiveBook --apply --resume

php mod/hvp/cli/fix_subcontent.php --all --library=H5P.BranchingScenario --dry-run
php mod/hvp/cli/fix_subcontent.php --all --library=H5P.BranchingScenario --apply --resume

php mod/hvp/cli/fix_subcontent.php --all --library=H5P.Column --dry-run
php mod/hvp/cli/fix_subcontent.php --all --library=H5P.Column --apply --resume

php mod/hvp/cli/fix_subcontent.php --all --library=H5P.QuestionSet --dry-run
php mod/hvp/cli/fix_subcontent.php --all --library=H5P.QuestionSet --apply --resume

php mod/hvp/cli/fix_subcontent.php --all --library=H5P.InteractiveVideo --dry-run
php mod/hvp/cli/fix_subcontent.php --all --library=H5P.InteractiveVideo --apply --resume

# ---------------------------------------------------------------------------
# 4b. --libraryid: pin one EXACT installed version instead of a machine name.
#     Safer than --library when two+ versions of the same content type are
#     installed side by side. Look up the id via --list-libraries or a
#     direct SELECT against hvp_libraries. Mutually exclusive with --library.
# ---------------------------------------------------------------------------
php mod/hvp/cli/fix_subcontent.php --all --libraryid=1234 --dry-run
php mod/hvp/cli/fix_subcontent.php --all --libraryid=1234 --apply --resume

# ---------------------------------------------------------------------------
# 5. Unattended / cron / background (recommended for large libraries)
# ---------------------------------------------------------------------------
nohup php mod/hvp/cli/fix_subcontent.php --all --library=H5P.CoursePresentation \
    --apply --resume --batch-size=500 --yes \
    > /var/log/hvp_fix_subcontent_coursepresentation.out 2>&1 &

# If interrupted, just re-run the exact same command (still with --resume).

# ---------------------------------------------------------------------------
# 6. Pilot a small sample before a big library-wide run
# ---------------------------------------------------------------------------
php mod/hvp/cli/fix_subcontent.php --all --library=H5P.QuestionSet --dry-run --max-content=25

# ---------------------------------------------------------------------------
# 7. Disaster recovery
# ---------------------------------------------------------------------------
# Roll back an entire run:
php mod/hvp/cli/fix_subcontent.php --restore-run=run_20260921_100340_d4e5f6

# Roll back just a couple of records from that run:
php mod/hvp/cli/fix_subcontent.php --restore-run=run_20260921_100340_d4e5f6 --restore-id=501,502

# ---------------------------------------------------------------------------
# 8. Clearing a checkpoint once you're confident a run is fully done
# ---------------------------------------------------------------------------
php mod/hvp/cli/fix_subcontent.php --all --library=H5P.CoursePresentation --apply --clear-checkpoint
EXAMPLES
