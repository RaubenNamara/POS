import { chromium } from 'playwright'
const b = await chromium.launch({ executablePath: 'C:/Users/hp/AppData/Local/ms-playwright/chromium-1243/chrome-win64/chrome.exe' })
const p = await (await b.newContext()).newPage()
p.on('response', (r) => r.url().includes('/api/auth') && console.log(r.status(), r.url()))
await p.goto('http://localhost:8000/login'); await p.fill('#l', 'owner@demo.local'); await p.getByRole('button', { name: 'Continue' }).click()
await p.waitForTimeout(1500)
console.log('alert:', await p.getByRole('alert').allInnerTexts(), 'has #p', await p.locator('#p').count())
if (await p.locator('#p').count()) { await p.fill('#p', 'DemoPass123'); await p.getByRole('button', { name: 'Sign in' }).click(); await p.waitForTimeout(2500); console.log('url', p.url(), await p.getByRole('alert').allInnerTexts()) }
await b.close()
