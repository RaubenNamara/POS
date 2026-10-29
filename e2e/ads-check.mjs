// Adverts end-to-end: node ads-check.mjs [base] [shotsDir]
// Creates test adverts through the Adverts page, checks the home page, then deletes them.
import { chromium } from 'playwright'
import { deflateSync } from 'node:zlib'
import { mkdirSync, writeFileSync } from 'node:fs'

const BASE = process.argv[2] ?? 'http://127.0.0.1:8000'
const DIR = process.argv[3] ?? './shots/ads'
mkdirSync(DIR, { recursive: true })
const results = []
const check = (n, ok, d = '') => { results.push(ok); console.log(`${ok ? 'PASS' : 'FAIL'}  ${n}${d ? `  (${d})` : ''}`) }

// Plain colour banner PNGs (no image library needed).
const crcT = new Uint32Array(256).map((_, n) => { let c = n; for (let k = 0; k < 8; k++) c = c & 1 ? 0xedb88320 ^ (c >>> 1) : c >>> 1; return c >>> 0 })
const crc = (b) => { let c = 0xffffffff; for (const x of b) c = crcT[(c ^ x) & 255] ^ (c >>> 8); return (c ^ 0xffffffff) >>> 0 }
const chunk = (t, d) => { const l = Buffer.alloc(4); l.writeUInt32BE(d.length); const td = Buffer.concat([Buffer.from(t), d]); const c = Buffer.alloc(4); c.writeUInt32BE(crc(td)); return Buffer.concat([l, td, c]) }
function png(w, h, [r, g, b]) {
  const row = Buffer.alloc(w * 3 + 1)
  for (let x = 0; x < w; x++) { row[1 + x * 3] = r; row[2 + x * 3] = g; row[3 + x * 3] = b }
  const raw = Buffer.concat(Array.from({ length: h }, () => row))
  const ih = Buffer.alloc(13); ih.writeUInt32BE(w, 0); ih.writeUInt32BE(h, 4); ih[8] = 8; ih[9] = 2
  return Buffer.concat([Buffer.from([137, 80, 78, 71, 13, 10, 26, 10]), chunk('IHDR', ih), chunk('IDAT', deflateSync(raw)), chunk('IEND', Buffer.alloc(0))])
}
const files = { a: [234, 88, 12], b: [21, 128, 61], c: [37, 99, 235], s: [109, 40, 217] }
for (const [k, col] of Object.entries(files)) writeFileSync(`${DIR}/test-${k}.png`, png(k === 's' ? 1500 : 1500, k === 's' ? 300 : 500, col))

const browser = await chromium.launch({ executablePath: 'C:/Users/hp/AppData/Local/ms-playwright/chromium-1243/chrome-win64/chrome.exe' })
const admin = await (await browser.newContext({ viewport: { width: 1400, height: 950 } })).newPage()
await admin.goto(BASE + '/login'); await admin.fill('#l', 'manager@demo.local'); await admin.getByRole('button', { name: 'Continue' }).click(); await admin.fill('#p', 'DemoPass123')
await admin.getByRole('button', { name: 'Sign in' }).click(); await admin.waitForURL(/admin/)
await admin.goto(BASE + '/admin/ads'); await admin.getByText('Adverts on the home page').waitFor()

async function addAd(title, file, placement, link) {
  await admin.getByRole('button', { name: 'New advert' }).click()
  if (placement === 'strip') await admin.locator('label:has-text("Wide banner")').click()
  await admin.locator('[role=dialog] input[type=file]').first().setInputFiles(`${DIR}/${file}`)
  await admin.locator('[role=dialog] img').first().waitFor()
  await admin.locator('[role=dialog] input[maxlength="120"]').fill(title)
  await admin.locator('[role=dialog] input[placeholder*="/c/computing"]').fill(link)
  await admin.getByRole('button', { name: 'Save advert' }).click()
  await admin.getByText('Advert added').first().waitFor()
  await admin.locator('[role=dialog]').waitFor({ state: 'detached' })
}
try {
  await addAd('E2E test advert one', 'test-a.png', 'hero', '/c/computing')
  await addAd('E2E test advert two', 'test-b.png', 'hero', '/search?on_sale=1')
  await addAd('E2E test advert three', 'test-c.png', 'hero', '/c/phones-tablets')
  await addAd('E2E test wide banner', 'test-s.png', 'strip', '/search?type=bundle')
  await admin.waitForTimeout(800)
  check('manager adds adverts with pictures', (await admin.getByText('Showing now').count()) === 4)
  await admin.screenshot({ path: `${DIR}/admin-ads.png`, fullPage: true })

  const shop = await (await browser.newContext({ viewport: { width: 1400, height: 950 } })).newPage()
  await shop.goto(BASE + '/')
  const slider = shop.locator('section[aria-roledescription=carousel]')
  await slider.waitFor()
  await shop.mouse.move(5, 5)
  check('home page shows the advert slider instead of the plain banner', !(await shop.getByText('Genuine products.').isVisible()))
  const idx = () => slider.locator('div.flex').first().evaluate((el) => Number(el.style.transform.match(/-?(\d+)00%/)?.[1] ?? 0))
  const seq = [await idx()]
  for (let i = 0; i < 4; i++) { await shop.waitForTimeout(5150); seq.push(await idx()) }
  check('slider changes advert every 5 seconds, always forward', JSON.stringify(seq) === '[0,1,2,0,1]', seq.join(' → '))
  await shop.screenshot({ path: `${DIR}/home-slider.png` })

  const strip = shop.locator('a[aria-label="E2E test wide banner"]')
  check('wide banner appears between sections', await strip.isVisible())

  // Top Deals: sample the scroll position; it may only jump back instantly by one full copy, never glide back.
  const deals = shop.locator('section', { hasText: 'Top Deals' }).first().locator('div.overflow-x-auto').first()
  await deals.scrollIntoViewIfNeeded(); await shop.mouse.move(5, 5)
  const samples = []
  for (let t = 0; t < 140; t++) { samples.push(await deals.evaluate((el) => el.scrollLeft)); await shop.waitForTimeout(100) }
  const setWidth = await deals.evaluate((el) => (el.scrollWidth + 12) / 3)
  const backward = samples.slice(1).map((v, i) => samples[i] - v).filter((d) => d > 1)
  const glides = backward.filter((d) => Math.abs(d - setWidth) > 30)
  check('Top Deals moves forward only (wraps invisibly)', samples.at(-1) !== samples[0] && glides.length === 0, `${backward.length} invisible wrap(s), ${glides.length} backward glides`)

  await strip.click()
  await shop.waitForURL(/type=bundle/)
  await shop.waitForTimeout(800)
  await admin.reload(); await admin.getByText('E2E test wide banner').waitFor()
  const card = admin.locator('article', { hasText: 'E2E test wide banner' })
  check('clicks on an advert are counted', /1 click\b/.test(await card.innerText()))
} finally {
  // Clean up: never leave test adverts on the shop.
  admin.on('dialog', (d) => d.accept())
  for (let i = 0; i < 6; i++) {
    const card = admin.locator('article', { hasText: 'E2E test' }).first()
    if (!(await card.count())) break
    await card.getByRole('button', { name: 'Delete' }).click()
    await admin.getByText('Advert deleted').first().waitFor()
    await admin.waitForTimeout(300)
  }
  console.log('test adverts left:', await admin.locator('article', { hasText: 'E2E test' }).count())
  await browser.close()
}
console.log(`\n${results.filter(Boolean).length}/${results.length} checks passed`)
