import { test, expect } from '@playwright/test'

/** Demo mode: any address and password log in. */
async function login(page) {
  await page.goto('/login')
  await page.fill('#email', 'demo@example.nl')
  await page.fill('#password', 'demo')
  await page.click('button[type=submit]')
  await expect(page.locator('section ul.divide-y > li').first()).toBeVisible()
}

test('calendar: list view, new event appears', async ({ page }) => {
  await login(page)
  await page.goto('/calendar')
  await page.getByRole('tab', { name: 'Lijst' }).click()
  await expect(page.getByText('Overleg website')).toBeVisible()
  await page.getByRole('button', { name: 'Nieuwe afspraak' }).first().click()
  await page.fill('input[placeholder="Titel"]', 'Afspraak uit e2e')
  await page.getByRole('button', { name: 'Bewaren' }).click()
  await expect(page.getByText('Afspraak bewaard')).toBeVisible()
  await expect(page.getByText('Afspraak uit e2e')).toBeVisible()
})

test('calendar: month view and switching months', async ({ page }) => {
  await login(page)
  await page.goto('/calendar')
  await page.getByRole('tab', { name: 'Maand' }).click()
  const title = page.locator('header h1')
  const before = await title.textContent()
  await page.getByRole('button', { name: 'Volgende' }).click()
  await expect(title).not.toHaveText(before)
  await page.getByRole('button', { name: 'Vandaag' }).click()
  await expect(title).toHaveText(before)
})

test('tasks: add one and complete it', async ({ page }) => {
  await login(page)
  await page.goto('/tasks')
  await page.fill('input[placeholder="Nieuwe taak"]', 'Taak uit e2e')
  await page.keyboard.press('Enter')
  const row = page.locator('li', { hasText: 'Taak uit e2e' })
  await expect(row).toBeVisible()
  await row.getByRole('button', { name: 'Afronden' }).click()
  await expect(page.getByText('Taak afgerond')).toBeVisible()
  await expect(page.locator('li', { hasText: 'Taak uit e2e' })).toHaveCount(0)
})

test('settings: app password for a phone is shown once', async ({ page }) => {
  await login(page)
  await page.goto('/settings/calendar')
  await page.fill('input[placeholder^="Naam van het apparaat"]', 'Testtelefoon')
  await page.getByRole('button', { name: 'App-wachtwoord maken' }).click()
  await expect(page.getByText('App-wachtwoord voor Testtelefoon:')).toBeVisible()
  await expect(page.locator('code')).toHaveText(/^[a-z0-9]{4}(-[a-z0-9]{4}){3}$/)
})

test('settings: add a calendar from elsewhere and make a share link', async ({ page }) => {
  await login(page)
  await page.goto('/settings/calendar')
  await page.getByPlaceholder(/iCal-adres/).fill('https://calendar.example.com/club/basic.ics')
  await page.getByPlaceholder('Naam (optioneel)').fill('Voetbalclub')
  await page.getByRole('button', { name: 'Toevoegen', exact: true }).click()
  await expect(page.locator('input[value="Voetbalclub"]')).toBeVisible()
  await expect(page.getByText(/Van calendar\.example\.com/)).toBeVisible()

  await page.getByTitle('Deellink').first().click()
  await page.getByRole('button', { name: 'Deellink maken' }).click()
  await expect(page.locator('input[readonly]').first()).toHaveValue(/\/api\/ical\//)
})

test('invitation in a message: accept puts it in the calendar', async ({ page }) => {
  await login(page)
  await page.getByText('Nieuwe repetitietijden').first().click()
  const card = page.getByRole('region', { name: 'Uitnodiging' })
  await expect(card).toBeVisible()
  await card.getByRole('button', { name: 'Accepteren' }).click()
  await expect(card.getByText('Staat in je agenda: geaccepteerd')).toBeVisible()
  await card.getByRole('button', { name: 'Afwijzen' }).click()
  await expect(card.getByText('Afgewezen')).toBeVisible()
})

test('events from a calendar from elsewhere are read-only', async ({ page }) => {
  await login(page)
  await page.goto('/calendar')
  await page.getByRole('tab', { name: 'Lijst' }).click()
  await page.getByText('Herfstvakantie regio zuid').first().click()
  await expect(page.getByText(/kan hier niet gewijzigd worden/)).toBeVisible()
  await expect(page.getByRole('button', { name: 'Opslaan' })).toHaveCount(0)
})
