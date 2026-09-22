#!/usr/bin/env node
/**
 * upgrade-runner.js
 *
 * Built for: Moodle 4.5.12 (2024100712), Ubuntu 24.04 LTS, PHP 8.3.32, MySQL 8.0.46, mod_hvp 1.28.4+.
 * 
 * NO GUARANTEES - `Experimental`
 * 
 * ALWAYS, ALWAYS, ALWAYS - Make a backup/snapshot of your entire database first to err on the side of caution.
 * 
 * Headless H5P content-parameter upgrade engine for the mod_hvp fix_subcontent CLI.
 *
 * This process is spawned ONCE by the PHP orchestrator (fix_subcontent.php) via
 * proc_open() and kept alive for the lifetime of a run. It speaks a line-delimited
 * JSON (NDJSON) protocol over stdin/stdout:
 *
 *   PHP -> Node   {"type":"init","libraries":{...}}
 *   Node -> PHP   {"type":"ready"}
 *   PHP -> Node   {"type":"job","id":123,"params":{...}}
 *   Node -> PHP   {"type":"result","id":123,"ok":true,"changed":true,"params":{...}}
 *   PHP -> Node   {"type":"shutdown"}
 *   Node -> PHP   {"type":"bye"}
 *
 * Every message is exactly one line of JSON terminated by "\n". Node never prints
 * anything else to stdout - all diagnostics go to stderr - so the protocol stream
 * stays clean even if a dependency misbehaves.
 *
 * The actual traversal/upgrade algorithm lives in logic-content-upgrade.js, which
 * is used completely unmodified. It recursively walks the content parameter tree,
 * finds any object shaped like {library: "H5P.X 1.2", params: {...}}  (i.e. any
 * embedded sub-content, at any depth, regardless of the semantics field name) and
 * runs that library's upgrades.js hooks on it, bumping the library version string
 * once all applicable hooks have run. Because this is done by structural shape
 * rather than by walking semantics.json, it only ever touches genuine sub-content
 * library references - never the root content's own main_library_id - which is
 * exactly the "fix_subcontent" behaviour of /mod/hvp/library_list.php?fix_subcontent=1.
 * 
 * @package    mod_hvp
 * @subpackage cli
 * @copyright  2026 Dave S. AS <corestaples@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

'use strict';

const readline = require('readline');
const vm = require('vm');
const { upgradeContent } = require('./logic-content-upgrade.js');

// ---------------------------------------------------------------------------
// Library registry: populated by the "init" message, then used for the whole run.
// ---------------------------------------------------------------------------

/** @type {Object<string, {major:number, minor:number, patch:number}>} */
let latestVersions = {};

/** @type {Object<string, object>} machineName -> H5PUpgrades[machineName] map extracted from upgrades.js */
let upgradeFunctionsByLibrary = {};

/** Shared sandbox all upgrades.js files are evaluated into (namespaced by H5PUpgrades[machineName]) */
const sandbox = { H5PUpgrades: {}, console: { log: () => {}, warn: () => {}, error: () => {} } };
vm.createContext(sandbox);

/**
 * Evaluate one library's upgrades.js source inside the shared sandbox and
 * extract the H5PUpgrades[machineName] object it registers.
 *
 * @param {string} machineName
 * @param {string} source raw JS source of upgrades.js
 */
function loadUpgradeScript(machineName, source) {
  try {
    // upgrades.js files assign to the global H5PUpgrades object, e.g.:
    //   H5PUpgrades['H5P.Column'] = { 1: { 22: function (params, finished) {...} } };
    vm.runInContext(source, sandbox, {
      filename: `${machineName}/upgrades.js`,
      timeout: 10000,
    });
  }
  catch (err) {
    process.stderr.write(`[upgrade-runner] Failed to evaluate upgrades.js for ${machineName}: ${err.message}\n`);
    return;
  }

  if (sandbox.H5PUpgrades && sandbox.H5PUpgrades[machineName]) {
    upgradeFunctionsByLibrary[machineName] = sandbox.H5PUpgrades[machineName];
  }
}

/**
 * @param {string} machineName
 * @returns {object|undefined} upgrade function tree for this library, or undefined if none
 */
function getUpgradesScript(machineName) {
  return upgradeFunctionsByLibrary[machineName];
}

/**
 * @param {string} machineName
 * @returns {{major:number, minor:number}} latest installed version for this library
 */
function getLatestLibraryVersion(machineName) {
  const v = latestVersions[machineName];
  if (!v) {
    // No installed version known for this machine name. logic-content-upgrade.js's
    // upgradeLibrary() will unconditionally stringify whatever we return here into
    // the content, so we MUST NOT return a fabricated version (e.g. "0.0") - that
    // would silently corrupt an otherwise-valid library reference. Instead we
    // return a sentinel object whose toString() reproduces "0.0" for internal use
    // but which handleJob() below detects via the unknownLibraries tracker and
    // uses to veto the write-back of this entire content item. See handleJob().
    return { major: 0, minor: 0, __unknown: true };
  }
  return { major: v.major, minor: v.minor };
}

// ---------------------------------------------------------------------------
// Per-job processing
// ---------------------------------------------------------------------------

/**
 * Deep, order-independent-enough change check. We compare canonical JSON
 * strings; since both sides went through JSON.parse/JSON.stringify this is
 * sufficient to detect whether the upgrade actually altered anything.
 */
function sameJson(a, b) {
  return JSON.stringify(a) === JSON.stringify(b);
}

function handleJob(job) {
  const { id } = job;
  const warnings = [];
  const originalUnknownLibraries = [];

  // Track any machine names encountered during traversal whose "latest version"
  // was unknown to us (not installed / not in hvp_libraries), so PHP can report
  // them instead of silently writing back a "H5P.Foo 0.0" reference.
  const originalGetLatest = getLatestLibraryVersion;
  const trackingGetLatest = (machineName) => {
    const v = latestVersions[machineName];
    if (!v) {
      originalUnknownLibraries.push(machineName);
    }
    return originalGetLatest(machineName);
  };

  let inputParams;
  try {
    inputParams = job.params;
    if (inputParams === null || typeof inputParams !== 'object') {
      throw new Error('json_content did not decode to an object');
    }
  }
  catch (err) {
    return { type: 'result', id, ok: false, error: `decode_error: ${err.message}` };
  }

  let upgraded;
  try {
    upgraded = upgradeContent(
      // Clone defensively: upgradeContent mutates in place in several branches.
      JSON.parse(JSON.stringify(inputParams)),
      getUpgradesScript,
      trackingGetLatest,
      {} // no explicit targetVersion => apply every applicable upgrade hook (fix_subcontent semantics)
    );
  }
  catch (err) {
    return { type: 'result', id, ok: false, error: `upgrade_error: ${err.message}` };
  }

  if (originalUnknownLibraries.length > 0) {
    // SAFETY: at least one nested library reference points at a machine name that
    // is not installed on this server. upgradeLibrary() will have already rewritten
    // that reference's version string to the "0.0" sentinel inside `upgraded`, which
    // is not something we're willing to persist - writing "H5P.Foo 0.0" back to the
    // database would be strictly worse than leaving the original (broken-but-known)
    // version string in place. We veto the write for the ENTIRE content item rather
    // than trying to selectively patch just the bad branch back out of `upgraded`,
    // because that branch may be arbitrarily deep and partially processed.
    return {
      type: 'result',
      id,
      ok: true,
      changed: false,
      skipped: true,
      skippedReason: 'unresolved_library_reference',
      params: inputParams, // unmodified - caller will NOT write this back either way
      warnings: [
        `Skipped: content references sub-content library machine name(s) not installed ` +
        `on this server: ${[...new Set(originalUnknownLibraries)].join(', ')}. Install the ` +
        `missing library (or its content type) and re-run, or investigate manually.`,
      ],
    };
  }

  const changed = !sameJson(inputParams, upgraded);

  return {
    type: 'result',
    id,
    ok: true,
    changed,
    skipped: false,
    params: upgraded,
    warnings,
  };
}

// ---------------------------------------------------------------------------
// NDJSON protocol loop
// ---------------------------------------------------------------------------

const rl = readline.createInterface({ input: process.stdin, terminal: false });

function send(obj) {
  process.stdout.write(JSON.stringify(obj) + '\n');
}

let initialized = false;

rl.on('line', (line) => {
  line = line.trim();
  if (line === '') {
    return;
  }

  let msg;
  try {
    msg = JSON.parse(line);
  }
  catch (err) {
    send({ type: 'error', error: `protocol_error: could not parse line as JSON: ${err.message}` });
    return;
  }

  switch (msg.type) {
    case 'init': {
      latestVersions = msg.latestVersions || {};
      const libraries = msg.libraries || {};
      let loaded = 0;
      for (const machineName of Object.keys(libraries)) {
        const entry = libraries[machineName];
        if (entry && entry.upgradeScript) {
          loadUpgradeScript(machineName, entry.upgradeScript);
          loaded++;
        }
      }
      initialized = true;
      send({
        type: 'ready',
        librariesKnown: Object.keys(latestVersions).length,
        upgradeScriptsLoaded: loaded,
        nodeVersion: process.version,
      });
      break;
    }

    case 'job': {
      if (!initialized) {
        send({ type: 'result', id: msg.id, ok: false, error: 'runner_not_initialized' });
        break;
      }
      let result;
      try {
        result = handleJob(msg);
      }
      catch (err) {
        result = { type: 'result', id: msg.id, ok: false, error: `fatal: ${err.stack || err.message}` };
      }
      send(result);
      break;
    }

    case 'ping': {
      send({ type: 'pong' });
      break;
    }

    case 'shutdown': {
      send({ type: 'bye' });
      rl.close();
      process.exit(0);
      break;
    }

    default:
      send({ type: 'error', error: `unknown_message_type: ${msg.type}` });
  }
});

process.on('uncaughtException', (err) => {
  process.stderr.write(`[upgrade-runner] uncaughtException: ${err.stack || err.message}\n`);
  // Do not exit - keep serving remaining jobs where possible; PHP tracks per-id
  // results and will treat a missing response as a job-level failure on timeout.
});

process.stderr.write(`[upgrade-runner] ready, waiting for init (node ${process.version})\n`);
