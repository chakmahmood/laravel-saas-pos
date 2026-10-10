import type { ItemType } from '@/types/enums'

export interface Product {
  id: number
  category_id: number | null
  name: string
  type: ItemType
  sku: string | null
  barcode: string | null
  description: string | null
  cost_price: number | null
  selling_price: number
  unit: string
  is_active: boolean
  tracks_stock: boolean
  created_at: string | null
  updated_at: string | null
}

export interface ProductListParams {
  page?: number
  per_page?: number
  search?: string
  type?: ItemType
  category_id?: number
  is_active?: boolean
  sort?: 'name' | 'created_at' | 'selling_price'
  direction?: 'asc' | 'desc'
}

export interface ProductPayload {
  name: string
  type: ItemType
  category_id: number | null
  sku: string | null
  barcode: string | null
  description: string | null
  cost_price: number | null
  selling_price: number
  unit: string
  is_active: boolean
  /** Only sent when the store supports inventory. */
  tracks_stock?: boolean
}
