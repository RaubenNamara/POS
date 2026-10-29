/**
 * Sign-up → location → checkout payment options, in a real browser with a faked GPS position.
 *   node location-flow.mjs [baseUrl] [screenshotDir]
 * Local server has no Google Maps key, so zones come from COD_FALLBACK_RADIUS_KM (dev only).
 */
import { chromium } from 'playwright'
import { mkdirSync } from 'node:fs'

const BASE = process.argv[2] ?? 'http://127.0.0.1:8000'
const SHOTS = process.argv[3] ?? './shots'
mkdirSync(SHOTS, { recursive: true })
const EXE = process.env.CHROME ?? 'C:/Users/hp/AppData/Local/ms-playwright/chromium-1243/chrome-win64/chrome.exe'
const results = []
const check = (name, ok, detail = '') => {
  results.push(ok)
  console.log(`${ok ? 'PASS' : 'FAIL'}  ${name}${detail ? `  (${detail})` : ''}`)
}

const browser = await chromium.launch({ executablePath: EXE })

async function journey(label, geo) {
  const ctx = await browser.newContext({ viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true, geolocation: geo, permissions: ['geolocation'] })
  const page = await ctx.newPage()
  try {
    // Something in the cart first.
    await page.goto(`${BASE}/p/oraimo-18w-fast-charger`)
    await page.locator('button.h-11:has-text("Add to cart")').click()

    // Register → taken straight to the location step.
    await page.goto(`${BASE}/register?next=/checkout`)
    const stamp = Date.now()
    await page.fill('#l', `e2e.${label.toLowerCase()}.${stamp}@example.com`)
    await page.getByRole('button', { name: 'Continue' }).click()
    await page.fill('#n', `${label} Customer`)
    await page.fill('#p', 'Shopper123!')
    await page.fill('#pc', 'Shopper123!')
    await page.getByRole('button', { name: 'Create account' }).click()
    await page.waitForURL(/account\/location/)
    check(`${label}: new customer is asked for location`, true)

    await page.getByRole('button', { name: 'Use my current location' }).click()
    await page.fill('#addr', 'Blue gate opposite the church')
    await page.fill('#ph', '0772' + String(stamp).slice(-6))
    await page.getByText('From your device').waitFor()
    await page.screenshot({ path: `${SHOTS}/loc-${label}-1-location.png` })
    await page.getByRole('button', { name: 'Save and continue' }).click()
    // One-line message about paying, then straight on to checkout.
    const status = page.getByText(/^You're in|^You're outside|^Delivering to|couldn't confirm/).first()
    await status.waitFor()
    const msg = (await status.innerText()).replace(/\s+/g, ' ')
    await page.waitForURL(/checkout/)
    await page.locator('label:has-text("Pay on delivery")').waitFor()
    await page.waitForTimeout(500)
    const cod = page.locator('label:has-text("Pay on delivery") input')
    const codEnabled = await cod.isEnabled()
    await page.screenshot({ path: `${SHOTS}/loc-${label}-2-checkout.png`, fullPage: true })
    return { page, ctx, msg, codEnabled }
  } catch (e) {
    await page.screenshot({ path: `${SHOTS}/loc-${label}-FAIL.png` })
    console.log('ERROR', String(e).split('\n')[0], page.url())
    await ctx.close()
    return null
  }
}

// Customer in central Kampala (Kisementi).
const k = await journey('Kampala', { latitude: 0.3352, longitude: 32.5966 })
if (k) {
  check('Kampala: told pay on delivery is available', /pay on delivery/i.test(k.msg), k.msg.slice(0, 80))
  check('Kampala: "Pay on delivery" can be chosen', k.codEnabled)
  await k.ctx.close()
}

// Customer in Gulu (~330 km north).
const g = await journey('Gulu', { latitude: 2.7724, longitude: 32.2881 })
if (g) {
  check('Gulu: told orders are paid online', /paid online/.test(g.msg), g.msg.slice(0, 80))
  check('Gulu: "Pay on delivery" is disabled', !g.codEnabled)
  const why = await g.page.locator('label:has-text("Pay on delivery")').innerText()
  check('Gulu: reason shown', /Only available in/.test(why), why.replace(/\s+/g, ' ').slice(0, 90))
  await g.ctx.close()
}

await browser.close()
console.log(`\n${results.filter(Boolean).length}/${results.length} checks passed`)
process.exit(results.every(Boolean) ? 0 : 1)
