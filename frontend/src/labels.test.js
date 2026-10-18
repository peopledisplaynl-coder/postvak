import { describe, it, expect } from 'vitest'
import { labelName, labelsOfMessage, newLabelKey } from './labels.js'
import { setLanguage } from './i18n/index.js'

describe('labels', () => {
  it('default labels get a translated name, own labels keep theirs', () => {
    setLanguage('nl')
    expect(labelName({ key: '$label1', name: '' })).toBe('Belangrijk')
    expect(labelName({ key: '$Label4', name: '' })).toBe('Te doen')
    expect(labelName({ key: '$label1', name: 'Urgent' })).toBe('Urgent')
    expect(labelName({ key: 'pv_klant', name: '' })).toBe('pv_klant')
  })
  it('labels of a message follow the user order and ignore unknown keywords', () => {
    const settings = { labels: [{ key: '$label2', name: '', color: '#000000' }, { key: '$label1', name: '', color: '#111111' }] }
    expect(labelsOfMessage(settings, { labels: ['$label1', '$label2', 'other'] }).map((l) => l.key)).toEqual(['$label2', '$label1'])
    expect(labelsOfMessage(settings, {})).toEqual([])
  })
  it('new keys are IMAP atoms and unique', () => {
    expect(newLabelKey('Klant Één!', [])).toBe('pv_klant_een')
    expect(newLabelKey('Klant', [{ key: 'pv_klant' }])).toBe('pv_klant_2')
    expect(newLabelKey('!!!', [])).toBe('pv_label')
  })
})
