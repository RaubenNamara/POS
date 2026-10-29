// Checks the Top Deals auto-slide: node autoplay-check.mjs [base]
import { chromium } from 'playwright'
const BASE = process.argv[2] ?? 'http://127.0.0.1:8000'
const b = await chromium.launch({ executablePath: 'C:/Users/hp/AppData/Local/ms-playwright/chromium-1243/chrome-win64/chrome.exe' })
const results = []
const check = (n, ok, d = '') => { results.push(ok); console.log(`${ok ? 'PASS' : 'FAIL'}  ${n}${d ? `  (${d})` : ''}`) }

async function open(opts = {}) {
  const ctx = await b.newContext({ viewport: { width: 1366, height: 900 }, ...opts })
  const p = await ctx.newPage()
  await p.goto(BASE + '/')
  await p.getByText('Top Deals').first().waitFor()
  const deals = p.locator('section', { hasText: 'Top Deals' }).first()
  const track = deals.locator('div.overflow-x-auto').first()
  await p.mouse.move(5, 5) // keep the pointer off the row
  return { ctx, p, deals, track, pos: () => track.evaluate((el) => Math.round(el.scrollLeft)) }
}

{
  const { ctx, p, deals, track, pos } = await open()
  const seen = [await pos()]
  for (let i = 0; i < 3; i++) { await p.waitForTimeout(3200); seen.push(await pos()) }
  check('slides by itself about every 3 seconds', seen[1] > seen[0] && seen[2] > seen[1], seen.join(' → '))

  await track.hover()
  const h0 = await pos(); await p.waitForTimeout(3500); const h1 = await pos()
  check('stops while the mouse is over it', h0 === h1, `${h0} → ${h1}`)
  await p.mouse.move(5, 5)

  await deals.getByRole('button', { name: /Pause the sliding/ }).click()
  await p.mouse.move(5, 5)
  const s0 = await pos(); await p.waitForTimeout(3500); const s1 = await pos()
  check('pause button stops it', s0 === s1, `${s0} → ${s1}`)
  await deals.getByRole('button', { name: /Play the sliding/ }).click()
  await p.mouse.move(5, 5)

  // Jump to the end, then the next step must glide back to the start.
  await track.evaluate((el) => el.scrollTo({ left: el.scrollWidth }))
  await p.waitForTimeout(3600)
  check('loops back to the first product at the end', (await pos()) < 50, `now at ${await pos()}`)
  await ctx.close()
}
{
  const { ctx, p, pos } = await open({ reducedMotion: 'reduce' })
  const r0 = await pos(); await p.waitForTimeout(3500); const r1 = await pos()
  check('does not move for "reduce motion" users', r0 === r1, `${r0} → ${r1}`)
  await ctx.close()
}
{
  const { ctx, p, pos } = await open()
  const other = p.locator('section', { hasText: 'Top picks in Computing' }).first().locator('div.overflow-x-auto').first()
  const o0 = await other.evaluate((el) => el.scrollLeft); await p.waitForTimeout(3500)
  check('other sections stay still', o0 === (await other.evaluate((el) => el.scrollLeft)))
  await ctx.close()
}
await b.close()
console.log(`\n${results.filter(Boolean).length}/${results.length} checks passed`)
