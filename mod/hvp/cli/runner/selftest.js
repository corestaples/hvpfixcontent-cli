#!/usr/bin/env node
/**
 * selftest.js
 *
 * Built for: Moodle 4.5.12 (2024100712), Ubuntu 24.04 LTS, PHP 8.3.32, MySQL 8.0.46, mod_hvp 1.28.4+.
 * 
 * NO GUARANTEES - `Experimental`
 * 
 * ALWAYS, ALWAYS, ALWAYS - Make a backup/snapshot of your entire database first to err on the side of caution.
 * 
 * Standalone sanity check for upgrade-runner.js that does NOT require Moodle,
 * a database, or any real H5P library files. Run this once after installing
 * Node to confirm the runner subprocess and protocol work on this server:
 *
 *   node mod/hvp/cli/runner/selftest.js
 *
 * It spawns upgrade-runner.js exactly the way fix_subcontent.php does, sends
 * a synthetic H5P.Column-style init payload with a tiny fake upgrades.js, then
 * sends one job containing a nested sub-content library reference at an old
 * version and checks that the runner bumps it to the "latest" version and
 * reports changed:true - exactly the fix_subcontent behaviour.
 *
 * @package    mod_hvp
 * @subpackage cli
 * @copyright  2026 Dave S. AS <corestaples@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

'use strict';

const { spawn } = require('child_process');
const path = require('path');

const child = spawn(process.execPath, [path.join(__dirname, 'upgrade-runner.js')], {
  stdio: ['pipe', 'pipe', 'pipe'],
});

let buffer = '';
let step = 0;
let failed = false;

child.stdout.on('data', (chunk) => {
  buffer += chunk.toString();
  let idx;
  while ((idx = buffer.indexOf('\n')) >= 0) {
    const line = buffer.slice(0, idx);
    buffer = buffer.slice(idx + 1);
    if (line.trim() === '') {
      continue;
    }
    handle(JSON.parse(line));
  }
});

child.stderr.on('data', (chunk) => {
  process.stderr.write(`[runner-stderr] ${chunk}`);
});

child.on('exit', (code) => {
  if (!failed && step >= 2) {
    console.log('\nSELFTEST PASSED - upgrade-runner.js is working correctly.');
    process.exit(0);
  }
  else if (!failed) {
    console.error('\nSELFTEST FAILED - runner exited before completing all steps.');
    process.exit(1);
  }
});

function send(obj) {
  child.stdin.write(JSON.stringify(obj) + '\n');
}

// The runner only emits its "ready" PROTOCOL message (distinct from the plain
// startup line it prints to stderr) once it has processed our "init" message.
// So we send init proactively as soon as the child process's pipes are up,
// rather than waiting to be told to.
child.on('spawn', () => {
  // A minimal, self-contained upgrades.js: bumps any 1.0 params to 1.1 by
  // adding a marker field. Mirrors the real shape H5P content types ship.
  const fakeUpgradeScript = `
    var H5PUpgrades = H5PUpgrades || {};
    H5PUpgrades['H5P.FakeType'] = {
      1: {
        1: function (parameters, finished) {
          parameters.upgradedBySelftest = true;
          finished(null, parameters);
        }
      }
    };
  `;

  send({
    type: 'init',
    latestVersions: {
      'H5P.FakeType': { major: 1, minor: 1, patch: 0 },
    },
    libraries: {
      'H5P.FakeType': { upgradeScript: fakeUpgradeScript },
    },
  });
});

function handle(msg) {
  if (msg.type === 'ready') {
    console.log('[1/2] init acknowledged:', msg);
    step = 1;

    // Synthetic content: a root object containing one nested sub-content
    // (duck-typed as {library, params}) at the OLD version 1.0.
    send({
      type: 'job',
      id: 1,
      params: {
        someField: {
          library: 'H5P.FakeType 1.0',
          params: { text: 'hello world' },
          subContentId: 'abc-123',
        },
        unrelatedField: 'left untouched',
      },
    });
  }
  else if (msg.type === 'result') {
    console.log('[2/2] job result:', JSON.stringify(msg, null, 2));
    step = 2;

    const ok =
      msg.ok === true &&
      msg.changed === true &&
      msg.params.someField.library === 'H5P.FakeType 1.1' &&
      msg.params.someField.params.upgradedBySelftest === true &&
      msg.params.unrelatedField === 'left untouched';

    if (!ok) {
      failed = true;
      console.error('SELFTEST FAILED - result did not match expected upgraded shape.');
    }

    send({ type: 'shutdown' });
  }
}

setTimeout(() => {
  if (step < 2) {
    console.error('SELFTEST FAILED - timed out waiting for runner response.');
    failed = true;
    child.kill();
    process.exit(1);
  }
}, 5000);
