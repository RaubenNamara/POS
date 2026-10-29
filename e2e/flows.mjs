/**
 * End-to-end acceptance run against a live server (default http://127.0.0.1:8000) seeded with DemoSeeder.
 *   node flows.mjs [baseUrl] [screenshotDir]
 * Uses a low-end phone profile (4x CPU slowdown, slow 4G) for the storefront and records Core Web Vitals.
 */
import { chromium } from 'playwright'
import { execFileSync } from 'node:child_process'
import { mkdirSync } from 'node:fs'

const BASE = process.argv[2] ?? 'http://127.0.0.1:8000'
const SHOTS = process.argv[3] ?? './shots'
mkdirSync(SHOTS, { recursive: true })
const EXE = process.env.CHROME ?? 'C:/Users/hp/AppData/Local/ms-playwright/chromium-1243/chrome-win64/chrome.exe'
const sql = (q) => execFileSync('C:/xampp/mysql/bin/mysql.exe', ['-uroot', '-N', 'shop', '-e', q]).toString().trim()

const results = []
const check = (name, ok, detail = '') => {
  results.push({ name, ok, detail })
  console.log(`${ok ? 'PASS' : 'FAIL'}  ${name}${detail ? `  (${detail})` : ''}`)
}

async function throttle(page, profile) {
  const cdp = await page.context().newCDPSession(page)
  if (profile === 'slow4g') {
    await cdp.send('Network.enable')
    // Lighthouse "slow 4G": 150 ms RTT, ~1.6 Mbps down, 750 kbps up.
    await cdp.send('Network.emulateNetworkConditions', { offline: false, latency: 150, downloadThroughput: (1.6 * 1024 * 1024) / 8, uploadThroughput: (750 * 1024) / 8 })
    await cdp.send('Emulation.setCPUThrottlingRate', { rate: 4 })
  }
  return cdp
}

/** LCP and CLS via PerformanceObserver (buffered), read after the page settles. */
async function vitals(page) {
  return page.evaluate(() => new Promise((resolve) => {
    let lcp = 0
    let cls = 0
    new PerformanceObserver((l) => l.getEntries().forEach((e) => (lcp = e.startTime))).observe({ type: 'largest-contentful-paint', buffered: true })
    new PerformanceObserver((l) => l.getEntries().forEach((e) => { if (!e.hadRecentInput) cls += e.value })).observe({ type: 'layout-shift', buffered: true })
    const nav = performance.getEntriesByType('navigation')[0]
    setTimeout(() => resolve({ lcp: Math.round(lcp), cls: +cls.toFixed(3), ttfb: Math.round(nav.responseStart), transferKB: Math.round(performance.getEntriesByType('resource').reduce((s, r) => s + (r.transferSize || 0), nav.transferSize || 0) / 1024) }), 1500)
  }))
}

/** Rough INP proxy: time from a click to the next painted frame. */
async function clickToPaint(page, locator) {
  await page.evaluate(() => (window.__t = 0))
  const el = page.locator(locator).first()
  await el.evaluate((node) => node.addEventListener('click', () => { const s = performance.now(); requestAnimationFrame(() => setTimeout(() => (window.__t = performance.now() - s), 0)) }, { once: true, capture: true }))
  await el.click()
  await page.waitForFunction(() => window.__t > 0)
  return Math.round(await page.evaluate(() => window.__t))
}

const browser = await chromium.launch({ executablePath: EXE })
try {
const stamp = Date.now()

// ------------------------------------------------------------------ shopper (low-end phone)
{
  const ctx = await browser.newContext({ viewport: { width: 360, height: 740 }, deviceScaleFactor: 2, isMobile: true, hasTouch: true, serviceWorkers: 'block', geolocation: { latitude: 0.3352, longitude: 32.5966 }, permissions: ['geolocation'] })
  const page = await ctx.newPage()
  await throttle(page, 'slow4g')
  await page.goto(BASE + '/', { waitUntil: 'load' })
  await page.getByText('Top Deals').first().waitFor({ timeout: 30000 })
  const v = await vitals(page)
  check('Home LCP ≤ 2500 ms (slow 4G, 4x CPU)', v.lcp <= 2500, `LCP ${v.lcp} ms, TTFB ${v.ttfb} ms, ${v.transferKB} KB transferred`)
  check('Home CLS ≤ 0.1', v.cls <= 0.1, `CLS ${v.cls}`)
  await page.screenshot({ path: `${SHOTS}/1-home-mobile.png`, fullPage: false })

  const searchBox = page.locator('input[type=search]:visible')
  await searchBox.fill('laptop')
  await searchBox.press('Enter')
  await page.waitForURL(/search\?q=laptop/)
  await page.getByRole('heading', { name: /Results for "laptop"/ }).waitFor()
  await page.locator('article').first().waitFor()
  const cards = await page.locator('article').count()
  check('Search "laptop" returns products from DB', cards >= 2, `${cards} cards`)
  await page.screenshot({ path: `${SHOTS}/2-search-mobile.png` })

  await page.locator('article a').filter({ hasText: 'HP 250 G9' }).first().click()
  await page.waitForURL(/\/p\//)
  await page.locator('button.h-11:has-text("Add to cart")').waitFor()
  const pv = await vitals(page)
  check('Product page CLS ≤ 0.1', pv.cls <= 0.1, `CLS ${pv.cls}`)
  const inp = await clickToPaint(page, 'button.h-11:has-text("Add to cart")')
  check('Add-to-cart click → paint ≤ 200 ms (4x CPU)', inp <= 200, `${inp} ms`)
  await page.screenshot({ path: `${SHOTS}/3-product-mobile.png` })

  await page.goto(BASE + '/cart')
  await page.getByRole('link', { name: /Checkout/ }).click()
  await page.waitForURL(/login/)
  const email = `e2e.shopper.${stamp}@example.com`
  await page.fill('#l', email)
  await page.getByRole('button', { name: 'Continue' }).click()
  await page.fill('#n', 'E2E Shopper')
  await page.fill('#ph', '0700' + String(stamp).slice(-6))
  await page.fill('#p', 'Shopper123!')
  await page.fill('#pc', 'Shopper123!')
  await page.getByRole('button', { name: 'Create account' }).click()
  // New customers set their delivery location once (decides pay-on-delivery eligibility).
  await page.waitForURL(/account\/location/)
  await page.getByRole('button', { name: 'Use my current location' }).click()
  await page.fill('#addr', 'Plot 12, Kira Road, Kampala')
  await page.getByRole('button', { name: 'Save location' }).click()
  await page.getByRole('button', { name: 'Save & continue' }).click()
  await page.waitForURL(/checkout/)
  await page.getByText('Order summary').waitFor()
  await page.locator('text=Total').last().waitFor()
  await page.getByRole('button', { name: 'Place order' }).click()
  await page.waitForURL(/account\/orders\/W/, { timeout: 30000 })
  const orderNo = page.url().match(/orders\/(W\d+)/)?.[1]
  check('Online order placed and tracked', !!orderNo, orderNo)
  await page.screenshot({ path: `${SHOTS}/4-order-mobile.png` })
  results.orderNo = orderNo
  await ctx.close()
}

// ------------------------------------------------------------------ cashier POS (tablet)
{
  const ctx = await browser.newContext({ viewport: { width: 1024, height: 700 }, serviceWorkers: 'allow' })
  const page = await ctx.newPage()
  await page.goto(BASE + '/login')
  await page.fill('#l', 'cashier@demo.local')
  await page.getByRole('button', { name: 'Continue' }).click()
  await page.fill('#p', 'DemoPass123')
  await page.getByRole('button', { name: 'Sign in' }).click()
  await page.waitForURL(/\/pos/)
  await page.getByPlaceholder(/Scan barcode/).waitFor()

  // Close any shift left from a previous run, then open a fresh one.
  if (await page.getByRole('button', { name: 'Shift', exact: true }).isVisible()) {
    await page.getByRole('button', { name: 'Shift', exact: true }).click()
    await page.fill('#cc', '0')
    page.once('dialog', (d) => d.accept())
    await page.getByRole('button', { name: 'Close shift' }).click()
    await page.getByRole('button', { name: 'Open shift' }).waitFor()
  }
  await page.getByRole('button', { name: 'Open shift' }).click()
  await page.fill('#fl', '50000')
  await page.locator('form').getByRole('button', { name: 'Open shift' }).click()
  await page.getByRole('button', { name: 'Shift', exact: true }).waitFor()

  const barcode = sql("SELECT barcode FROM products WHERE name='Oraimo 18W Fast Charger'")
  const before = Number(sql("SELECT total_stock FROM products WHERE name='Oraimo 18W Fast Charger'"))
  const search = page.getByPlaceholder(/Scan barcode/)
  const t0 = Date.now()
  await search.fill(barcode)
  await search.press('Enter')
  await page.getByText('Added Oraimo 18W Fast Charger').waitFor()
  check('Barcode scan adds item', true, `${Date.now() - t0} ms incl. typing`)
  await search.fill('flash')
  const addMs = await clickToPaint(page, 'section button:has-text("SanDisk 64GB")')
  check('POS product tap → paint ≤ 200 ms', addMs <= 200, `${addMs} ms`)
  await page.screenshot({ path: `${SHOTS}/5-pos-cart.png` })

  await page.keyboard.press('F9')
  await page.getByText('Total to pay').waitFor()
  await page.locator('.btn-outline.btn-sm').filter({ hasText: /^\d/ }).first().click() // quick cash round-up
  await page.getByRole('button', { name: 'Complete sale' }).click()
  await page.getByText('Sale complete').waitFor({ timeout: 15000 })
  await page.screenshot({ path: `${SHOTS}/6-pos-receipt.png` })
  const after = Number(sql("SELECT total_stock FROM products WHERE name='Oraimo 18W Fast Charger'"))
  check('Confirmed sale deducted stock on the server', after === before - 1, `${before} → ${after}`)
  await page.getByRole('button', { name: /New sale/ }).click()

  // ---- offline sale, survives reload, syncs once
  const lotionBefore = Number(sql("SELECT total_stock FROM products WHERE name='Nivea Body Lotion 400ml'"))
  await ctx.setOffline(true)
  await search.fill('nivea')
  await page.locator('section button:has-text("Nivea")').first().click()
  await page.keyboard.press('F9')
  await page.getByRole('button', { name: 'Complete sale' }).click()
  await page.getByText('No connection.').waitFor({ timeout: 25000 })
  await page.getByRole('button', { name: /Save offline sale/ }).click()
  await page.getByText(/Provisional receipt/).waitFor()
  await page.screenshot({ path: `${SHOTS}/7-pos-offline-receipt.png` })
  await page.getByRole('button', { name: /New sale/ }).click()
  check('Offline sale queued, not yet on server', Number(sql("SELECT total_stock FROM products WHERE name='Nivea Body Lotion 400ml'")) === lotionBefore)

  await page.reload() // still offline: app shell from the service worker, queue from IndexedDB
  await page.getByPlaceholder(/Scan barcode/).waitFor({ timeout: 20000 })
  const badge = await page.locator('header button[title="Sync status"]').innerText()
  check('Offline queue survives a reload', /Offline\s*1/.test(badge.replace(/\n/g, ' ')), badge.replace(/\n/g, ' '))

  await ctx.setOffline(false)
  await page.evaluate(() => dispatchEvent(new Event('online')))
  await page.waitForFunction(() => !document.querySelector('header button[title="Sync status"]')?.textContent?.match(/\d/), null, { timeout: 30000 })
  const lotionAfter = Number(sql("SELECT total_stock FROM products WHERE name='Nivea Body Lotion 400ml'"))
  check('Offline sale synced exactly once', lotionAfter === lotionBefore - 1, `${lotionBefore} → ${lotionAfter}`)
  await ctx.close()
}

// ------------------------------------------------------------------ owner back office (desktop)
{
  const ctx = await browser.newContext({ viewport: { width: 1366, height: 800 } })
  const page = await ctx.newPage()
  await page.goto(BASE + '/login')
  await page.fill('#l', 'owner@demo.local')
  await page.getByRole('button', { name: 'Continue' }).click()
  await page.fill('#p', 'DemoPass123')
  await page.getByRole('button', { name: 'Sign in' }).click()
  await page.waitForURL(/\/admin$/)
  await page.getByText('Inventory capital (at cost)').waitFor()
  const sales = await page.locator('text=/\\d+ sales/').first().innerText()
  check('Dashboard shows today\'s live sales', /[1-9]\d* sales/.test(sales), sales)
  await page.screenshot({ path: `${SHOTS}/8-dashboard.png`, fullPage: true })

  const orderId = sql(`SELECT id FROM sales WHERE number='${results.orderNo}'`)
  await page.goto(`${BASE}/admin/sales/${orderId}`)
  await page.getByText('Order progress').waitFor()
  await page.getByRole('button', { name: 'Record payment' }).click()
  await page.locator('input[inputmode="decimal"]').fill(sql(`SELECT balance_due FROM sales WHERE id=${orderId}`))
  // Paid on delivery by mobile money (cash would need the owner's own open till shift).
  await page.locator('[role=dialog] select').selectOption({ label: 'MTN Mobile Money' })
  await page.locator('[role=dialog] input:not([inputmode])').fill('MP' + stamp)
  await page.getByRole('button', { name: 'Confirm money received' }).click()
  await page.getByText('Payment recorded').waitFor()
  await page.getByRole('button', { name: 'delivered' }).click()
  await page.getByText('Order delivered').waitFor()
  check('Online order paid + delivered → completed revenue', sql(`SELECT CONCAT(status,'/',payment_status) FROM sales WHERE id=${orderId}`) === 'completed/paid')

  await page.goto(`${BASE}/admin/reports`)
  await page.getByText('Profit and loss').waitFor()
  await page.screenshot({ path: `${SHOTS}/9-profit-loss.png`, fullPage: true })
  await page.locator('select').first().selectOption('reconcile')
  await page.getByText(/tie out|≠|inconsistent/).first().waitFor()
  check('Reconciliation check passes after the whole run', await page.getByText('tie out').isVisible())
  await ctx.close()
}

} catch (e) {
  console.log('ERROR', String(e).split(String.fromCharCode(10))[0])
  for (const c of browser.contexts()) for (const pg of c.pages()) {
    await pg.screenshot({ path: `${SHOTS}/FAIL.png` }).catch(() => {})
    console.log('URL', pg.url())
    console.log('ALERTS', (await pg.locator('[role=alert], .text-bad').allInnerTexts().catch(() => [])).join(' | '))
  }
  results.push({ name: 'run aborted', ok: false })
}
await browser.close()
const failed = results.filter((r) => !r.ok)
console.log(`\n${results.length - failed.length}/${results.length} checks passed`)
process.exit(failed.length ? 1 : 0)
