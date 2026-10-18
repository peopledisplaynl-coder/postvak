import { describe, it, expect, afterEach } from 'vitest'
import { t, setLanguage, language, locale, languages } from './index.js'

describe('translations', () => {
  afterEach(() => setLanguage('nl'))

  it('fills in placeholders', () => {
    setLanguage('nl')
    expect(t('toast.movedMany', { n: 3, folder: 'Archief' })).toBe('3 berichten verplaatst naar Archief')
    setLanguage('en')
    expect(t('toast.movedMany', { n: 3, folder: 'Archive' })).toBe('3 messages moved to Archive')
  })

  it('switches locale with the language', () => {
    setLanguage('en')
    expect(language()).toBe('en')
    expect(locale()).toBe('en-GB')
    setLanguage('nl')
    expect(locale()).toBe('nl-NL')
  })

  it('unknown setting falls back to the browser language (English without a browser)', () => {
    setLanguage('auto')
    expect(Object.keys(languages)).toContain(language())
  })

  it('missing key returns the key itself', () => {
    expect(t('does.not.exist')).toBe('does.not.exist')
  })
})
