import { describe, it, expect, vi } from 'vitest'

vi.mock('virtual:pwa-register', () => ({ registerSW: () => () => {} }))
vi.mock('./api/client.js', () => ({ api: { get: vi.fn() } }))
const { isNewer } = await import('./appUpdate.js')

describe('isNewer', () => {
  it('compares versions as numbers', () => {
    expect(isNewer('1.6.1', '1.6.0')).toBe(true)
    expect(isNewer('1.10.0', '1.9.9')).toBe(true)
    expect(isNewer('1.6.0', '1.6.0')).toBe(false)
    expect(isNewer('1.5.9', '1.6.0')).toBe(false)
    expect(isNewer('2.0', '1.99.99')).toBe(true)
  })
})
