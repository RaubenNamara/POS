import { chromium } from 'playwright'
const D = process.argv[2]
const B = 'http://127.0.0.1:8000'
const b = await chromium.launch({ executablePath: 'C:/Users/hp/AppData/Local/ms-playwright/chromium-1243/chrome-win64/chrome.exe' })
const owner = await (await b.newContext()).newPage()
const login = async (p, who) => { await p.goto(B + '/login'); await p.fill('#l', who); await p.getByRole('button', { name: 'Continue' }).click(); await p.fill('#p', 'DemoPass123'); await p.getByRole('button', { name: 'Sign in' }).click(); await p.waitForURL((u) => !u.pathname.startsWith('/login')) }
await login(owner, 'owner@demo.local')
const setKeys = (body) => owner.evaluate(async (body) => {
  await fetch('/sanctum/csrf-cookie')
  const x = decodeURIComponent(document.cookie.match(/XSRF-TOKEN=([^;]+)/)[1])
  const r = await fetch('/api/admin/settings', { method: 'PUT', headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': x }, body: JSON.stringify(body) })
  return (await r.json()).integrations?.flutterwave
}, body)
console.log('flutterwave connected:', await setKeys({ flutterwave_public_key: 'FLWPUBK_TEST-placeholder', flutterwave_secret_key: 'FLWSECK_TEST-placeholder' }))
try {
  for (const [label, geo] of [['kampala', { latitude: 0.3352, longitude: 32.5966 }], ['gulu', { latitude: 2.7724, longitude: 32.2881 }]]) {
    const ctx = await b.newContext({ viewport: { width: 1366, height: 1000 }, geolocation: geo, permissions: ['geolocation'] })
    const p = await ctx.newPage()
    await login(p, 'shopper@demo.local')
    await p.goto(B + '/p/oraimo-18w-fast-charger'); await p.locator('button.h-11:has-text("Add to cart")').click()
    await p.goto(B + '/checkout')
    await p.getByText('Order summary').waitFor()
    if (await p.getByRole('button', { name: 'Change' }).isVisible()) await p.getByRole('button', { name: 'Change' }).click()
    await p.getByRole('button', { name: 'Use my current location' }).click()
    await p.getByRole('button', { name: 'Use this location' }).click()
    await p.waitForTimeout(1500)
    const opts = await p.locator('label:has(input[type=radio][value])').evaluateAll((ls) => ls.filter((l) => l.querySelector('input[name], input[value="online"], input[value="cod"]')).map((l) => `${l.querySelector('b')?.textContent}: ${l.querySelector('input').disabled ? 'NOT available' : 'available'}`))
    console.log(label, '->', opts.filter((o) => /Pay/.test(o)).join(' | '))
    await p.locator('text=Payment').first().scrollIntoViewIfNeeded()
    await p.screenshot({ path: `${D}/two-options-${label}.png` })
    await ctx.close()
  }
} finally {
  console.log('placeholder keys removed:', (await setKeys({ flutterwave_public_key: '', clear_secrets: ['flutterwave_secret_key'] })) === false)
  await b.close()
}
