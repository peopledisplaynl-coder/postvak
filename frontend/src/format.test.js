import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'
import { listDate, laterDate, fileSize, initials, avatarColor, displayName } from './format.js'
import { setLanguage } from './i18n/index.js'

describe('dates', () => {
  beforeEach(() => {
    vi.useFakeTimers()
    vi.setSystemTime(new Date(2026, 9, 18, 12, 0)) // Sunday 18 October 2026, 12:00 local
    setLanguage('nl')
  })
  afterEach(() => vi.useRealTimers())

  it('list: time today, "gisteren" yesterday, weekday this week, date otherwise', () => {
    expect(listDate(new Date(2026, 9, 18, 9, 5).toISOString())).toBe('09:05')
    expect(listDate(new Date(2026, 9, 17, 20, 0).toISOString())).toBe('gisteren')
    expect(listDate(new Date(2026, 9, 14).toISOString())).toMatch(/^wo/)
    expect(listDate(new Date(2026, 2, 3).toISOString())).toBe('3 mrt')
    expect(listDate(new Date(2024, 2, 3).toISOString())).toBe('3 mrt 2024')
  })

  it('future: "vandaag", "morgen", weekday, date — and English', () => {
    expect(laterDate(new Date(2026, 9, 18, 18, 0).toISOString())).toBe('vandaag 18:00')
    expect(laterDate(new Date(2026, 9, 19, 8, 0).toISOString())).toBe('morgen 08:00')
    expect(laterDate(new Date(2026, 9, 22, 8, 0).toISOString())).toMatch(/^do 08:00$/)
    setLanguage('en')
    expect(laterDate(new Date(2026, 9, 19, 8, 0).toISOString())).toBe('tomorrow 08:00')
    expect(listDate(new Date(2026, 9, 17, 20, 0).toISOString())).toBe('yesterday')
  })
})

describe('small helpers', () => {
  it('file sizes', () => {
    expect(fileSize(0)).toBe('')
    expect(fileSize(512)).toBe('512 B')
    expect(fileSize(1536)).toBe('1.5 kB')
    expect(fileSize(5 * 1024 * 1024)).toBe('5.0 MB')
  })

  it('initials and names', () => {
    expect(initials({ name: 'Anja Bakker', email: 'a@b.nl' })).toBe('AB')
    expect(initials({ name: '', email: 'peter@x.nl' })).toBe('P')
    expect(initials(null)).toBe('?')
    expect(displayName({ name: '', email: 'x@y.nl' })).toBe('x@y.nl')
  })

  it('avatar colour is stable per address', () => {
    expect(avatarColor('a@b.nl')).toBe(avatarColor('a@b.nl'))
    expect(avatarColor('a@b.nl')).toMatch(/^#[0-9A-F]{6}$/i)
  })
})
