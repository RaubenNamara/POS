import { chromium, devices } from 'playwright'
const D = process.argv[2]
const B = 'http://127.0.0.1:8000'
const b = await chromium.launch({ executablePath: 'C:/Users/hp/AppData/Local/ms-playwright/chromium-1243/chrome-win64/chrome.exe' })
const owner = await (await b.newContext()).newPage()
await owner.goto(B + '/login'); await owner.fill('#l', 'owner@demo.local'); await owner.getByRole('button', { name: 'Continue' }).click(); await owner.fill('#p', 'DemoPass123')
await owner.getByRole('button', { name: 'Sign in' }).click(); await owner.waitForURL(/admin/)
const setLinks = (body) => owner.evaluate(async (body) => {
  await fetch('/sanctum/csrf-cookie')
  const x = decodeURIComponent(document.cookie.match(/XSRF-TOKEN=([^;]+)/)[1])
  const r = await fetch('/api/admin/settings', { method: 'PUT', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': x }, body: JSON.stringify(body) })
  return r.status
}, body)
const footer = async (ctxOpts, name) => {
  const ctx = await b.newContext(ctxOpts); const p = await ctx.newPage()
  await p.goto(B + '/'); await p.getByText('Top Deals').first().waitFor()
  await p.evaluate(() => scrollTo(0, document.body.scrollHeight)); await p.waitForTimeout(500)
  const r = { play: await p.locator('a[aria-label="Get it on Google Play"]').count(), apple: await p.locator('a[aria-label="Download on the App Store"]').count(),
    install: await p.getByRole('button', { name: /Install the app/ }).count(),
    playHref: await p.locator('a[aria-label="Get it on Google Play"]').getAttribute('href').catch(() => null) }
  if (name) await p.locator('footer').screenshot({ path: `${D}/${name}.png` })
  return { r, p, ctx }
}
try {
  console.log('bad link refused:', await setLinks({ play_store_url: 'https://example.com/app' }) === 422)
  const none = await footer({ viewport: { width: 1366, height: 900 } })
  console.log('no links ->', JSON.stringify(none.r)); await none.ctx.close()
  console.log('save links:', await setLinks({ play_store_url: 'https://play.google.com/store/apps/details?id=ug.example.shop', app_store_url: 'https://apps.apple.com/ug/app/example-shop/id000000000' }))
  const withLinks = await footer({ viewport: { width: 1366, height: 900 } }, 'footer-badges')
  console.log('links ->', JSON.stringify(withLinks.r)); await withLinks.ctx.close()
  const iphone = await footer({ ...devices['iPhone 13'] }, 'footer-iphone')
  console.log('iphone ->', JSON.stringify(iphone.r))
  if (iphone.r.install) {
    await iphone.p.getByRole('button', { name: /Install the app/ }).click()
    console.log('iphone help shown:', await iphone.p.getByText('Add to Home Screen').first().isVisible())
  }
  await iphone.ctx.close()
} finally {
  console.log('example links removed:', await setLinks({ play_store_url: '', app_store_url: '' }))
  await b.close()
}
