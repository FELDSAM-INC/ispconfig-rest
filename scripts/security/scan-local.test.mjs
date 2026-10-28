import assert from 'node:assert/strict'
import { mkdtempSync, readFileSync, rmSync, statSync, writeFileSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { join } from 'node:path'
import test from 'node:test'

import { scanLocal, parseOptions } from './scan-local.mjs'

const rateLimited = {
  status: 'failed',
  code: 'SCAN_FAILED',
  message: 'The configured account reached its rate limit. Wait and retry.',
}

function scenario(responses, options = {}) {
  const reports = mkdtempSync(join(tmpdir(), 'security-runner-test-'))
  const calls = []
  const waits = []
  return {
    calls,
    waits,
    reports,
    async run() {
      return scanLocal(
        { repository: '/test/repository', reports, ...options },
        {
          environment: {
            PATH: '/bin',
            OPENAI_API_KEY: 'test-api-key',
            CODEX_API_KEY: 'test-codex-key',
            CODEX_SECURITY_API_KEY: 'test-ci-key',
            CODEX_SECURITY_PROJECT_CONFIG: '/test/override.json',
          },
          execute(command, args, configuration) {
            calls.push({ command, args, configuration })
            const response = responses.shift()
            assert.ok(response, 'Unexpected extra scan')
            if (response.stderr) {
              assert.equal(typeof configuration.stdio[2], 'number')
              writeFileSync(configuration.stdio[2], response.stderr)
            }
            return {
              status: response.status,
              signal: response.signal,
              stdout: JSON.stringify(response.body ?? {}),
            }
          },
          sleep: async (seconds) => {
            waits.push(seconds)
          },
          jitter: () => 0,
          log: () => {},
        },
      )
    },
    cleanup() {
      rmSync(reports, { recursive: true, force: true })
    },
  }
}

test('subscription authentication is explicit and API keys never reach the scanner', async (t) => {
  const run = scenario([{ status: 0 }])
  t.after(() => run.cleanup())
  assert.equal(await run.run(), 0)
  const { command, args, configuration } = run.calls[0]
  assert.equal(command, 'npx')
  assert.equal(args[args.indexOf('--auth') + 1], 'chatgpt')
  assert.equal(args[args.indexOf('--provider') + 1], 'openai')
  assert.equal(configuration.env.OPENAI_API_KEY, undefined)
  assert.equal(configuration.env.CODEX_API_KEY, undefined)
  assert.equal(configuration.env.CODEX_SECURITY_API_KEY, undefined)
  assert.equal(configuration.env.CODEX_SECURITY_PROJECT_CONFIG, undefined)
  assert.equal(configuration.env.PATH, '/bin')
  assert.deepEqual(run.waits, [])
})

test('verbose diagnostics are saved privately without exposing API keys or retrying access failures', async (t) => {
  const run = scenario([
    { status: 2, body: { status: 'failed', code: 'SCAN_FAILED', message: 'Access denied' }, stderr: 'private diagnostics' },
  ], { verbose: true })
  t.after(() => run.cleanup())
  assert.equal(await run.run(), 2)
  assert.ok(run.calls[0].args.includes('--verbose'))
  assert.equal(run.calls[0].configuration.env.OPENAI_API_KEY, undefined)
  const diagnosticPath = join(run.reports, 'attempt-1.log')
  assert.equal(readFileSync(diagnosticPath, 'utf8'), 'private diagnostics')
  assert.equal(statSync(diagnosticPath).mode & 0o777, 0o600)
  assert.equal(parseOptions(['--verbose']).verbose, true)
  assert.equal(run.calls.length, 1)
  assert.deepEqual(run.waits, [])
})

test('rate limits wait with backoff, keep separate attempts, then return success', async (t) => {
  const run = scenario([
    { status: 2, body: rateLimited },
    { status: 2, body: rateLimited },
    { status: 0 },
  ])
  t.after(() => run.cleanup())
  assert.equal(await run.run(), 0)
  assert.deepEqual(run.waits, [60, 120])
  assert.equal(run.calls.length, 3)
  assert.notDeepEqual(run.calls[0].args, run.calls[1].args)
  assert.deepEqual(
    JSON.parse(readFileSync(join(run.reports, 'attempt-1.json'), 'utf8')),
    rateLimited,
  )
})

test('a server retry hint is respected without shortening it', async (t) => {
  const run = scenario([
    { status: 2, body: { ...rateLimited, retryAfterSeconds: 300 } },
    { status: 0 },
  ])
  t.after(() => run.cleanup())
  assert.equal(await run.run(), 0)
  assert.deepEqual(run.waits, [300])
})

test('the CLI structured error envelope also retries rate limits', async (t) => {
  const run = scenario([{ status: 2, body: { ok: false, error: rateLimited } }, { status: 0 }])
  t.after(() => run.cleanup())
  assert.equal(await run.run(), 0)
  assert.deepEqual(run.waits, [60])
})

test('the wait budget is shared by all attempts', async (t) => {
  const run = scenario(
    [
      { status: 2, body: rateLimited },
      { status: 2, body: rateLimited },
    ],
    { maxWait: 100 },
  )
  t.after(() => run.cleanup())
  assert.equal(await run.run(), 2)
  assert.deepEqual(run.waits, [60])
  assert.equal(run.calls.length, 2)
})

test('wait budget exhaustion stops instead of retrying earlier than the server hint', async (t) => {
  const run = scenario([{ status: 2, body: { ...rateLimited, retryAfterSeconds: 901 } }])
  t.after(() => run.cleanup())
  assert.equal(await run.run(), 2)
  assert.deepEqual(run.waits, [])
  assert.equal(run.calls.length, 1)
})

test('persistent throttling fails after three attempts', async (t) => {
  const run = scenario(Array.from({ length: 3 }, () => ({ status: 2, body: rateLimited })))
  t.after(() => run.cleanup())
  assert.equal(await run.run(), 2)
  assert.equal(run.calls.length, 3)
  assert.deepEqual(run.waits, [60, 120])
})

for (const [name, response] of Object.entries({
  'authentication error': { status: 2, body: { ...rateLimited, message: 'Invalid credentials' } },
  'cost limit': { status: 2, body: { ...rateLimited, message: 'Scan stopped: budget exceeded' } },
  'findings policy': { status: 1, body: rateLimited },
  'billing exhaustion': {
    status: 2,
    body: { ...rateLimited, message: `credit_balance_exhausted: ${rateLimited.message}` },
  },
  cancellation: { status: null, signal: 'SIGINT' },
  'unstructured failure': { status: 2, body: { message: rateLimited.message } },
})) {
  test(`${name} does not restart the scan`, async (t) => {
    const run = scenario([response])
    t.after(() => run.cleanup())
    assert.notEqual(await run.run(), 0)
    assert.equal(run.calls.length, 1)
    assert.deepEqual(run.waits, [])
  })
}

test('dry runs do not retry and cannot be changed into API authentication', async (t) => {
  const run = scenario([{ status: 2, body: rateLimited }], { dryRun: true })
  t.after(() => run.cleanup())
  assert.equal(await run.run(), 2)
  assert.ok(run.calls[0].args.includes('--dry-run'))
  assert.deepEqual(run.waits, [])
  assert.throws(() => parseOptions(['--auth', 'api-key']))
})
