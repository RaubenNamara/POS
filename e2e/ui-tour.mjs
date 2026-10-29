// Screenshot tour of many pages: node ui-tour.mjs <dir> [prefix]
import { chromium } from 'playwright'
const D = process.argv[2], P = process.argv[3] ?? 'a'
const B = 'http://localhost:8000'
const b = await chromium.launch({ executablePath: 'C:/Users/hp/AppData/Local/ms-playwright/chromium-1243/chrome-win64/chrome.exe' })
const shot = async (p, name) => { await p.waitForTimeout(1200); await p.screenshot({ path: `${D}/${P}-${name}.png` }) }
const guest = await (await b.newContext({ viewport: { width: 1366, height: 860 } })).newPage()
for (const [n, u] of [['catalog', '/c/computing'], ['product', '/p/oraimo-18w-fast-charger'], ['help', '/help']]) { await guest.goto(B + u); await shot(guest, n) }
await guest.goto(B + '/p/oraimo-18w-fast-charger'); await guest.locator('button.h-11:has-text("Add to cart")').click().catch(() => {}); await guest.goto(B + '/cart'); await shot(guest, 'cart')
const o = await (await b.newContext({ viewport: { width: 1366, height: 860 } })).newPage()
await o.goto(B + '/login'); await o.fill('#l', 'owner@demo.local'); await o.getByRole('button', { name: 'Continue' }).click()
await o.fill('#p', 'DemoPass123'); await o.getByRole('button', { name: 'Sign in' }).click(); await o.waitForURL(/admin/)
for (const [n, u] of [['pedit', '/admin/products/5'], ['reports', '/admin/reports'], ['customers', '/admin/customers'], ['receive', '/admin/receive'], ['expenses', '/admin/expenses'], ['staff', '/admin/users'], ['settings', '/admin/settings'], ['orders', '/admin/orders'], ['sale', '/admin/sales/47'], ['stock', '/admin/inventory'], ['adjust', '/admin/inventory/adjust'], ['suppliers', '/admin/suppliers'], ['ads', '/admin/ads'], ['pos', '/pos']]) { await o.goto(B + u); await shot(o, n) }
await o.goto(B + '/account/orders'); await shot(o, 'myorders')
await b.close()
