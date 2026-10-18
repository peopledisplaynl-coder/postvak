import { describe, it, expect } from 'vitest'
import { ymd, parseYmd, addMonths, startOfWeek, monthGrid, daysOf, byDay, layoutDay, nextHalfHour, taskGroup } from './calendar.js'

describe('calendar helpers', () => {
  it('formats and parses local dates', () => {
    expect(ymd(new Date(2026, 9, 5))).toBe('2026-10-05')
    expect(parseYmd('2026-02-28').getDate()).toBe(28)
  })

  it('adds months without overflowing short months', () => {
    expect(ymd(addMonths(new Date(2026, 0, 31), 1))).toBe('2026-02-28')
    expect(ymd(addMonths(new Date(2026, 11, 15), 1))).toBe('2027-01-15')
  })

  it('weeks start on Monday and the month grid has 42 days', () => {
    expect(ymd(startOfWeek(new Date(2026, 9, 18)))).toBe('2026-10-12') // Sunday → Monday before
    expect(ymd(startOfWeek(new Date(2026, 9, 19)))).toBe('2026-10-19')
    const grid = monthGrid(new Date(2026, 9, 18))
    expect(grid).toHaveLength(42)
    expect(ymd(grid[0])).toBe('2026-09-28')
  })

  it('all-day events cover their days, end exclusive', () => {
    expect(daysOf({ all_day: true, start: '2026-10-24', end: '2026-10-26' })).toEqual(['2026-10-24', '2026-10-25'])
    expect(daysOf({ all_day: true, start: '2026-10-24', end: '2026-10-25' })).toEqual(['2026-10-24'])
  })

  it('timed events over midnight appear on both days, ending at midnight on one', () => {
    const late = { all_day: false, start: new Date(2026, 9, 20, 22, 0).toISOString(), end: new Date(2026, 9, 21, 1, 0).toISOString() }
    expect(daysOf(late)).toEqual(['2026-10-20', '2026-10-21'])
    const toMidnight = { all_day: false, start: new Date(2026, 9, 20, 22, 0).toISOString(), end: new Date(2026, 9, 21, 0, 0).toISOString() }
    expect(daysOf(toMidnight)).toEqual(['2026-10-20'])
  })

  it('sorts a day with all-day events first', () => {
    const map = byDay([
      { title: 'b', all_day: false, start: new Date(2026, 9, 20, 9).toISOString(), end: new Date(2026, 9, 20, 10).toISOString() },
      { title: 'a', all_day: true, start: '2026-10-20', end: '2026-10-21' },
    ])
    expect(map.get('2026-10-20').map((e) => e.title)).toEqual(['a', 'b'])
  })

  it('puts overlapping events side by side', () => {
    const at = (h, m = 0) => new Date(2026, 9, 20, h, m).toISOString()
    const items = layoutDay([
      { title: 'a', all_day: false, start: at(9), end: at(11) },
      { title: 'b', all_day: false, start: at(10), end: at(12) },
      { title: 'c', all_day: false, start: at(13), end: at(14) },
    ], '2026-10-20')
    const byTitle = Object.fromEntries(items.map((i) => [i.ev.title, i]))
    expect(byTitle.a.top).toBe(540)
    expect(byTitle.a.height).toBe(120)
    expect([byTitle.a.col, byTitle.b.col]).toEqual([0, 1])
    expect(byTitle.a.cols).toBe(2)
    expect(byTitle.c.cols).toBe(1)
  })

  it('rounds to the next half hour', () => {
    expect(nextHalfHour(new Date(2026, 9, 20, 9, 10)).getMinutes()).toBe(30)
    expect(nextHalfHour(new Date(2026, 9, 20, 9, 40)).getHours()).toBe(10)
  })

  it('groups tasks by due date', () => {
    const now = new Date(2026, 9, 20, 12, 0)
    expect(taskGroup({ due: null }, now)).toBe('none')
    expect(taskGroup({ due: '2026-10-19' }, now)).toBe('overdue')
    expect(taskGroup({ due: '2026-10-20' }, now)).toBe('today')
    expect(taskGroup({ due: '2026-10-20T09:00' }, now)).toBe('overdue')
    expect(taskGroup({ due: '2026-10-21' }, now)).toBe('later')
  })
})
