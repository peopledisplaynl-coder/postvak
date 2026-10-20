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
  // Undo turns the change back.
  await expect(page.getByText('2 berichten als gelezen gemarkeerd')).toBeVisible()
  await page.getByRole('button', { name: 'Ongedaan maken' }).click()
  await expect(page.getByText('2 berichten als ongelezen gemarkeerd')).toHaveCount(0)
  await expect(rows(page).nth(0).locator('.font-semibold').first()).toBeVisible()
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

test('compose: heading and colour menus open and apply', async ({ page }) => {
  await login(page)
  await page.getByRole('button', { name: 'Nieuw bericht' }).first().click()
  const editor = page.locator('.ProseMirror')
  await editor.click()
  await page.keyboard.type('Titel')
  await page.getByRole('toolbar').getByRole('button', { name: /Normaal/ }).click()
  await page.getByRole('button', { name: 'Kop', exact: true }).click()
  await expect(editor.locator('h2')).toHaveText('Titel')
  await page.keyboard.press('End')
  await page.keyboard.press('Enter')
  await page.keyboard.type('rood')
  await page.keyboard.press('Shift+Home')
  await page.getByRole('toolbar').getByTitle('Tekstkleur').click()
  await page.getByRole('button', { name: '#c2463c' }).click()
  await expect(editor.locator('span[style*="color"]')).toHaveText('rood')
})

test('columns can be resized and reset', async ({ page, isMobile }) => {
  test.skip(isMobile, 'columns are fixed on phones')
  await login(page)
  const list = page.locator('section').first().locator('..')
  const before = (await list.boundingBox()).width
  const handle = page.getByRole('separator', { name: 'Breedte van de berichtenlijst' })
  const box = await handle.boundingBox()
  await page.mouse.move(box.x + box.width / 2, box.y + 200)
  await page.mouse.down()
  await page.mouse.move(box.x + box.width / 2 + 120, box.y + 200, { steps: 5 })
  await page.mouse.up()
  await expect.poll(async () => Math.round((await list.boundingBox()).width)).toBe(Math.round(before + 120))
  await page.reload()
  await page.locator('section ul.divide-y > li').first().waitFor()
  await expect.poll(async () => Math.round((await list.boundingBox()).width)).toBe(Math.round(before + 120))
  await handle.dblclick()
  await expect.poll(async () => Math.round((await list.boundingBox()).width)).toBe(Math.round(before))
})

test('rules: make one from a message, out-of-office reply', async ({ page }) => {
  await login(page)
  await rows(page).first().locator('[role=button]').click()
  await page.getByTitle('Regel maken voor deze afzender').click()
  await expect(page).toHaveURL(/settings\/rules/)
  const editor = page.locator('form').filter({ hasText: 'Verplaatsen naar' })
  await expect(editor.locator('input[maxlength="255"]').first()).not.toHaveValue('')
  await editor.getByLabel('Ster geven').check()
  await editor.getByRole('button', { name: 'Opslaan' }).click()
  await expect(page.getByText('Regel opgeslagen')).toBeVisible()
  await expect(page.locator('li').filter({ hasText: /afzender bevat/ }).last()).toBeVisible()

  await page.getByLabel('Aan', { exact: true }).check()
  await page.getByPlaceholder(/Ik ben tot en met/).fill('Ik ben op vakantie.')
  await page.getByRole('button', { name: 'Opslaan' }).first().click()
  await expect(page.getByText('Afwezigheidsmelding staat aan')).toBeVisible()
})

test('compose: insert a template and save a new one', async ({ page }) => {
  await login(page)
  await page.getByRole('button', { name: 'Nieuw bericht' }).first().click()
  const editor = page.locator('.ProseMirror')
  await editor.click()
  await page.getByTitle('Sjablonen').click()
  await page.getByRole('button', { name: 'Bedankt', exact: true }).click()
  await expect(editor).toContainText('Bedankt voor je bericht')
  page.once('dialog', (d) => d.accept('Mijn sjabloon'))
  await page.getByTitle('Sjablonen').click()
  await page.getByRole('button', { name: 'Deze tekst opslaan als sjabloon' }).click()
  await expect(page.getByText('Sjabloon "Mijn sjabloon" opgeslagen')).toBeVisible()
})

test('labels: give a message a label and filter on it', async ({ page, isMobile }) => {
  await login(page)
  await rows(page).nth(2).locator('[role=button]').click()
  await page.locator('article h2').first().waitFor()
  if (isMobile) {
    // On a phone the labels are in the ⋮ menu.
    await page.getByRole('button', { name: 'Meer acties' }).click()
    await page.getByRole('menuitem', { name: 'Labels' }).click()
    await page.getByRole('menuitemcheckbox', { name: 'Persoonlijk' }).click()
    await page.keyboard.press('Escape')
    await page.keyboard.press('Escape')
  } else {
    await page.getByTitle('Labels').first().click()
    await page.getByRole('menuitemcheckbox', { name: 'Persoonlijk' }).click()
    await page.mouse.click(5, 300) // outside the menu closes it
  }
  await expect(page.locator('article').getByText('Persoonlijk')).toBeVisible()
  await page.goBack()
  await expect(rows(page).first()).toBeVisible()
  const sidebarLabel = page.getByRole('button', { name: 'Persoonlijk', exact: true })
  if (!(await sidebarLabel.isVisible())) await page.getByRole('button', { name: /menu|Instellingen/i }).first().click()
  await page.getByRole('button', { name: 'Persoonlijk', exact: true }).click()
  await expect(page.getByText('Alleen met label')).toBeVisible()
  await expect(rows(page)).toHaveCount(1)
  await page.getByRole('button', { name: 'Alles tonen' }).click()
  await expect(page.getByText('Alleen met label')).toHaveCount(0)
})

test('appearance: accent colour and text size', async ({ page }) => {
  await login(page)
  await page.goto('/settings/appearance')
  await page.getByRole('radio', { name: 'Groen' }).click()
  await expect(page.locator('html')).toHaveAttribute('data-accent', 'green')
  await page.getByRole('combobox').nth(1).selectOption('large')
  await expect(page.locator('html')).toHaveAttribute('data-text-size', 'large')
  await page.getByPlaceholder('Nieuw label, bijv. Klant').fill('Klant')
  await page.getByRole('button', { name: 'Toevoegen' }).click()
  await expect(page.locator('input[placeholder="Klant"], input').filter({ hasText: '' }).last()).toBeVisible()
})

test('signature with a logo', async ({ page }) => {
  await login(page)
  await page.goto('/settings/accounts')
  await page.getByRole('button', { name: 'Bewerken' }).first().click()
  const editor = page.locator('.ProseMirror')
  await editor.click()
  await page.keyboard.type('Ton – Postvak')
  await page.locator('input[type=file][accept^="image/"]').setInputFiles('e2e/logo.png')
  await expect(editor.locator('img[src^="blob:"]')).toHaveCount(1)
})

test('phones load fewer messages and the rest when scrolling', async ({ page, isMobile }) => {
  test.skip(!isMobile, 'the phone setting (25) is below the demo inbox size; computers load 50')
  await login(page)
  const more = page.getByRole('button', { name: /Meer berichten laden/ })
  await expect(more).toBeVisible()
  const first = await rows(page).count()
  expect(first).toBeLessThanOrEqual(25)
  await more.scrollIntoViewIfNeeded()
  await expect.poll(() => rows(page).count()).toBeGreaterThan(first)

  // Setting 10 per page: the list starts over with 10.
  await page.goto('/settings')
  const select = page.locator('select').filter({ has: page.locator('option[value="10"]') }).first()
  await select.selectOption('10')
  await page.goBack()
  await expect.poll(() => rows(page).count()).toBeLessThanOrEqual(10)
})

test('about page shows the maker and the version', async ({ page }) => {
  await login(page)
  await page.goto('/settings/about')
  await expect(page.getByRole('heading', { name: 'Postvak' })).toBeVisible()
  await expect(page.getByRole('link', { name: 'PeopleDisplay', exact: true })).toHaveAttribute('href', 'https://peopledisplay.nl')
  await expect(page.getByText(/^Versie \d/)).toBeVisible()
})

test('quick reply under a message sends without opening the composer', async ({ page }) => {
  await login(page)
  await rows(page).first().locator('[role=button]').click()
  const box = page.getByRole('textbox', { name: 'Snel antwoorden' })
  await expect(box).toBeVisible()
  await box.fill('Prima, tot dan!')
  await page.getByRole('button', { name: 'Versturen', exact: true }).last().click()
  await expect(page.getByText('Bericht wordt verstuurd…')).toBeVisible()
  await expect(page.locator('form .ProseMirror')).toBeHidden()
  await expect(box).toHaveValue('')

  // "Full editor" opens the composer with the text typed so far.
  await box.fill('Even uitgebreider')
  await page.getByRole('button', { name: 'Volledig venster' }).click()
  await expect(page.locator('form .ProseMirror')).toContainText('Even uitgebreider')
})

test('search filters: unread, attachment and more from a sender', async ({ page }) => {
  await login(page)
  await page.getByRole('button', { name: 'Zoeken' }).first().click()
  const all = await rows(page).count()
  await page.getByRole('button', { name: 'Ongelezen', exact: true }).click()
  await expect.poll(() => rows(page).count()).toBeLessThan(all)
  await expect(page.getByRole('button', { name: 'Ongelezen', exact: true })).toHaveAttribute('aria-pressed', 'true')
  await page.getByRole('button', { name: 'Wissen' }).click()
  await expect.poll(() => rows(page).count()).toBe(all)
  await page.getByRole('button', { name: 'Terug' }).first().click()

  // From the reader: "More from this sender".
  await rows(page).first().locator('[role=button]').click()
  await page.getByTitle('Meer van deze afzender').first().click()
  await expect(page.getByText(/^Van: /)).toBeVisible()
  await expect.poll(() => rows(page).count()).toBeLessThan(all)
})

test('a large file goes as a download link', async ({ page }) => {
  await login(page)
  await page.getByRole('button', { name: 'Nieuw bericht' }).first().click()
  const big = Buffer.alloc(11 * 1024 * 1024, 7)
  await page.locator('form input[type=file]').setInputFiles({ name: 'presentatie.mov', mimeType: 'video/quicktime', buffer: big })
  await expect(page.locator('form li', { hasText: 'presentatie.mov' })).toContainText('downloadlink')
  await expect(page.locator('form .ProseMirror a', { hasText: 'presentatie.mov' })).toHaveAttribute('href', /\/api\/files\/\w{32}$/)
  // In-app navigation (a page load would reset the demo data).
  await page.evaluate(() => { history.pushState({}, '', '/settings/privacy'); dispatchEvent(new PopStateEvent('popstate')) })
  await expect(page.getByRole('link', { name: 'presentatie.mov' })).toBeVisible()
})

test('deleting the open message from the list also closes the reader', async ({ page, isMobile }) => {
  test.skip(isMobile, 'hover buttons are a desktop feature')
  await login(page)
  const first = rows(page).first()
  const count = await rows(page).count()
  await first.locator('[role=button]').click()
  await expect(page).toHaveURL(/uid=/)
  await first.hover()
  await first.getByTitle('Verwijderen').click()
  await expect(page).not.toHaveURL(/uid=/)
  await expect(page.locator('article h2')).toHaveCount(0)
  await expect.poll(() => rows(page).count()).toBe(count - 1)
  // Undo puts it back in the list.
  await page.getByRole('button', { name: 'Ongedaan maken' }).click()
  await expect.poll(() => rows(page).count()).toBe(count)
})

test('right-click menu on a message in the list', async ({ page, isMobile }) => {
  test.skip(isMobile, 'right-click is a desktop feature')
  await login(page)
  const row = rows(page).nth(2)
  await row.locator('[role=button]').click({ button: 'right' })
  const menu = page.getByRole('menu')
  await expect(menu.getByRole('menuitem', { name: 'Beantwoorden', exact: true })).toBeVisible()
  await menu.getByRole('menuitem', { name: 'Labels' }).click()
  await menu.getByRole('menuitemcheckbox', { name: 'Werk' }).click()
  await expect(menu.getByRole('menuitemcheckbox', { name: 'Werk' })).toHaveAttribute('aria-checked', 'true')
  await page.keyboard.press('Escape')
  await page.keyboard.press('Escape')
  await expect(page.getByRole('menu')).toHaveCount(0)
  await expect(row).toContainText('Werk')
  // Reply from the menu opens the composer with "Re:".
  await row.locator('[role=button]').click({ button: 'right' })
  await page.getByRole('menuitem', { name: 'Beantwoorden', exact: true }).click()
  await expect(page.locator('form input[placeholder="Onderwerp"]')).toHaveValue(/^Re: /)
})

test('phone: bigger toolbar with a ⋮ menu for the other actions', async ({ page, isMobile }) => {
  test.skip(!isMobile, 'the ⋮ menu is for small screens')
  await login(page)
  await rows(page).nth(1).locator('[role=button]').click()
  await expect(page.getByRole('button', { name: 'Archiveren' })).toBeVisible()
  await expect(page.locator('header button[title="Verplaatsen naar map"]')).toBeHidden()
  await page.getByRole('button', { name: 'Meer acties' }).click()
  await expect(page.getByRole('menuitem', { name: 'Snoozen' })).toBeVisible()
  await expect(page.getByRole('menuitem', { name: 'Verplaatsen naar map' })).toBeVisible()
  await page.getByRole('menuitem', { name: 'Ster geven' }).click()
  await expect(page.getByRole('menu')).toHaveCount(0)
})

test('contacts: full card with company, phones, address, birthday and groups', async ({ page, isMobile }) => {
  await login(page)
  await page.goto('/contacts')
  // The card of a contact with details.
  await page.getByRole('button', { name: /Marieke de Vries/ }).first().click()
  await expect(page.getByRole('heading', { name: 'Marieke de Vries' })).toBeVisible()
  await expect(page.getByText('Teamleider · PeopleDisplay').last()).toBeVisible()
  await expect(page.getByText('Gilze')).toBeVisible()
  await expect(page.getByRole('link', { name: 'Op de kaart' })).toBeVisible()

  // A new contact with the full form (on a phone: back to the list first).
  if (isMobile) await page.locator('button[aria-label="Terug"]:visible').first().click()
  await page.getByTitle('Nieuw contact').click()
  await page.getByLabel('Voornaam').fill('Sanne')
  await page.getByLabel('Achternaam').fill('Bakker')
  await page.getByRole('button', { name: '+ Bedrijf en functie' }).click()
  await page.getByLabel('Bedrijf').fill('Bakkerij Bakker')
  await page.getByLabel('Functie').fill('Eigenaar')
  await page.getByLabel('E-mailadres').first().fill('sanne@bakkerij.example')
  await page.getByRole('button', { name: '+ Telefoonnummer toevoegen' }).click()
  await page.getByLabel('Telefoon', { exact: true }).nth(1).fill('013 123 45 67')
  await page.getByLabel('Soort').nth(2).selectOption('work')
  await page.getByRole('button', { name: '+ Adres toevoegen' }).click()
  await page.getByLabel('Straat en huisnummer').fill('Kerkstraat 5')
  await page.getByLabel('Plaats').fill('Tilburg')
  await page.getByLabel('Verjaardag').fill('1990-07-01')
  await page.getByRole('combobox', { name: 'Groepen' }).fill('Leveranciers')
  await page.keyboard.press('Enter')
  await page.getByRole('button', { name: 'Opslaan' }).click()
  await expect(page.getByRole('heading', { name: 'Sanne Bakker' })).toBeVisible()
  await expect(page.getByText('Eigenaar · Bakkerij Bakker').last()).toBeVisible()
  await expect(page.getByRole('link', { name: '013 123 45 67' })).toBeVisible()
  await expect(page.getByText('Kerkstraat 5')).toBeVisible()
  await expect(page.getByText(/1 juli 1990/)).toBeVisible()
})

/** Navigate inside the app (a page load would reset the demo data). */
async function goInApp(page, path) {
  await page.evaluate((p) => { history.pushState({}, '', p); dispatchEvent(new PopStateEvent('popstate')) }, path)
}

test('inbox tabs: Primary, Promotions, Social and Updates', async ({ page }) => {
  await login(page)
  await goInApp(page, '/settings')
  await page.getByRole('checkbox', { name: /Inboxtabbladen/ }).check()
  await goInApp(page, '/')
  const tabs = page.getByRole('tablist', { name: 'Inboxtabbladen' })
  await expect(tabs.getByRole('tab', { name: 'Primair' })).toHaveAttribute('aria-selected', 'true')
  const primary = await rows(page).count()
  await tabs.getByRole('tab', { name: 'Reclame' }).click()
  await expect(tabs.getByRole('tab', { name: 'Reclame' })).toHaveAttribute('aria-selected', 'true')
  await expect.poll(() => rows(page).count()).not.toBe(primary)
})

test('a contact group as recipient adds all its members', async ({ page }) => {
  await login(page)
  await page.getByRole('button', { name: 'Nieuw bericht' }).first().click()
  await page.locator('form input').first().fill('Werk')
  await page.getByText('👥 Werk').click()
  await expect(page.getByRole('button', { name: /verwijderen$/ })).not.toHaveCount(0)
  expect(await page.getByRole('button', { name: /verwijderen$/ }).count()).toBeGreaterThan(1)
})

test('follow-up reminder: banner when no reply came', async ({ page }) => {
  await login(page)
  // The demo has one sent message that came back because nobody answered.
  await goInApp(page, '/mail/1?folder=INBOX&uid=995&acc=1')
  await expect(page.getByText('kreeg nog geen antwoord')).toBeVisible()
  await expect(page.getByRole('button', { name: 'Opnieuw opvolgen' })).toBeVisible()
})

test('unsubscribe, block sender and the original message', async ({ page, isMobile }) => {
  page.on('dialog', (d) => d.accept())
  await login(page)
  await page.getByRole('button', { name: 'Nieuw bericht' }).first().waitFor()
  // A mailing (promotions) has an "Unsubscribe" button.
  const promo = rows(page).filter({ hasText: /Webwinkel|Winkel|Shop/i }).first()
  await promo.locator('[role=button]').click()
  await page.getByRole('button', { name: 'Uitschrijven' }).click()
  await expect(page.getByText(/Je bent uitgeschreven/)).toBeVisible()
  // Block the sender from the ⋮ menu.
  await page.getByRole('button', { name: 'Meer acties' }).first().click()
  await expect(page.getByRole('menuitem', { name: 'Origineel bekijken' })).toBeVisible()
  await expect(page.getByRole('menuitem', { name: 'Gesprek negeren' })).toBeVisible()
  await page.getByRole('menuitem', { name: 'Afzender blokkeren' }).click()
  await expect(page.getByText(/gaat voortaan naar de prullenbak/)).toBeVisible()
  if (!isMobile) await expect(page).not.toHaveURL(/uid=/)
})

test('after deleting: open the next message', async ({ page, isMobile }) => {
  test.skip(isMobile, 'the toolbar delete is the same on phones; checked on desktop')
  await login(page)
  await goInApp(page, '/settings')
  await page.getByRole('combobox').filter({ hasText: 'Terug naar de lijst' }).selectOption('next')
  await goInApp(page, '/')
  await rows(page).nth(2).locator('[role=button]').click()
  await expect(page).toHaveURL(/uid=/)
  const before = new URL(page.url()).search
  await page.locator('header button[title="Verwijderen"]').first().click()
  await expect.poll(() => new URL(page.url()).search).not.toBe(before)
  await expect(page).toHaveURL(/uid=\d+/)
})

test('attachments overview and storage bar', async ({ page, isMobile }) => {
  await login(page)
  if (!isMobile) await expect(page.getByText(/van 1 GB gebruikt|van 1,0 GB gebruikt|gebruikt/).first()).toBeVisible()
  await goInApp(page, '/attachments')
  await expect(page.getByRole('heading', { name: 'Bijlagen' })).toBeVisible()
  await expect(page.getByText('zaal.png').first()).toBeVisible()
  await page.getByRole('tab', { name: 'Afbeeldingen' }).click()
  await expect(page.getByText('planning.xlsx')).toHaveCount(0)
  await page.getByText('zaal.png').first().click()
  await expect(page).toHaveURL(/uid=/)
})

test('inbox tabs: choose the tabs and move a sender to another tab', async ({ page }) => {
  await login(page)
  await goInApp(page, '/settings')
  await page.getByRole('checkbox', { name: /Inboxtabbladen/ }).check()
  await page.getByRole('checkbox', { name: 'Sociaal', exact: true }).uncheck()
  await goInApp(page, '/')
  const tabs = page.getByRole('tablist', { name: 'Inboxtabbladen' })
  await expect(tabs.getByRole('tab')).toHaveCount(3)
  await expect(tabs.getByRole('tab', { name: 'Sociaal' })).toHaveCount(0)
  // A newsletter that is not one: move the sender to Primary.
  await tabs.getByRole('tab', { name: 'Reclame' }).click()
  await expect(rows(page).first()).toContainText(/Webwinkel/)
  const before = await rows(page).count()
  await rows(page).first().locator('[role=button]').click()
  await page.getByRole('button', { name: 'Meer acties' }).first().click()
  await page.getByRole('menuitem', { name: 'Naar ander tabblad' }).click()
  await page.getByRole('menuitemradio', { name: 'Primair' }).click()
  await expect(page.getByText(/komt voortaan in Primair/)).toBeVisible()
  await expect.poll(() => rows(page).count()).toBeLessThan(before)
  // It is listed in the settings, where it can be reset.
  await goInApp(page, '/settings')
  await expect(page.getByText('Zelf ingedeelde afzenders')).toBeVisible()
})
