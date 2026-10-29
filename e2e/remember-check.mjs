import { chromium } from 'playwright'
const D = process.argv[2]
const b = await chromium.launch({ executablePath: 'C:/Users/hp/AppData/Local/ms-playwright/chromium-1243/chrome-win64/chrome.exe' })
const p = await b.newPage({ viewport: { width: 1600, height: 1000 } })
await p.goto('http://127.0.0.1:8000/login'); await p.fill('#l', 'owner@demo.local'); await p.getByRole('button', { name: 'Continue' }).click(); await p.fill('#p', 'DemoPass123')
await p.getByRole('button', { name: 'Sign in' }).click(); await p.waitForURL(/admin/)
await p.goto('http://127.0.0.1:8000/admin/products'); await p.locator('table tbody tr').first().waitFor()
await p.locator('select').first().selectOption({ label: 'Computing' })
await p.getByText('on the home page shows the first').waitFor()
await p.waitForTimeout(600)
await p.locator('table tbody tr').nth(1).click()                       // open product #2
await p.getByText(/2 of 10/).waitFor()
console.log('on product page:', await p.locator('a:has-text("Products (")').innerText(), '|', await p.getByText(/of 10/).innerText())
await p.getByRole('link', { name: /Next/ }).click(); await p.getByText(/3 of 10/).waitFor()
console.log('after Next:', await p.getByText(/of 10/).innerText())
await p.getByRole('button', { name: 'Save product' }).click()
await p.getByText('Product saved').waitFor()
await p.waitForTimeout(250)
await p.screenshot({ path: `${D}/toast.png`, clip: { x: 500, y: 0, width: 600, height: 120 } })
await p.locator('a:has-text("Products (")').click()                 // back to the list
await p.locator('table tbody tr').first().waitFor(); await p.waitForTimeout(400)
console.log('back on list, section still:', await p.locator('select').first().evaluate((s) => s.options[s.selectedIndex].text), '| rows:', await p.locator('table tbody tr').count())
await p.getByRole('link', { name: 'Products' }).first().click()       // even via the sidebar
await p.waitForTimeout(600)
console.log('via sidebar, section still:', await p.locator('select').first().evaluate((s) => s.options[s.selectedIndex].text))
await b.close()
