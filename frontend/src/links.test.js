import { describe, it, expect } from 'vitest'
import { describeLink, baseDomain, shownHost } from './links.js'

describe('links', () => {
  it('finds the real destination and the base domain', () => {
    const l = describeLink('https://mail.ing.nl/inloggen?x=1', 'Log in')
    expect(l.host).toBe('mail.ing.nl')
    expect(l.mismatch).toBe(null)
    expect(baseDomain('www.bbc.co.uk')).toBe('bbc.co.uk')
  })
  it('flags a link whose text shows another web address', () => {
    expect(describeLink('https://ing-veilig.example/login', 'www.ing.nl').mismatch).toBe('www.ing.nl')
    expect(describeLink('https://www.ing.nl/login', 'https://ing.nl').mismatch).toBe(null)
    expect(describeLink('https://click.mailing.example/r/123', 'Bekijk de aanbieding').mismatch).toBe(null)
  })
  it('handles mail links and plain text', () => {
    expect(describeLink('mailto:info@ing.nl', 'Mail ons').kind).toBe('mail')
    expect(shownHost('Klik hier')).toBe(null)
    expect(shownHost(' ing.nl/zakelijk ')).toBe('ing.nl')
  })
})
