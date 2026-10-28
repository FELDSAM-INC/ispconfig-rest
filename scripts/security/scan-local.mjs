// Local subscription scans. CI deliberately uses the official Action for PR diffs only.
import { spawnSync } from 'node:child_process'
import { closeSync, mkdtempSync, openSync, writeFileSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { basename, join, resolve } from 'node:path'
import { setTimeout as sleep } from 'node:timers/promises'
import { fileURLToPath } from 'node:url'
import { parseArgs } from 'node:util'

const CLI = '@openai/codex-security@0.6.0'
const MAX_ATTEMPTS = 3

export function parseOptions(args) {
  const { values } = parseArgs({
    args,
    options: {
      'dry-run': { type: 'boolean', default: false },
      verbose: { type: 'boolean', default: false },
      'max-wait-seconds': { type: 'string', default: '900' },
      model: { type: 'string', default: 'gpt-6-sol' },
      python: { type: 'string' },
      help: { type: 'boolean', default: false },
    },
  })
  const maxWait = Number(values['max-wait-seconds'])
  if (!Number.isInteger(maxWait) || maxWait < 0 || maxWait > 7200) {
    throw new Error('--max-wait-seconds must be an integer between 0 and 7200.')
  }
  return {
    dryRun: values['dry-run'],
    verbose: values.verbose,
    maxWait,
    model: values.model,
    python: values.python,
    help: values.help,
  }
}

function rateLimitFailure(stdout) {
  try {
    const payload = JSON.parse(stdout)
    const failure =
      payload.status === 'failed' ? payload : payload.ok === false ? payload.error : null
    if (failure?.code !== 'SCAN_FAILED' || typeof failure.message !== 'string') return null
    if (!failure.message.includes('The configured account reached its rate limit. Wait and retry.'))
      return null
    if (
      /credit_balance|insufficient_quota|billing|payment|budget|cost limit/i.test(failure.message)
    )
      return null
    const hint = failure.retryAfterSeconds
    return { retryAfter: Number.isFinite(hint) && hint > 0 ? Math.ceil(hint) : 0 }
  } catch {
    return null
  }
}

export async function scanLocal(options, dependencies = {}) {
  const environment = { ...(dependencies.environment ?? process.env) }
  // Explicit --auth is also required: an API key in the parent must never cause paid fallback.
  for (const key of Object.keys(environment)) {
    if (['OPENAI_API_KEY', 'CODEX_API_KEY', 'CODEX_SECURITY_API_KEY', 'CODEX_SECURITY_PROJECT_CONFIG'].includes(key.toUpperCase())) delete environment[key]
  }
  const execute = dependencies.execute ?? spawnSync
  const wait = dependencies.sleep ?? ((seconds) => sleep(seconds * 1000))
  const jitter = dependencies.jitter ?? (() => Math.floor(Math.random() * 16))
  const log = dependencies.log ?? console.error
  let waited = 0

  log(`Using ChatGPT sign-in and plan usage. Private scan reports: ${options.reports}`)
  for (let attempt = 1; attempt <= MAX_ATTEMPTS; attempt += 1) {
    const args = [
      '--yes',
      CLI,
      'scan',
      options.repository,
      '--auth',
      'chatgpt',
      '--provider',
      'openai',
      '--model',
      options.model ?? 'gpt-6-sol',
      '--effort',
      'high',
      '--headless',
      '--json',
      '--output-dir',
      join(options.reports, `attempt-${attempt}`),
    ]
    if (options.python) args.push('--python', options.python)
    if (options.dryRun) args.push('--dry-run')
    if (options.verbose) args.push('--verbose')
    log(`Scan attempt ${attempt}/${MAX_ATTEMPTS}.`)
    // Verbose output may contain source or credentials. Keep it out of terminal/CI logs.
    const diagnosticPath = join(options.reports, `attempt-${attempt}.log`)
    const diagnosticFd = options.verbose ? openSync(diagnosticPath, 'wx', 0o600) : null
    if (options.verbose) log(`Private verbose diagnostics: ${diagnosticPath}`)
    let result
    try {
      result = execute('npx', args, {
        cwd: options.repository,
        env: environment,
        encoding: 'utf8',
        stdio: ['ignore', 'pipe', diagnosticFd ?? 'inherit'],
        maxBuffer: 64 * 1024 * 1024,
      })
    } finally {
      if (diagnosticFd !== null) closeSync(diagnosticFd)
    }
    writeFileSync(join(options.reports, `attempt-${attempt}.json`), result.stdout ?? '', {
      mode: 0o600,
    })
    if (result.signal) return result.signal === 'SIGINT' ? 130 : 143
    if (result.error) {
      log(`Could not run the scanner: ${result.error.message}`)
      return 2
    }
    if (result.status === 0) {
      log(
        options.dryRun
          ? 'Configuration validated; no security scan or model call was performed.'
          : `Scan finished. Review findings and coverage in ${join(options.reports, `attempt-${attempt}`)}.`,
      )
      return 0
    }
    const limited = result.status === 2 ? rateLimitFailure(result.stdout) : null
    if (!limited || options.dryRun || attempt === MAX_ATTEMPTS) return result.status ?? 2
    const delay = Math.max(60 * 2 ** (attempt - 1), limited.retryAfter) + jitter()
    if (waited + delay > (options.maxWait ?? 900)) {
      log(
        'The rate-limit wait exceeds the remaining wait budget. Retry after the account limit resets.',
      )
      return 2
    }
    log(
      `Rate limited. Waiting ${delay}s before starting a new scan; another attempt consumes more plan usage.`,
    )
    await wait(delay)
    waited += delay
  }
  return 2
}

function findPython(preferred) {
  for (const candidate of preferred
    ? [preferred]
    : ['python3.13', 'python3.12', 'python3.11', 'python3.10', 'python3']) {
    const result = spawnSync(
      candidate,
      ['-I', '-c', 'import sys; sys.exit(sys.version_info < (3, 10))'],
      { stdio: 'ignore' },
    )
    if (result.status === 0) return candidate
  }
  throw new Error('Python 3.10+ is required. Install Python 3.12 or select it with --python.')
}

if (process.argv[1] && resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
  try {
    const options = parseOptions(process.argv.slice(2))
    if (options.help) {
      console.log(
        'node scripts/security/scan-local.mjs [--dry-run] [--verbose] [--model MODEL] [--python PATH] [--max-wait-seconds 900]',
      )
      console.log(`Sign in first: npx --yes ${CLI} login`)
    } else {
      const repository = fileURLToPath(new URL('../../', import.meta.url))
      const reports = mkdtempSync(join(tmpdir(), `${basename(repository)}-security-`))
      process.exitCode = await scanLocal({
        ...options,
        repository,
        reports,
        python: findPython(options.python ?? process.env.PYTHON),
      })
    }
  } catch (error) {
    console.error(error.message)
    process.exitCode = 2
  }
}
