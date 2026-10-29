// Screenshots of the storefront for visual review: node look.mjs [base] [dir]
import { chromium } from 'playwright'
import { mkdirSync } from 'node:fs'

const BASE = process.argv[2] ?? 'http://127.0.0.1:8000'
const DIR = process.argv[3] ?? './shots/look'
mkdirSync(DIR, { recursive: true })
const browser = await chromium.launch({ executablePath: process.env.CHROME ?? 'C:/Users/hp/AppData/Local/ms-playwright/chromium-1243/chrome-win64/chrome.exe' })

const desk = await browser.newPage({ viewport: { width: 1920, height: 960 } })
await desk.goto(BASE + '/')
await desk.getByText('Top Deals').first().waitFor()
await desk.waitForTimeout(600)
await desk.screenshot({ path: `${DIR}/desk-1-top.png` })
await desk.mouse.wheel(0, 700)
await desk.waitForTimeout(400)
await desk.screenshot({ path: `${DIR}/desk-2-sections.png` })
await desk.getByRole('button', { name: /Account/ }).click()
await desk.screenshot({ path: `${DIR}/desk-3-account.png` })
await desk.getByRole('button', { name: /Help/ }).click()
await desk.screenshot({ path: `${DIR}/desk-4-help.png` })
await desk.goto(BASE + '/help#returns')
await desk.waitForTimeout(800)
await desk.screenshot({ path: `${DIR}/desk-5-helppage.png` })

const phone = await browser.newPage({ viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true })
await phone.goto(BASE + '/')
await phone.getByText('Top Deals').first().waitFor()
await phone.waitForTimeout(600)
await phone.screenshot({ path: `${DIR}/phone-1.png` })
await phone.mouse.wheel(0, 600)
await phone.waitForTimeout(400)
await phone.screenshot({ path: `${DIR}/phone-2.png` })
await browser.close()
console.log('done')
