// Sign-in "email or phone first" flow: node login-check.mjs [shotsDir]
import { chromium, devices } from 'playwright'
const D = process.argv[2] ?? './shots/login'
const B = 'http://127.0.0.1:8000'
const b = await chromium.launch({ executablePath: 'C:/Users/hp/AppData/Local/ms-playwright/chromium-1243/chrome-win64/chrome.exe' })
const res = []
const check = (n, ok, d = '') => { res.push(ok); console.log(`${ok ? 'PASS' : 'FAIL'}  ${n}${d ? `  (${d})` : ''}`) }
const stamp = Date.now()
try {
  const p = await (await b.newContext({ viewport: { width: 1366, height: 900 } })).newPage()
  await p.goto(B + '/login'); await p.locator('#l').waitFor()
  await p.screenshot({ path: `${D}/1-start.png` })
  check('step one asks only for email or mobile number', (await p.locator('form input:visible').count()) === 1)

  await p.fill('#l', 'not-an-email@'); await p.getByRole('button', { name: 'Continue' }).click()
  check('a mistyped email is refused', /valid email/i.test(await p.getByRole('alert').innerText()))

  await p.fill('#l', 'owner@demo.local'); await p.getByRole('button', { name: 'Continue' }).click()
  await p.getByText('Welcome back!').waitFor()
  await p.screenshot({ path: `${D}/2-password.png` })
  check('existing account goes to the password step', await p.locator('#p').isVisible() && !(await p.locator('#n').count()))
  await p.fill('#p', 'wrong-password'); await p.getByRole('button', { name: 'Sign in' }).click()
  check('a wrong password is refused', /do not match/i.test(await p.getByRole('alert').innerText()))

  await p.getByRole('button', { name: 'Edit' }).click()
  check('Edit goes back to step one with the address kept', (await p.inputValue('#l')) === 'owner@demo.local')

  const email = `e2e.signup.${stamp}@example.com`
  await p.fill('#l', email); await p.getByRole('button', { name: 'Continue' }).click()
  await p.getByText('Create your account').waitFor()
  await p.screenshot({ path: `${D}/3-register-email.png` })
  check('new email goes to sign-up, asking name, phone and password', await p.locator('#n').isVisible() && await p.locator('#ph').isVisible() && !(await p.locator('#e').count()))

  await p.getByRole('button', { name: 'Edit' }).click()
  await p.fill('#l', '0799' + String(stamp).slice(-6)); await p.getByRole('button', { name: 'Continue' }).click()
  await p.getByText('Create your account').waitFor()
  check('new phone number goes to sign-up, asking for an email', await p.locator('#e').isVisible() && !(await p.locator('#ph').count()))

  await p.getByRole('button', { name: 'Edit' }).click()
  await p.fill('#l', email); await p.getByRole('button', { name: 'Continue' }).click()
  await p.fill('#n', 'E2E Signup'); await p.fill('#p', 'Shopper123!'); await p.fill('#pc', 'Shopper123!')
  await p.getByRole('button', { name: 'Create account' }).click()
  await p.waitForURL(/account\/location/)
  check('new customer account is created and asked for location', true)

  const m = await (await b.newContext(devices['Pixel 7'])).newPage()
  await m.goto(B + '/login'); await m.locator('#l').waitFor()
  await m.screenshot({ path: `${D}/4-phone.png` })
  const over = await m.evaluate(() => document.documentElement.scrollWidth > innerWidth)
  check('fits a phone screen', !over)
} finally {
  await b.close()
}
console.log(`\n${res.filter(Boolean).length}/${res.length} checks passed`)
