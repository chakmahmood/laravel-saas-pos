import 'vue-router'

declare module 'vue-router' {
  interface RouteMeta {
    title?: string
    requiresAuth?: boolean
    requiresStore?: boolean
    guestOnly?: boolean
    moduleTitle?: string
    moduleDescription?: string
    moduleStage?: string
  }
}
