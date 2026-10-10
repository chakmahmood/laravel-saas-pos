export interface Category {
  id: number
  name: string
  description: string | null
  is_active: boolean
  created_at: string | null
  updated_at: string | null
}

export interface CategoryListParams {
  page?: number
  per_page?: number
  search?: string
  is_active?: boolean
  sort?: 'name' | 'created_at'
  direction?: 'asc' | 'desc'
}

export interface CategoryPayload {
  name: string
  description: string | null
  is_active: boolean
}
