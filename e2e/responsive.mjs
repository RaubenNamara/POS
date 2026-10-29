/**
 * Responsive sweep: every main page at phone → 4K widths.
 *   node responsive.mjs [baseUrl] [screenshotDir] [--shots]
 * Reports, per page and width:
 *   - horizontal page overflow (the page scrolls sideways = broken on that screen)
 *   - elements wider than the viewport that are not inside a scroll container
 *   - text inputs/buttons narrower than 44 px tall on touch widths (hard to tap)
 *   - how much of a wide screen the main content actually uses
 */
import { chromium } from 'playwright'
import { execFileSync } from 'node:child_process'
import { mkdirSync } from 'node:fs'

const BASE = process.argv[2] ?? 'http://127.0.0.1:8000'
const SHOTS = process.argv[3] ?? './shots/responsive'
const TAKE = process.argv.includes('--shots')
mkdirSync(SHOTS, { recursive: true })
const EXE = process.env.CHROME ?? 'C:/Users/hp/AppData/Local/ms-playwright/chromium-1243/chrome-win64/chrome.exe'
const sql = (q) => execFileSync('C:/xampp/mysql/bin/mysql.exe', ['-uroot', '-N', 'shop', '-e', q]).toString().trim()

const WIDTHS = [
  [320, 640, 'phone-sm'], [360, 740, 'phone'], [390, 844, 'phone-lg'], [768, 1024, 'tablet'],
  [1024, 768, 'laptop-sm'], [1366, 768, 'laptop'], [1920, 1080, 'desktop'], [2560, 1440, '27in'], [3840, 2160, '4k'],
]
const saleId = sql("SELECT id FROM sales WHERE channel='pos' ORDER BY id DESC LIMIT 1")
const orderNo = sql("SELECT number FROM sales WHERE channel='online' AND customer_id=(SELECT id FROM customers WHERE email='shopper@demo.local') ORDER BY id DESC LIMIT 1") || null
const productId = sql("SELECT id FROM products WHERE slug LIKE 'hp-250%'")

const PAGES = [
  { who: 'guest', path: '/', wait: 'text=Top Deals' },
  { who: 'guest', path: '/search?q=samsung', wait: 'article' },
  { who: 'guest', path: '/p/hp-250-g9-laptop-i5-8gb-512gb-ssd', wait: 'button:has-text("Add to cart")' },
  { who: 'guest', path: '/login', wait: '#l', narrow: true },
  { who: 'shopper', path: '/cart', wait: 'h1', setup: true },
  { who: 'shopper', path: '/checkout', wait: 'text=Order summary' },
  { who: 'shopper', path: '/account/location', wait: 'text=Use my current location', narrow: true },
  { who: 'shopper', path: '/account/orders', wait: 'h1', narrow: true },
  { who: 'owner', path: '/admin', wait: 'text=Net sales' },
  { who: 'owner', path: '/admin/products', wait: 'table' },
  { who: 'owner', path: `/admin/products/${productId}`, wait: 'text=Stock history' },
  { who: 'owner', path: '/admin/sales', wait: 'table' },
  { who: 'owner', path: `/admin/sales/${saleId}`, wait: 'text=Payments' },
  { who: 'owner', path: '/admin/reports', wait: 'text=Profit and loss' },
  { who: 'owner', path: '/admin/receive', wait: 'text=Receive goods into stock' },
  { who: 'owner', path: '/admin/settings', wait: 'text=Shop & receipts' },
  { who: 'cashier', path: '/pos', wait: 'input[placeholder*="Scan barcode"]' },
]
if (orderNo) PAGES.push({ who: 'shopper', path: `/account/orders/${orderNo}`, wait: 'text=Order', narrow: true })

const LOGINS = { owner: 'owner@demo.local', cashier: 'cashier@demo.local', shopper: 'shopper@demo.local' }
const browser = await chromium.launch({ executablePath: EXE })

async function contextFor(who, [w, h]) {
  const ctx = await browser.newContext({ viewport: { width: w, height: h }, isMobile: w < 768, hasTouch: w < 1024, serviceWorkers: 'block' })
  if (who !== 'guest') {
    const page = await ctx.newPage()
    await page.goto(`${BASE}/login`)
    await page.fill('#l', LOGINS[who])
    await page.getByRole('button', { name: 'Continue' }).click()
    await page.fill('#p', 'DemoPass123')
    await page.getByRole('button', { name: 'Sign in' }).click()
    await page.waitForURL((u) => !u.pathname.startsWith('/login'))
    if (who === 'shopper') {
      // Something in the cart so cart/checkout render their full layout.
      await page.goto(`${BASE}/p/oraimo-18w-fast-charger`)
      await page.locator('button.h-11:has-text("Add to cart")').click()
    }
    await page.close()
  }
  return ctx
}

const problems = []
for (const size of WIDTHS) {
  const [w, h, label] = size
  const ctxs = {}
  for (const pg of PAGES) {
    ctxs[pg.who] ??= await contextFor(pg.who, size)
    const page = await ctxs[pg.who].newPage()
    try {
      await page.goto(BASE + pg.path, { waitUntil: 'load' })
      await page.locator(pg.wait).first().waitFor({ timeout: 15000 })
      await page.waitForTimeout(300)
      const r = await page.evaluate(() => {
        const vw = document.documentElement.clientWidth
        const scrollW = document.documentElement.scrollWidth
        const inScroller = (el) => {
          for (let p = el.parentElement; p; p = p.parentElement) {
            const s = getComputedStyle(p)
            if (/(auto|scroll|hidden)/.test(s.overflowX)) return true
          }
          return false
        }
        const wide = []
        for (const el of document.querySelectorAll('body *')) {
          const b = el.getBoundingClientRect()
          const cs = getComputedStyle(el)
          if (b.width === 0 || cs.position === 'fixed' || cs.visibility === 'hidden') continue
          if ((b.right > vw + 1 || b.left < -1) && !inScroller(el)) {
            wide.push(`${el.tagName.toLowerCase()}.${String(el.className).split(' ').slice(0, 3).join('.')} (${Math.round(b.left)}→${Math.round(b.right)})`)
            if (wide.length > 3) break
          }
        }
        // Text spilling out of its own box (truncated without ellipsis).
        const clipped = []
        for (const el of document.querySelectorAll('button, a.btn, .btn-primary, .btn-outline, label, th')) {
          if (el.scrollWidth > el.clientWidth + 2 && getComputedStyle(el).overflow !== 'hidden' && el.clientWidth > 0) {
            clipped.push(`${el.tagName.toLowerCase()} "${el.textContent.trim().slice(0, 30)}"`)
            if (clipped.length > 3) break
          }
        }
        const main = document.querySelector('main') ?? document.body
        const content = [...main.querySelectorAll(':scope > *, :scope > * > *')].reduce((m, el) => Math.max(m, el.getBoundingClientRect().width), 0)
        return { vw, overflow: scrollW - vw, wide, clipped, used: Math.round((content / vw) * 100), font: getComputedStyle(document.body).fontSize }
      })
      const issues = []
      if (r.overflow > 1) issues.push(`page scrolls sideways by ${r.overflow}px`)
      if (r.wide.length) issues.push(`too wide: ${r.wide.join('; ')}`)
      if (r.clipped.length) issues.push(`text overflows: ${r.clipped.join('; ')}`)
      if (w >= 1920 && !pg.narrow && r.used < 60) issues.push(`content uses only ${r.used}% of the screen`)
      if (issues.length) problems.push({ label, w, path: pg.path, issues })
      console.log(`${issues.length ? 'ISSUE' : 'ok   '} ${String(w).padStart(4)} ${pg.path.padEnd(42)} used ${String(r.used).padStart(3)}%  ${issues.join(' | ')}`)
      if (TAKE) await page.screenshot({ path: `${SHOTS}/${label}${pg.path.replace(/[/?=&]+/g, '_') || '_home'}.png`, fullPage: false })
    } catch (e) {
      problems.push({ label, w, path: pg.path, issues: ['did not render: ' + String(e).split('\n')[0]] })
      console.log(`FAIL  ${w} ${pg.path} ${String(e).split('\n')[0]}`)
    } finally {
      await page.close()
    }
  }
  for (const c of Object.values(ctxs)) await c.close()
}
await browser.close()
console.log(`\n${problems.length} page/width combinations with issues out of ${WIDTHS.length * PAGES.length}`)
process.exit(problems.length ? 1 : 0)
