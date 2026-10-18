#!/usr/bin/env node
/**
 * Regenerates docs/screenshots/*.png from the demo mode (fake data).
 * Start the demo first:  npx --prefix frontend vite --mode mock --port 5174
 * Then:                  node scripts/screenshots.mjs   (PW_CHROMIUM=/path/to/chrome to use an existing browser)
 */
import { createRequire } from 'node:module'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'

const root = join(dirname(fileURLToPath(import.meta.url)), '..')
const { chromium, devices } = createRequire(join(root, 'frontend', 'package.json'))('@playwright/test')
const base = process.env.BASE_URL || 'http://localhost:5174'
const out = (name) => join(root, 'docs', 'screenshots', `${name}.png`)

const browser = await chromium.launch({ executablePath: process.env.PW_CHROMIUM || undefined })

async function session(options) {
  const context = await browser.newContext({ locale: 'en-GB', ...options })
  const page = await context.newPage()
  await page.goto(`${base}/login`)
  await page.fill('#email', 'demo@example.com')
  await page.fill('#password', 'demo')
  await page.click('button[type=submit]')
  await page.locator('section ul.divide-y > li').first().waitFor()
  await page.waitForTimeout(400)
  return { context, page }
}

async function openFirst(page, index = 0) {
  await page.locator('section ul.divide-y > li').nth(index).locator('[role=button]').click()
  await page.locator('article h2').first().waitFor()
  await page.mouse.move(5, 5)
  await page.waitForTimeout(700)
}

const desktop = { viewport: { width: 1440, height: 900 }, deviceScaleFactor: 1 }

for (const scheme of ['light', 'dark']) {
  const { context, page } = await session({ ...desktop, colorScheme: scheme })
  await openFirst(page)
  await page.screenshot({ path: out(`desktop-${scheme}`) })
  if (scheme === 'light') {
    await page.keyboard.press('Escape')
    await page.getByRole('button', { name: 'New message' }).first().click()
    await page.locator('.ProseMirror').waitFor()
    await page.locator('form input').first().fill('maria@example.org')
    await page.keyboard.press('Enter')
    await page.locator('form input[placeholder="Subject"]').fill('Plans for Saturday')
    await page.locator('.ProseMirror').click()
    await page.keyboard.type('Hi Maria,\n\nShall we meet at ten at the market? ')
    await page.keyboard.press('Control+b')
    await page.keyboard.type('Bring the list!')
    await page.keyboard.press('Control+b')
    await page.waitForTimeout(400)
    await page.screenshot({ path: out('compose') })
  }
  await context.close()
}

{
  const { context, page } = await session({ ...devices['Pixel 7'], colorScheme: 'light' })
  await page.screenshot({ path: out('mobile-list') })
  await openFirst(page, 1)
  await page.screenshot({ path: out('mobile-message') })
  await context.close()
}
{
  const { context, page } = await session({ ...devices['Pixel 7'], colorScheme: 'dark' })
  await page.goto(`${base}/settings`)
  await page.waitForTimeout(600)
  await page.screenshot({ path: out('mobile-settings-dark') })
  await context.close()
}

await browser.close()
console.log('docs/screenshots updated')
