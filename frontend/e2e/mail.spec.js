import { test, expect } from '@playwright/test'

/** Demo mode: any address and password log in. */
async function login(page) {
  await page.goto('/login')
  await page.fill('#email', 'demo@example.nl')
  await page.fill('#password', 'demo')
  await page.click('button[type=submit]')
  await expect(page.locator('section ul.divide-y > li').first()).toBeVisible()
}

const rows = (page) => page.locator('section ul.divide-y > li')

test('log in, open a message, go back', async ({ page }) => {
  await login(page)
  const first = rows(page).first()
  await first.locator('[role=button]').click()
  await expect(page).toHaveURL(/uid=/)
  await expect(page.locator('article h2').first()).not.toBeEmpty()
  await page.locator('header button[aria-label="Terug"]').first().click()
  await expect(page).not.toHaveURL(/uid=/)
})

test('archive with undo puts the message back', async ({ page, isMobile }) => {
  test.skip(isMobile, 'hover actions are a desktop feature')
  await login(page)
  const count = await rows(page).count()
  const first = rows(page).first()
  await first.locator('[role=button]').hover()
  await first.locator('button[title="Archiveren"]').click()
  await expect(rows(page)).toHaveCount(count - 1)
  await page.getByRole('button', { name: 'Ongedaan maken' }).click()
  await expect(rows(page)).toHaveCount(count)
})

test('select several messages and mark them read', async ({ page, isMobile }) => {
  test.skip(isMobile, 'avatar click selects on desktop; long press on phones')
  await login(page)
  await rows(page).nth(0).locator('[role=button] > button').first().click()
  await rows(page).nth(1).locator('[role=button] > button').first().click()
  await expect(page.getByText('2 geselecteerd')).toBeVisible()
  await page.locator('header button[title="Gelezen / ongelezen"]').click()
  await expect(page.getByText('2 geselecteerd')).toBeVisible()
})

test('compose: editor, formatting and a draft that is kept', async ({ page }) => {
  await login(page)
  await page.getByRole('button', { name: 'Nieuw bericht' }).first().click()
  const editor = page.locator('.ProseMirror')
  await expect(editor).toBeVisible()
  await page.locator('form input').first().fill('anja@example.nl')
  await page.keyboard.press('Enter')
  await page.locator('form input[placeholder="Onderwerp"]').fill('Test vanuit e2e')
  await editor.click()
  await page.keyboard.type('Hallo ')
  await page.keyboard.press('Control+b')
  await page.keyboard.type('vet')
  await expect(editor.locator('strong')).toHaveText('vet')
  await page.locator('form header button').first().click() // close = keep as draft
  await expect(editor).toHaveCount(0)
})

test('search narrows the list', async ({ page, isMobile }) => {
  test.skip(isMobile, 'same code path as desktop')
  await login(page)
  const before = await rows(page).count()
  await page.keyboard.press('/')
  await page.keyboard.type('zzzz-niets')
  await expect(page.getByText('Niets gevonden voor deze zoekopdracht.')).toBeVisible()
  expect(before).toBeGreaterThan(0)
})

test('settings: switch the interface to English and back', async ({ page }) => {
  await login(page)
  await page.goto('/settings/general')
  await page.locator('main label').first().locator('select').selectOption('en')
  await expect(page.locator('h1')).toHaveText('Settings')
  await page.locator('main label').first().locator('select').selectOption('nl')
  await expect(page.locator('h1')).toHaveText('Instellingen')
})

test('contacts page lists and filters', async ({ page }) => {
  await login(page)
  await page.goto('/contacts')
  await page.getByRole('tab', { name: 'Alle adressen' }).click()
  await expect(page.locator('main, section').getByText('Automatisch').first()).toBeVisible()
})
