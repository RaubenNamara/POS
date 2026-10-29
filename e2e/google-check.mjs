import { chromium } from 'playwright'
const D = process.argv[2]
const B = 'http://localhost:8000'
const b = await chromium.launch({ executablePath: 'C:/Users/hp/AppData/Local/ms-playwright/chromium-1243/chrome-win64/chrome.exe' })
const ctx = await b.newContext({ viewport: { width: 1366, height: 900 } })
const p = await ctx.newPage()
// 1. error carry-over
await p.goto(B + '/login')
await p.fill('#l', 'nobody@example.com'); await p.getByRole('button', { name: 'Continue' }).click(); await p.fill('#p', 'wrongpass')
await p.getByRole('button', { name: 'Sign in' }).click()
await p.getByText('These credentials do not match').waitFor()
await p.getByRole('link', { name: /Create an account/ }).click()
await p.waitForURL(/register/)
console.log('error shown on register page:', await p.getByText('These credentials do not match').isVisible())
// 2. owner settings card
await p.goto(B + '/login')
await p.fill('#l', 'owner@demo.local'); await p.getByRole('button', { name: 'Continue' }).click(); await p.fill('#p', 'DemoPass123')
await p.getByRole('button', { name: 'Sign in' }).click()
await p.waitForURL(/admin/)
await p.goto(B + '/admin/settings')
await p.getByText('Online payments & Google sign-in').waitFor()
await p.locator('form:has-text("Online payments")').screenshot({ path: `${D}/integrations-card.png` })
console.log('statuses:', (await p.locator('form:has-text("Online payments") .badge').allInnerTexts()).join(', '))
// 3. save a dummy client id -> Google button on sign-in
await p.fill('input[placeholder*="apps.googleusercontent.com"]', '000000000000-dummycheck.apps.googleusercontent.com')
await p.getByRole('button', { name: 'Save keys' }).click()
await p.getByText('Keys saved').waitFor()
const guest = await b.newContext({ viewport: { width: 1366, height: 900 } })
const g = await guest.newPage()
await g.goto(B + '/register')
await g.getByText('For customers. Staff sign in with their password.').waitFor({ timeout: 10000 })
await g.waitForTimeout(3000)
const frames = g.frames().filter((f) => f.url().includes('accounts.google.com')).length
console.log('google button area shown: true, google iframe loaded:', frames > 0)
await g.screenshot({ path: `${D}/register-google.png` })
// clean up: remove dummy id
await p.fill('input[placeholder*="apps.googleusercontent.com"]', '')
await p.getByRole('button', { name: 'Save keys' }).click()
await p.waitForTimeout(800)
await b.close()
