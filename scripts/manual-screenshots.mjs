#!/usr/bin/env node
/**
 * Screenshots for the Dutch user manual (docs/HANDLEIDING.md), taken from the demo mode (fake data).
 * Start the demo first:  npx --prefix frontend vite --mode mock --port 5174
 * Then:                  node scripts/manual-screenshots.mjs   (PW_CHROMIUM=/path/to/chrome to use an existing browser)
 */
import { createRequire } from 'node:module'
import { dirname, join } from 'node:path'
import { mkdirSync } from 'node:fs'
import { fileURLToPath } from 'node:url'

const root = join(dirname(fileURLToPath(import.meta.url)), '..')
const { chromium } = createRequire(join(root, 'frontend', 'package.json'))('@playwright/test')
const base = process.env.BASE_URL || 'http://localhost:5174'
const dir = join(root, 'docs', 'handleiding')
mkdirSync(dir, { recursive: true })
const out = (name) => join(dir, `${name}.png`)

const browser = await chromium.launch({ executablePath: process.env.PW_CHROMIUM || undefined })
const desktop = { viewport: { width: 1280, height: 800 }, deviceScaleFactor: 1 }
const phone = { viewport: { width: 390, height: 844 }, deviceScaleFactor: 2, isMobile: true, hasTouch: true }
const rows = (page) => page.locator('section ul.divide-y > li')

async function session(options, { login = true } = {}) {
  const context = await browser.newContext({ locale: 'nl-NL', timezoneId: 'Europe/Amsterdam', colorScheme: 'light', ...options })
  await context.clock.setFixedTime(new Date('2026-10-14T13:20:00Z'))
  const page = await context.newPage()
  await page.goto(`${base}/login`)
  if (login) {
    await page.fill('#email', 'ton@example.nl')
    await page.fill('#password', 'demo')
    await page.click('button[type=submit]')
    await rows(page).first().waitFor()
    await page.waitForTimeout(400)
  }
  return { context, page }
}

/** Navigate inside the app without reloading (a reload resets the demo data). */
async function go(page, path) {
  await page.evaluate((p) => { history.pushState({}, '', p); dispatchEvent(new PopStateEvent('popstate')) }, path)
  await page.waitForTimeout(700)
}

async function shot(name, fn) {
  try {
    await fn()
    console.log('✓', name)
  } catch (e) {
    console.log('✗', name, e.message.split('\n')[0])
  }
}

// ---- computer
{
  const { context, page } = await session(desktop, { login: false })
  await shot('inloggen', async () => {
    await page.waitForTimeout(500)
    await page.screenshot({ path: out('inloggen') })
  })
  await context.close()
}
{
  const { context, page } = await session(desktop)
  await shot('overzicht', async () => {
    await rows(page).nth(1).locator('[role=button]').click()
    await page.locator('article h2').first().waitFor()
    await page.mouse.move(5, 5)
    await page.waitForTimeout(700)
    await page.screenshot({ path: out('overzicht') })
  })
  await shot('snel-antwoorden', async () => {
    const box = page.getByRole('textbox', { name: 'Snel antwoorden' })
    await box.fill('Klinkt goed, ik ben er om 10 uur!')
    await page.locator('article').first().screenshot({ path: out('snel-antwoorden') })
    await box.fill('')
  })
  await shot('labels', async () => {
    await page.getByTitle('Labels').first().click()
    await page.waitForTimeout(400)
    await page.screenshot({ path: out('labels'), clip: { x: 640, y: 0, width: 640, height: 420 } })
    await page.keyboard.press('Escape')
    await page.mouse.click(900, 700)
  })
  await shot('zoeken', async () => {
    await page.getByRole('button', { name: 'Zoeken in mail' }).first().click()
    await page.getByRole('button', { name: 'Ongelezen', exact: true }).click()
    await page.waitForTimeout(700)
    await page.screenshot({ path: out('zoeken'), clip: { x: 0, y: 0, width: 700, height: 460 } })
    await page.getByRole('button', { name: 'Wissen' }).click()
    await page.locator('header button[aria-label="Terug"]').first().click()
  })
  await shot('schrijven', async () => {
    await page.getByRole('button', { name: 'Nieuw bericht' }).first().click()
    await page.locator('.ProseMirror').waitFor()
    await page.locator('form input[type=email]').first().fill('marieke@voorbeeld.nl')
    await page.keyboard.press('Enter')
    await page.locator('form input[placeholder="Onderwerp"]').fill('Zaterdag')
    await page.locator('.ProseMirror').click()
    await page.keyboard.type('Hoi Marieke,\n\nZullen we om tien uur afspreken bij de markt? ')
    await page.keyboard.press('Control+b')
    await page.keyboard.type('Neem de lijst mee!')
    await page.keyboard.press('Control+b')
    await page.waitForTimeout(400)
    await page.locator('form').first().screenshot({ path: out('schrijven') })
  })
  await shot('grote-bijlage', async () => {
    const big = Buffer.alloc(12 * 1024 * 1024, 7)
    await page.locator('form input[type=file]').setInputFiles({ name: 'Vakantiefilm.mp4', mimeType: 'video/mp4', buffer: big })
    await page.locator('form li', { hasText: 'downloadlink' }).waitFor({ timeout: 20000 })
    await page.waitForTimeout(400)
    await page.locator('form').first().screenshot({ path: out('grote-bijlage') })
    await page.getByRole('button', { name: 'Sluiten' }).first().click().catch(() => page.keyboard.press('Escape'))
  })
  for (const [name, tab, extra] of [
    ['instellingen-algemeen', 'general'],
    ['instellingen-weergave', 'appearance'],
    ['instellingen-regels', 'rules'],
    ['instellingen-agenda', 'calendar'],
    ['instellingen-privacy', 'privacy'],
    ['instellingen-accounts', 'accounts'],
    ['over-postvak', 'about'],
  ]) {
    await shot(name, async () => {
      await go(page, `/settings/${tab}`)
      await page.waitForTimeout(500)
      await page.screenshot({ path: out(name), fullPage: true })
    })
  }
  await shot('agenda', async () => {
    await go(page, '/calendar')
    await page.getByRole('tab', { name: 'Week', exact: true }).click()
    await page.waitForTimeout(700)
    await page.screenshot({ path: out('agenda') })
  })
  await shot('taken', async () => {
    await go(page, '/tasks')
    await page.waitForTimeout(800)
    await page.screenshot({ path: out('taken') })
  })
  await shot('contacten', async () => {
    await go(page, '/contacts')
    await page.waitForTimeout(800)
    await page.screenshot({ path: out('contacten') })
  })
  await context.close()
}

// ---- phone
{
  const { context, page } = await session(phone)
  await shot('telefoon-lijst', async () => {
    await page.screenshot({ path: out('telefoon-lijst') })
  })
  await shot('telefoon-menu', async () => {
    await page.locator('header button[aria-label="Instellingen"]').first().click().catch(() => {})
    await page.waitForTimeout(600)
    await page.screenshot({ path: out('telefoon-menu') })
    await page.mouse.click(370, 400)
    await page.waitForTimeout(400)
  })
  await shot('telefoon-bericht', async () => {
    await rows(page).nth(1).locator('[role=button]').click()
    await page.locator('article h2').first().waitFor()
    await page.waitForTimeout(700)
    await page.screenshot({ path: out('telefoon-bericht') })
  })
  await context.close()
}

await browser.close()
console.log('docs/handleiding updated')
