import { chromium } from 'playwright'
const D = process.argv[2]
const b = await chromium.launch({ executablePath: 'C:/Users/hp/AppData/Local/ms-playwright/chromium-1243/chrome-win64/chrome.exe' })
async function as(email) {
  const p = await (await b.newContext({ viewport: { width: 1440, height: 900 } })).newPage()
  await p.goto('http://localhost:8000/login'); await p.fill('#l', email); await p.getByRole('button', { name: 'Continue' }).click()
  await p.fill('#p', 'DemoPass123'); await p.getByRole('button', { name: 'Sign in' }).click(); await p.waitForURL((u) => !u.pathname.startsWith('/login'))
  if (!p.url().includes('/admin')) await p.goto('http://localhost:8000/admin/sales')
  await p.waitForTimeout(800)
  return p
}
const side = (p) => p.locator('aside nav a').allInnerTexts()
const o = await as('owner@demo.local')
console.log('owner sidebar:', (await side(o)).join(' | '))
await o.screenshot({ path: `${D}/nav-dash.png` })
let bad = 0
for (const path of ['/admin/sales','/admin/orders','/admin/shifts','/admin/offline','/admin/customers','/admin/products','/admin/categories','/admin/ads','/admin/inventory','/admin/inventory/adjust','/admin/receive','/admin/purchase-orders','/admin/goods-receipts','/admin/suppliers','/admin/reports','/admin/expenses','/admin/settings','/admin/users','/admin/audit']) {
  await o.goto('http://localhost:8000' + path); await o.waitForTimeout(500)
  const active = await o.locator('aside nav a.on').allInnerTexts()
  const tab = await o.locator('main nav a.on').allInnerTexts()
  const ok = active.length === 1
  if (!ok) bad++
  console.log(ok ? 'ok ' : 'BAD', path.padEnd(26), '| sidebar:', active.join(','), '| tab:', tab.join(',') || '(none)')
}
await o.goto('http://localhost:8000/admin/sales'); await o.waitForTimeout(800); await o.screenshot({ path: `${D}/nav-sales.png` })
await o.locator('main table a, main tbody tr').first().click().catch(() => {}); await o.waitForTimeout(800)
console.log('sale detail:', o.url(), '| sidebar:', (await o.locator('aside nav a.on').allInnerTexts()).join(','), '| tabs shown:', await o.locator('main nav a.tab').count())
const c = await as('cashier@demo.local')
console.log('cashier sidebar:', (await side(c)).join(' | '), '| sales tabs:', (await c.locator('main nav a.tab').allInnerTexts()).join(','))
console.log('pages with problems:', bad)
await b.close()
