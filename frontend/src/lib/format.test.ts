import { describe, expect, it } from 'vitest'
import { formatCurrency, formatCurrencyInput, formatQuantity, parseCurrencyInput } from './format'

describe('currency helpers', () => {
  it('parses grouped and raw input into integer rupiah', () => {
    expect(parseCurrencyInput('Rp 10.000')).toBe(10000)
    expect(parseCurrencyInput('10.000')).toBe(10000)
    expect(parseCurrencyInput('10000')).toBe(10000)
    expect(parseCurrencyInput('0')).toBe(0)
    expect(parseCurrencyInput('')).toBeNull()
    expect(parseCurrencyInput('abc')).toBeNull()
  })

  it('formats an integer for an editable input (no currency symbol)', () => {
    expect(formatCurrencyInput(10000)).toBe('10.000')
    expect(formatCurrencyInput(0)).toBe('0')
    expect(formatCurrencyInput(null)).toBe('')
  })

  it('round-trips an input value without corrupting the payload', () => {
    expect(parseCurrencyInput(formatCurrencyInput(1500000))).toBe(1500000)
  })

  it('formats display currency for Indonesia', () => {
    expect(formatCurrency(18000).replace(/\s/g, '')).toContain('Rp18.000')
    expect(formatCurrency(null)).toBe('—')
  })

  it('formats quantity with up to 3 decimals', () => {
    expect(formatQuantity('1.125')).toBe('1,125')
    expect(formatQuantity(null)).toBe('—')
  })
})
