import { createRouter, createWebHistory } from 'vue-router'
import { useAuthStore } from '@/stores/auth.js'

const routes = [
  { path: '/',           redirect: '/dashboard' },
  { path: '/login',           component: () => import('@/views/admin/Login.vue'),          name: 'login',           meta: { public: true } },
  { path: '/forgot-password', component: () => import('@/views/admin/ForgotPassword.vue'), name: 'forgot-password', meta: { public: true } },
  { path: '/activate',        component: () => import('@/views/admin/SetPassword.vue'),    name: 'activate',        meta: { public: true } },
  { path: '/reset-password',  component: () => import('@/views/admin/SetPassword.vue'),   name: 'reset-password',  meta: { public: true } },
  { path: '/dashboard',  component: () => import('@/views/admin/Dashboard.vue'), name: 'dashboard' },
  { path: '/camps',      component: () => import('@/views/admin/Camps.vue'),     name: 'camps' },
  { path: '/members',    component: () => import('@/views/admin/Members.vue'),   name: 'members' },
  { path: '/rates',      component: () => import('@/views/admin/Rates.vue'),     name: 'rates' },
  { path: '/sites',      component: () => import('@/views/admin/Sites.vue'),       name: 'sites' },
  { path: '/allocation', component: () => import('@/views/admin/Allocation.vue'),  name: 'allocation' },
  { path: '/import',       component: () => import('@/views/admin/Import.vue'),       name: 'import' },
  { path: '/map',          component: () => import('@/views/admin/MapAdmin.vue'),     name: 'map' },
  { path: '/payments',         component: () => import('@/views/admin/Payments.vue'),       name: 'payments' },
  { path: '/payment-records', component: () => import('@/views/admin/PaymentRecords.vue'), name: 'payment-records' },
  { path: '/camp-summary',       component: () => import('@/views/admin/CampSummary.vue'),       name: 'camp-summary' },
  { path: '/feature-requests',   component: () => import('@/views/admin/FeatureRequests.vue'), name: 'feature-requests' },
  { path: '/prepayments',  component: () => import('@/views/admin/Prepayments.vue'), name: 'prepayments' },
  { path: '/intranet-admin', component: () => import('@/views/admin/IntranetAdmin.vue'), name: 'intranet-admin' },
  { path: '/square-sync',   component: () => import('@/views/admin/SquareSync.vue'),   name: 'square-sync' },
  { path: '/settings',      component: () => import('@/views/admin/Settings.vue'),      name: 'settings' },
  { path: '/:pathMatch(.*)*', redirect: '/dashboard' }
]

const router = createRouter({ history: createWebHistory(), routes })

router.beforeEach(async (to) => {
  if (to.meta.public) return true
  const auth = useAuthStore()
  if (!auth.checked) await auth.check()
  if (!auth.isAuth) return '/login'
})

export default router
