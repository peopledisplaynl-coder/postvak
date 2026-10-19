#!/usr/bin/env node
/**
 * Screenshots for the user manual, taken from the demo mode (fake data):
 * Dutch (docs/HANDLEIDING.md → docs/handleiding/) and English (docs/USER-GUIDE.md → docs/user-guide/).
 * Start the demo first:  npx --prefix frontend vite --mode mock --port 5174
 * Then:                  node scripts/manual-screenshots.mjs [nl|en]   (default: both; PW_CHROMIUM=/path/to/chrome)
 */
import { createRequire } from 'node:module'
import { dirname, join } from 'node:path'
import { mkdirSync } from 'node:fs'
import { fileURLToPath } from 'node:url'

const root = join(dirname(fileURLToPath(import.meta.url)), '..')
const { chromium } = createRequire(join(root, 'frontend', 'package.json'))('@playwright/test')
const base = process.env.BASE_URL || 'http://localhost:5174'
const langs = process.argv[2] ? [process.argv[2]] : ['nl', 'en']

/** Texts of the interface and the sample message, per language. */
const L = {
  nl: {
    dir: 'handleiding', locale: 'nl-NL', email: 'ton@example.nl', quickReply: 'Snel antwoorden', reply: 'Klinkt goed, ik ben er om 10 uur!',
    search: 'Zoeken in mail', unread: 'Ongelezen', clear: 'Wissen', back: 'Terug', compose: 'Nieuw bericht', subject: 'Onderwerp',
    to: 'marieke@voorbeeld.nl', subj: 'Zaterdag', body: 'Hoi Marieke,\n\nZullen we om tien uur afspreken bij de markt? ', bold: 'Neem de lijst mee!',
    file: 'Vakantiefilm.mp4', asLink: 'downloadlink', close: 'Sluiten', menu: 'Instellingen',
    names: {},
  },
  en: {
    dir: 'user-guide', locale: 'en-GB', email: 'demo@example.com', quickReply: 'Quick reply', reply: 'Sounds good, see you at ten!',
    search: 'Search mail', unread: 'Unread', clear: 'Clear', back: 'Back', compose: 'New message', subject: 'Subject',
    to: 'maria@example.org', subj: 'Saturday', body: 'Hi Maria,\n\nShall we meet at ten at the market? ', bold: 'Bring the list!',
    file: 'Holiday video.mp4', asLink: 'download link', close: 'Close', menu: 'Settings',
    names: {
      inloggen: 'login', overzicht: 'overview', 'snel-antwoorden': 'quick-reply', labels: 'labels', zoeken: 'search', schrijven: 'compose',
      'grote-bijlage': 'large-file', 'instellingen-algemeen': 'settings-general', 'instellingen-weergave': 'settings-appearance',
      'instellingen-regels': 'settings-rules', 'instellingen-agenda': 'settings-calendar', 'instellingen-privacy': 'settings-privacy',
      'instellingen-accounts': 'settings-accounts', 'over-postvak': 'about', agenda: 'calendar', taken: 'tasks', contacten: 'contacts',
      'telefoon-lijst': 'phone-list', 'telefoon-menu': 'phone-menu', 'telefoon-bericht': 'phone-message',
    },
  },
}
let T, out

const browser = await chromium.launch({ executablePath: process.env.PW_CHROMIUM || undefined })
const desktop = { viewport: { width: 1280, height: 800 }, deviceScaleFactor: 1 }
const phone = { viewport: { width: 390, height: 844 }, deviceScaleFactor: 2, isMobile: true, hasTouch: true }
const rows = (page) => page.locator('section ul.divide-y > li')

async function session(options, { login = true } = {}) {
  const context = await browser.newContext({ locale: T.locale, timezoneId: 'Europe/Amsterdam', colorScheme: 'light', ...options })
  await context.clock.setFixedTime(new Date('2026-10-14T13:20:00Z'))
  const page = await context.newPage()
  await page.goto(`${base}/login`)
  if (login) {
    await page.fill('#email', T.email)
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

for (const lang of langs) {
  T = L[lang]
  const dir = join(root, 'docs', T.dir)
  mkdirSync(dir, { recursive: true })
  out = (name) => join(dir, `${T.names[name] ?? name}.png`)
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
      const box = page.getByRole('textbox', { name: T.quickReply })
      await box.fill(T.reply)
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
      await page.getByRole('button', { name: T.search }).first().click()
      await page.getByRole('button', { name: T.unread, exact: true }).click()
      await page.waitForTimeout(700)
      await page.screenshot({ path: out('zoeken'), clip: { x: 0, y: 0, width: 700, height: 460 } })
      await page.getByRole('button', { name: T.clear, exact: true }).click()
      await page.locator(`header button[aria-label="${T.back}"]`).first().click()
    })
    await shot('schrijven', async () => {
      await page.getByRole('button', { name: T.compose }).first().click()
      await page.locator('.ProseMirror').waitFor()
      await page.locator('form input[type=email]').first().fill(T.to)
      await page.keyboard.press('Enter')
      await page.locator(`form input[placeholder="${T.subject}"]`).fill(T.subj)
      await page.locator('.ProseMirror').click()
      await page.keyboard.type(T.body)
      await page.keyboard.press('Control+b')
      await page.keyboard.type(T.bold)
      await page.keyboard.press('Control+b')
      await page.waitForTimeout(400)
      await page.locator('form').first().screenshot({ path: out('schrijven') })
    })
    await shot('grote-bijlage', async () => {
      const big = Buffer.alloc(12 * 1024 * 1024, 7)
      await page.locator('form input[type=file]').setInputFiles({ name: T.file, mimeType: 'video/mp4', buffer: big })
      await page.locator('form li', { hasText: T.asLink }).waitFor({ timeout: 20000 })
      await page.waitForTimeout(400)
      await page.locator('form').first().screenshot({ path: out('grote-bijlage') })
      await page.getByRole('button', { name: T.close }).first().click().catch(() => page.keyboard.press('Escape'))
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
      await page.locator(`header button[aria-label="${T.menu}"]`).first().click().catch(() => {})
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

}

await browser.close()
console.log('manual screenshots updated:', langs.join(', '))
