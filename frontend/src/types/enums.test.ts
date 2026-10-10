import { describe, expect, it } from 'vitest'
import {
  BUSINESS_TYPE_DESCRIPTIONS,
  BUSINESS_TYPE_LABELS,
  BUSINESS_TYPE_VALUES,
  INVENTORY_BUSINESS_TYPES,
  businessTypeLabel,
  catalogLabel,
  usesInventory,
} from './enums'

describe('business types', () => {
  it('exposes exactly the two canonical groups', () => {
    expect(BUSINESS_TYPE_VALUES).toEqual(['retail', 'service'])
    expect(Object.keys(BUSINESS_TYPE_LABELS)).toEqual(['retail', 'service'])
  })

  it('labels the groups in natural Indonesian', () => {
    expect(businessTypeLabel('retail')).toBe('Toko & Penjualan')
    expect(businessTypeLabel('service')).toBe('Jasa & Servis')
  })

  it('only the retail group uses inventory', () => {
    expect(usesInventory('retail')).toBe(true)
    expect(usesInventory('service')).toBe(false)
    expect(INVENTORY_BUSINESS_TYPES).toEqual(['retail'])
  })

  it('adapts the catalog label to the group', () => {
    expect(catalogLabel('retail')).toBe('Produk')
    expect(catalogLabel('service')).toBe('Layanan')
  })

  it('has a description for each group', () => {
    expect(BUSINESS_TYPE_DESCRIPTIONS.retail).toContain('produk')
    expect(BUSINESS_TYPE_DESCRIPTIONS.service).toContain('layanan')
  })
})
