import assert from 'node:assert/strict'
import { createHmac } from 'node:crypto'
import { spawn, spawnSync } from 'node:child_process'
import { once } from 'node:events'
import { copyFile, cp, mkdir, mkdtemp, readFile, rm, writeFile } from 'node:fs/promises'
import { createServer } from 'node:net'
import { createServer as createHttpServer } from 'node:http'
import { tmpdir } from 'node:os'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const testsDirectory = path.dirname(fileURLToPath(import.meta.url))
const php = process.argv[2] || process.env.PHP_BINARY || 'php'
const filter = process.env.WHMCS_TEST_FILTER ? new RegExp(process.env.WHMCS_TEST_FILTER, 'i') : null
const version = spawnSync(php, ['-v'], { encoding: 'utf8' })
if (version.status !== 0) throw new Error(`PHP CLI is required: ${version.error || version.stderr}`)
const fixture = await mkdtemp(path.join(tmpdir(), 'anore-whmcs-test-'))
const stateFile = path.join(fixture, 'state.json')
let server
let apiServer
let serverOutput = ''
let passed = 0
const secret = 'whmcs-test-webhook-secret'
const paymentId = '0ad952c8-1df9-4c4a-8873-6fbb3321f017'
const olderPaymentId = 'ccce9d70-76dc-4a96-a828-bf9266f83b88'
let apiOrigin
let apiRequests = []
let apiResponseOverride = null

function creationParams(overrides = {}) {
  return {
    name: 'Anore', apiUrl: `${apiOrigin}/api/v1`, apiKey: 'fixture-api-key', apiSecret: 'fixture-api-secret',
    webhookSecret: secret, invoiceid: 42, amount: '123.45', currency: 'RUB', shopId: '27',
    systemurl: 'https://billing.example.test/clients/', returnurl: 'https://billing.example.test/viewinvoice.php?id=42',
    clientdetails: { email: 'customer@example.test' }, methods: 'sbp,card,crypto-old,card',
    identitySource: 'external_id', franchiseMode: 'disabled',
    ...overrides,
  }
}

async function createPayment(overrides = {}) {
  const response = await fetch(`${origin}/create.php`, {
    method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(creationParams(overrides)),
  })
  assert.equal(response.status, 200)
  return response.text()
}

function baseState() {
  const attempt = {
    id: 1,
    invoice_id: 42,
    payment_id: paymentId,
    payment_url: `https://pay.anore.cc/${paymentId}`,
    amount: '123.45',
    currency: 'RUB',
    status: 'new',
    expires_at: '2099-01-01 00:00:00',
    created_at: '2026-01-01 00:00:00',
    updated_at: '2026-01-01 00:00:00',
    request_fingerprint: 'a'.repeat(64),
  }
  return {
    gateway: { type: 'Invoices', name: 'Anore', webhookSecret: secret },
    tables: {
      mod_anore_payment_attempts: [attempt],
      mod_anore_payments: [],
      tblinvoices: [{ id: 42, userid: 1, status: 'Unpaid', paymentmethod: 'anore', total: '123.45' }],
      tblclients: [{ id: 1, currency: 1 }],
      tblcurrencies: [{ id: 1, code: 'RUB' }],
      tblcustomfields: [
        { id: 7, type: 'client', fieldname: 'Internal Telegram ID' },
        { id: 8, type: 'client', fieldname: 'Internal Telegram Username' },
      ],
      tblcustomfieldsvalues: [
        { id: 1, fieldid: 7, relid: 1, value: '123456789' },
        { id: 2, fieldid: 8, relid: 1, value: '@Customer_Name' },
      ],
      tblaccounts: [],
    },
    logs: [], credits: [], locks: [], advisoryLocks: [],
  }
}

function success(overrides = {}) {
  return { event: 'payment.succeeded', id: paymentId, orderId: '42', amount: 123.45, currency: 'RUB', ...overrides }
}

async function state() {
  return JSON.parse(await readFile(stateFile, 'utf8'))
}

async function reset(change = () => {}) {
  const value = baseState()
  change(value)
  await writeFile(stateFile, JSON.stringify(value), 'utf8')
}

async function test(name, run) {
  if (filter && !filter.test(name)) return
  await reset()
  apiRequests = []
  apiResponseOverride = null
  await run()
  passed += 1
  console.log(`PASS ${name}`)
}

async function request(payload = success(), options = {}) {
  const raw = typeof payload === 'string' ? payload : JSON.stringify(payload)
  const method = options.method || 'POST'
  const response = await fetch(`${origin}/modules/gateways/callback/anore.php`, {
    method,
    headers: {
      'Content-Type': 'application/json',
      'Anore-Signature': options.signature ?? createHmac('sha256', secret).update(raw).digest('hex'),
    },
    ...(method === 'GET' ? {} : { body: raw }),
  })
  return { status: response.status, body: await response.text() }
}

async function expectStatus(expected, payload = success(), options = {}) {
  const response = await request(payload, options)
  assert.equal(response.status, expected, `response=${JSON.stringify(response)}\nserver=${serverOutput}`)
  if (expected === 200) assert.equal(response.body.trim(), 'OK')
  return response
}

let origin
try {
  await mkdir(path.join(fixture, 'includes'), { recursive: true })
  await cp(path.join(testsDirectory, '..', 'modules'), path.join(fixture, 'modules'), { recursive: true })
  await copyFile(path.join(testsDirectory, 'whmcs-fixture.php'), path.join(fixture, 'whmcs-fixture.php'))
  await writeFile(path.join(fixture, 'init.php'), "<?php\ndefine('WHMCS', true);\nrequire_once __DIR__ . '/whmcs-fixture.php';\n", 'utf8')
  await writeFile(path.join(fixture, 'create.php'), "<?php\nrequire_once __DIR__ . '/init.php';\nrequire_once __DIR__ . '/modules/gateways/anore.php';\necho anore_link(json_decode(file_get_contents('php://input'), true));\n", 'utf8')
  for (const file of ['gatewayfunctions.php', 'invoicefunctions.php']) {
    await writeFile(path.join(fixture, 'includes', file), '<?php\n', 'utf8')
  }
  for (const file of ['modules/gateways/anore.php', 'modules/gateways/callback/anore.php', 'whmcs-fixture.php']) {
    const lint = spawnSync(php, ['-l', path.join(fixture, file)], { encoding: 'utf8' })
    assert.equal(lint.status, 0, lint.stdout + lint.stderr)
  }
  const portProbe = createServer()
  portProbe.listen(0, '127.0.0.1')
  await once(portProbe, 'listening')
  const port = portProbe.address().port
  await new Promise(resolve => portProbe.close(resolve))
  origin = `http://127.0.0.1:${port}`
  apiServer = createHttpServer(async (request, response) => {
    let raw = ''
    for await (const chunk of request) raw += chunk
    apiRequests.push({ path: request.url, method: request.method, headers: request.headers, raw, body: JSON.parse(raw) })
    const id = `aaaaaaaa-1111-4222-8333-${String(apiRequests.length).padStart(12, '0')}`
    response.writeHead(201, { 'Content-Type': 'application/json' })
    response.end(JSON.stringify(apiResponseOverride ?? { id, paymentUrl: `https://pay.anore.cc/${id}`, expiresIn: 14400 }))
  })
  apiServer.listen(0, '127.0.0.1')
  await once(apiServer, 'listening')
  apiOrigin = `http://127.0.0.1:${apiServer.address().port}`
  await reset()
  server = spawn(php, ['-d', 'display_errors=0', '-d', 'log_errors=1', '-d', 'date.timezone=Asia/Tokyo', '-S', `127.0.0.1:${port}`, '-t', fixture], {
    env: { ...process.env, WHMCS_FIXTURE_STATE: stateFile },
    stdio: ['ignore', 'pipe', 'pipe'],
  })
  server.stdout.on('data', chunk => { serverOutput += chunk })
  server.stderr.on('data', chunk => { serverOutput += chunk })
  for (let attempt = 0; attempt < 100; attempt++) {
    try { await fetch(`${origin}/missing`); break } catch (error) {
      if (attempt === 99 || server.exitCode !== null) throw new Error(`PHP server did not start: ${serverOutput}`)
      await new Promise(resolve => setTimeout(resolve, 50))
    }
  }

  await test('real WHMCS bootstrap paths accept a signed successful payment', async () => {
    await expectStatus(200)
    const result = await state()
    assert.deepEqual(result.credits, [[42, paymentId, 123.45, 0, 'anore']])
    assert.equal(result.tables.mod_anore_payment_attempts[0].status, 'paid')
    assert.ok(result.advisoryLocks.some(item => /GET_LOCK/.test(item.sql)), 'invoice advisory lock is required')
    assert.ok(result.advisoryLocks.some(item => /RELEASE_LOCK/.test(item.sql)), 'invoice advisory lock is released')
  })
  await test('repeated delivery credits the invoice exactly once', async () => {
    await expectStatus(200)
    await expectStatus(200)
    assert.equal((await state()).credits.length, 1)
  })
  await test('duplicate delivery survives a missing local mapping', async () => {
    await expectStatus(200)
    const value = await state()
    value.tables.mod_anore_payment_attempts = []
    delete value.tables.mod_anore_payments
    await writeFile(stateFile, JSON.stringify(value), 'utf8')
    await expectStatus(200)
    assert.equal((await state()).credits.length, 1)
  })
  await test('mapping supplies orderId if optional webhook orderId is absent', async () => {
    const payload = success()
    delete payload.orderId
    await expectStatus(200, payload)
    assert.equal((await state()).credits.length, 1)
  })
  await test('legacy payment mapping is accepted by payment ID', async () => {
    await reset(value => {
      value.tables.mod_anore_payments = value.tables.mod_anore_payment_attempts
      delete value.tables.mod_anore_payment_attempts
    })
    await expectStatus(200)
    assert.equal((await state()).credits.length, 1)
  })
  await test('an older attempt remains payable after a new attempt exists', async () => {
    await reset(value => {
      value.tables.mod_anore_payment_attempts.push({ ...value.tables.mod_anore_payment_attempts[0], id: 2, payment_id: olderPaymentId })
    })
    await expectStatus(200, success({ id: olderPaymentId }))
    assert.deepEqual((await state()).credits[0], [42, olderPaymentId, 123.45, 0, 'anore'])
  })
  await test('expired events cannot regress an already paid attempt', async () => {
    await expectStatus(200)
    await expectStatus(200, { event: 'payment.expired', id: paymentId })
    assert.equal((await state()).tables.mod_anore_payment_attempts[0].status, 'paid')
  })
  await test('expiry marks only the corresponding open attempt', async () => {
    await expectStatus(200, { event: 'payment.expired', id: paymentId })
    assert.equal((await state()).tables.mod_anore_payment_attempts[0].status, 'expired')
    assert.equal((await state()).credits.length, 0)
  })
  await test('unknown signed events are acknowledged without credit', async () => {
    await expectStatus(200, { event: 'payment.created', id: paymentId })
    assert.equal((await state()).credits.length, 0)
  })
  await test('GET callbacks are rejected explicitly', async () => { await expectStatus(405, '', { method: 'GET' }) })
  await test('invalid signature is rejected', async () => {
    await expectStatus(401, success(), { signature: '0'.repeat(64) })
    assert.equal((await state()).credits.length, 0)
  })
  await test('malformed signed JSON is rejected as bad input', async () => { await expectStatus(400, '{"event":') })
  await test('missing payment ID is rejected as bad input', async () => { await expectStatus(400, success({ id: '' })) })
  await test('missing currency cannot silently credit an invoice', async () => {
    const payload = success()
    delete payload.currency
    await expectStatus(400, payload)
    assert.equal((await state()).credits.length, 0)
  })
  await test('unknown mapping gives a validation error instead of a database 500', async () => {
    await expectStatus(422, success({ id: olderPaymentId }))
    assert.equal((await state()).credits.length, 0)
  })
  await test('wrong invoice ID cannot override trusted payment mapping', async () => {
    await expectStatus(422, success({ orderId: '999' }))
    assert.equal((await state()).credits.length, 0)
  })
  await test('wrong amount is rejected without credit', async () => {
    await expectStatus(422, success({ amount: 123.44 }))
    assert.equal((await state()).credits.length, 0)
  })
  await test('wrong currency is rejected without credit', async () => {
    await expectStatus(422, success({ currency: 'USD' }))
    assert.equal((await state()).credits.length, 0)
  })
  await test('USD invoices use original amount rather than the webhook RUB equivalent', async () => {
    await reset(value => {
      value.tables.mod_anore_payment_attempts[0].amount = '1.41'
      value.tables.mod_anore_payment_attempts[0].currency = 'USD'
      value.tables.tblcurrencies[0].code = 'USD'
      value.tables.tblinvoices[0].total = '1.41'
    })
    await expectStatus(200, success({ currency: 'USD', amount: 1.41, rubAmount: 123.45 }))
    assert.deepEqual((await state()).credits[0], [42, paymentId, 1.41, 0, 'anore'])
  })
  await test('processing currency mismatch with the WHMCS invoice cannot overcredit it', async () => {
    await reset(value => { value.tables.tblcurrencies[0].code = 'USD' })
    await expectStatus(422)
    assert.equal((await state()).credits.length, 0)
  })
  await test('duplicate transaction with mismatched amount is not silently acknowledged', async () => {
    await expectStatus(200)
    await expectStatus(422, success({ amount: 9.99 }))
    assert.equal((await state()).credits.length, 1)
  })
  await test('database failure is retryable and does not credit', async () => {
    await reset(value => { value.databaseFailure = true })
    await expectStatus(503)
    assert.equal((await state()).credits.length, 0)
  })
  await test('logging failure cannot break a valid callback', async () => {
    await reset(value => { value.logFailure = true })
    await expectStatus(200)
    await expectStatus(200)
    assert.equal((await state()).credits.length, 1)
  })
  await test('busy invoice lock is retryable without credit', async () => {
    await reset(value => { value.lockUnavailable = true })
    await expectStatus(503)
    assert.equal((await state()).credits.length, 0)
  })
  await test('credit failure retries safely after recovery', async () => {
    await reset(value => { value.creditFailure = true })
    await expectStatus(503)
    const value = await state()
    value.creditFailure = false
    await writeFile(stateFile, JSON.stringify(value), 'utf8')
    await expectStatus(200)
    await expectStatus(200)
    assert.equal((await state()).credits.length, 1)
  })
  await test('false credit result is retryable and does not mark attempt paid', async () => {
    await reset(value => { value.creditReturnsFalse = true })
    await expectStatus(503)
    assert.equal((await state()).tables.mod_anore_payment_attempts[0].status, 'new')
    assert.equal((await state()).credits.length, 0)
  })
  await test('cache failure after credit cannot cause duplicate credit on retry', async () => {
    await reset(value => { value.mappingUpdateFailure = true })
    const first = await request()
    assert.ok([200, 503].includes(first.status), `unexpected response: ${JSON.stringify(first)}`)
    const value = await state()
    value.mappingUpdateFailure = false
    await writeFile(stateFile, JSON.stringify(value), 'utf8')
    await expectStatus(200)
    assert.equal((await state()).credits.length, 1)
  })
  await test('payment creation sends current API fields and signs exact JSON', async () => {
    const html = await createPayment()
    assert.match(html, /href="https:\/\/pay\.anore\.cc\//)
    assert.equal(apiRequests.length, 1)
    const request = apiRequests[0]
    assert.equal(request.path, '/api/v1/payments')
    assert.equal(request.method, 'POST')
    assert.equal(request.headers.authorization, 'Bearer fixture-api-key')
    assert.equal(request.headers['x-zpay-signature'], createHmac('sha256', 'fixture-api-secret').update(request.raw).digest('hex'))
    assert.equal(request.body.email, 'customer@example.test')
    assert.equal(request.body.callbackUrl, 'https://billing.example.test/clients/modules/gateways/callback/anore.php')
    assert.equal(request.body.orderId, '42')
    assert.equal(request.body.shopId, 27)
    assert.deepEqual(request.body.methods, ['sbp', 'card', 'crypto-old'])
    assert.equal(request.body.amount, 123.45)
    assert.equal(request.body.currency, 'rub')
    assert.deepEqual(request.body.user_identity, { type: 'external_id', value: '1' })
    assert.equal('franchise' in request.body, false)
  })
  await test('email identity is canonicalized and sent explicitly', async () => {
    await createPayment({ identitySource: 'email', clientdetails: { email: '  Customer@Example.TEST ' } })
    assert.deepEqual(apiRequests[0].body.user_identity, { type: 'email', value: 'customer@example.test' })
  })
  await test('Telegram identity uses configured numeric custom field IDs only', async () => {
    await createPayment({
      identitySource: 'telegram_id', telegramIdCustomFieldId: '7', telegramUsernameCustomFieldId: '8',
    })
    assert.deepEqual(apiRequests[0].body.user_identity, {
      type: 'telegram_id', value: '123456789', telegram_username: 'customer_name',
    })
  })
  await test('Telegram custom field name is never accepted as configuration', async () => {
    const html = await createPayment({ identitySource: 'telegram_id', telegramIdCustomFieldId: 'Internal Telegram ID' })
    assert.match(html, /alert-danger/)
    assert.equal(apiRequests.length, 0)
  })
  await test('invoice owner is authoritative for external identity', async () => {
    await createPayment({ clientdetails: { email: 'customer@example.test', id: 999, userid: 999 } })
    assert.deepEqual(apiRequests[0].body.user_identity, { type: 'external_id', value: '1' })
  })
  await test('enabled franchise mode sends canonical Telegram branch context', async () => {
    await createPayment({
      franchiseMode: 'enabled', franchiseType: 'telegram_bot', franchiseAddress: '@Store_Bot',
      franchiseOwnerTelegramId: '987654321', franchiseOwnerTelegramUsername: '@Store_Owner',
    })
    assert.deepEqual(apiRequests[0].body.franchise, {
      type: 'telegram_bot', address: 'store_bot', owner_telegram_id: '987654321',
      owner_telegram_username: 'store_owner',
    })
  })
  await test('enabled website franchise accepts only a canonical HTTPS origin', async () => {
    await createPayment({
      franchiseMode: 'enabled', franchiseType: 'website', franchiseAddress: 'https://Shop.Example.TEST/',
      franchiseOwnerTelegramId: '987654321',
    })
    assert.deepEqual(apiRequests[0].body.franchise, {
      type: 'website', address: 'https://shop.example.test', owner_telegram_id: '987654321',
    })
  })
  for (const franchiseAddress of ['http://shop.example.test', 'https://shop.example.test/path', 'https://user@shop.example.test']) {
    await test(`unsafe franchise origin ${franchiseAddress} is rejected before API request`, async () => {
      const html = await createPayment({
        franchiseMode: 'enabled', franchiseType: 'website', franchiseAddress,
        franchiseOwnerTelegramId: '987654321',
      })
      assert.match(html, /alert-danger/)
      assert.equal(apiRequests.length, 0)
    })
  }
  await test('enabled franchise mode requires valid owner and address before API request', async () => {
    const html = await createPayment({ franchiseMode: 'enabled', franchiseType: 'telegram_bot' })
    assert.match(html, /alert-danger/)
    assert.equal(apiRequests.length, 0)
  })
  await test('identity and franchise changes invalidate the cached payment fingerprint', async () => {
    await createPayment()
    await createPayment({ identitySource: 'email' })
    await createPayment({
      franchiseMode: 'enabled', franchiseType: 'telegram_bot', franchiseAddress: 'store_bot',
      franchiseOwnerTelegramId: '987654321',
    })
    assert.equal(apiRequests.length, 3)
  })
  await test('API failures do not put raw response evidence into logs', async () => {
    apiResponseOverride = { message: 'failure with customer@example.test and 123456789' }
    const html = await createPayment()
    assert.match(html, /alert-danger/)
    const logs = (await state()).logs
    assert.ok(logs.length > 0)
    assert.doesNotMatch(JSON.stringify(logs), /customer@example\.test|123456789/)
  })
  await test('reloading an unchanged invoice reuses its UTC cached attempt', async () => {
    const first = await createPayment()
    assert.equal(await createPayment(), first)
    assert.equal(apiRequests.length, 1)
  })
  await test('new amount creates another attempt without overwriting a previous payment', async () => {
    await createPayment()
    const changedInvoice = await state()
    changedInvoice.tables.tblinvoices[0].total = '200.00'
    changedInvoice.tables.tblinvoices[0].balance = '200.00'
    await writeFile(stateFile, JSON.stringify(changedInvoice), 'utf8')
    await createPayment({ amount: '200.00' })
    assert.equal(apiRequests.length, 2)
    const attempts = (await state()).tables.mod_anore_payment_attempts.filter(row => row.payment_id.startsWith('aaaaaaaa-'))
    assert.equal(attempts.length, 2)
    assert.deepEqual(attempts.map(row => row.amount), ['123.45', '200.00'])
    await expectStatus(200, success({ id: attempts[0].payment_id }))
    assert.equal((await state()).credits.length, 1)
  })
  await test('changed shop or customer email cannot reuse an incompatible payment', async () => {
    await createPayment()
    await createPayment({ shopId: '28' })
    await createPayment({ clientdetails: { email: 'other@example.test' } })
    assert.equal(apiRequests.length, 3)
  })
  await test('paid attempt is not offered as a new payment link', async () => {
    await createPayment()
    const value = await state()
    const created = value.tables.mod_anore_payment_attempts.find(row => row.payment_id.startsWith('aaaaaaaa-'))
    await expectStatus(200, success({ id: created.payment_id }))
    const html = await createPayment()
    assert.match(html, /alert-danger/)
    assert.doesNotMatch(html, /href=/)
    assert.equal(apiRequests.length, 1)
  })
  await test('method aliases and mixed case normalize before API request', async () => {
    await createPayment({ methods: '  SBP, CARD , DVNET, xrocket,CRYPTO, crypto-old ' })
    assert.deepEqual(apiRequests[0].body.methods, ['sbp', 'card', 'crypto', 'crypto-old'])
  })
  await test('equivalent method aliases reuse the same cached request', async () => {
    const first = await createPayment({ methods: 'SBP, dvnet, xrocket' })
    const second = await createPayment({ methods: 'sbp,crypto,crypto-old' })
    assert.equal(second, first)
    assert.equal(apiRequests.length, 1)
  })
  await test('invalid payment method is rejected before API request', async () => {
    const html = await createPayment({ methods: 'sbp,wrong-method' })
    assert.match(html, /alert-danger/)
    assert.equal(apiRequests.length, 0)
  })
  for (const status of ['Paid', 'Cancelled', 'Draft']) {
    await test(`${status} invoices cannot create another payment`, async () => {
      await reset(value => { value.tables.tblinvoices[0].status = status })
      const html = await createPayment()
      assert.match(html, /alert-danger/)
      assert.equal(apiRequests.length, 0)
    })
  }
  for (const balance of ['0.00', '-1.00']) {
    await test(`nonpositive invoice balance ${balance} cannot create another payment`, async () => {
      await reset(value => { value.tables.tblinvoices[0].balance = balance })
      const html = await createPayment()
      assert.match(html, /alert-danger/)
      assert.equal(apiRequests.length, 0)
    })
  }
  await test('invoice paid while waiting for lock cannot reuse a stale cached URL', async () => {
    await createPayment()
    const value = await state()
    value.invoiceOnLock = { status: 'Paid', balance: '0.00' }
    await writeFile(stateFile, JSON.stringify(value), 'utf8')
    const html = await createPayment()
    assert.match(html, /alert-danger/)
    assert.doesNotMatch(html, /href=/)
    assert.equal(apiRequests.length, 1)
  })
  await test('invoice amount changed while waiting for lock cannot create a stale payment', async () => {
    await reset(value => { value.invoiceOnLock = { total: '200.00', balance: '200.00' } })
    const html = await createPayment()
    assert.match(html, /alert-danger/)
    assert.equal(apiRequests.length, 0)
  })
  await test('partial invoice credit requires a fresh request for the remaining balance', async () => {
    await reset(value => { value.tables.tblinvoices[0].balance = '100.00' })
    const stale = await createPayment()
    assert.match(stale, /alert-danger/)
    assert.equal(apiRequests.length, 0)
    const current = await createPayment({ amount: '100.00' })
    assert.match(current, /href="https:\/\/pay\.anore\.cc\//)
    assert.equal(apiRequests[0].body.amount, 100)
  })
  await test('invoice API failure prevents creation and leaves attempts unchanged', async () => {
    await reset(value => { value.localApiFailure = true })
    const html = await createPayment()
    assert.match(html, /alert-danger/)
    assert.equal(apiRequests.length, 0)
    assert.equal((await state()).tables.mod_anore_payment_attempts.length, 1)
  })
  await test('invoice API response for a different invoice cannot create a payment', async () => {
    await reset(value => {
      value.localApiResponseOverride = { result: 'success', invoiceid: 999, status: 'Unpaid', balance: '123.45' }
    })
    const html = await createPayment()
    assert.match(html, /alert-danger/)
    assert.equal(apiRequests.length, 0)
  })
  await test('unsafe payment URL is rejected before rendering a button', async () => {
    apiResponseOverride = { id: paymentId, paymentUrl: 'javascript:alert(1)', expiresIn: 14400 }
    const html = await createPayment()
    assert.match(html, /alert-danger/)
    assert.doesNotMatch(html, /href="javascript:/)
  })
  await test('missing webhook secret fails before creating an unprocessable payment', async () => {
    const html = await createPayment({ webhookSecret: '' })
    assert.match(html, /alert-danger/)
    assert.equal(apiRequests.length, 0)
  })
  await test('processing currency conversion is refused before requesting payment', async () => {
    await reset(value => { value.tables.tblcurrencies[0].code = 'USD' })
    const html = await createPayment()
    assert.match(html, /alert-danger/)
    assert.equal(apiRequests.length, 0)
  })
  await test('schema is created for a fresh installation', async () => {
    await reset(value => { delete value.tables.mod_anore_payment_attempts })
    const html = await createPayment()
    assert.match(html, /href="https:\/\/pay\.anore\.cc\//)
    assert.equal((await state()).tables.mod_anore_payment_attempts.length, 1)
  })
  console.log(`WHMCS callback HTTP: ${passed} tests passed (${version.stdout.split('\n')[0].trim()})`)
} finally {
  if (server && server.exitCode === null) {
    server.kill()
    await once(server, 'exit')
  }
  if (apiServer) await new Promise(resolve => apiServer.close(resolve))
  await rm(fixture, { recursive: true, force: true })
}
