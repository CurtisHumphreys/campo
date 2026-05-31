<template>
  <div class="min-h-dvh bg-surface-800 flex">
    <!-- Sidebar (desktop) -->
    <aside v-if="auth.isAuth"
      class="hidden lg:flex flex-col w-60 flex-none bg-surface-900 border-r border-surface-600
             sticky top-0 h-screen overflow-y-auto">
      <!-- Logo -->
      <div class="flex items-center gap-3 px-5 py-5 border-b border-surface-700">
        <div class="w-9 h-9 rounded-xl bg-ember-500 flex items-center justify-center shadow-ember">
          <span class="text-surface-900 font-black text-sm">C</span>
        </div>
        <div>
          <div class="font-bold text-ink-100 text-sm leading-none">CAMPO</div>
          <div class="text-[10px] text-ink-500 leading-none mt-0.5">Admin</div>
        </div>
      </div>

      <!-- Take Payments CTA -->
      <div v-if="['full_admin','admin'].includes(auth.user?.role)" class="px-3 pt-3 pb-1">
        <RouterLink to="/payments"
          class="flex items-center justify-center gap-2 w-full py-2.5 rounded-xl font-semibold text-sm
                 transition-all bg-ember-500 text-surface-900 hover:bg-ember-400 shadow-ember">
          💳 Take Payments
        </RouterLink>
      </div>

      <!-- Nav groups -->
      <nav class="flex-1 px-3 py-4 space-y-1">
        <template v-for="group in navGroups" :key="group.label">
          <div v-if="canSeeGroup(group)" class="mb-4">
            <div class="section-label px-3 mb-1">{{ group.label }}</div>
            <template v-for="item in group.items.filter(canSeeItem)" :key="item.href || item.path">
              <button v-if="item.action" @click="handleAction(item.action)"
                class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-sm font-medium w-full
                       transition-all duration-100 text-ink-400 hover:text-ink-200 hover:bg-surface-700">
                <span class="text-base">{{ item.icon }}</span>{{ item.label }}
              </button>
              <a v-else-if="item.href" :href="item.href" target="_blank"
                class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-sm font-medium
                       transition-all duration-100 text-ink-400 hover:text-ink-200 hover:bg-surface-700">
                <span class="text-base">{{ item.icon }}</span>{{ item.label }}
              </a>
              <RouterLink v-else :to="item.path"
                class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-sm font-medium
                       transition-all duration-100 group"
                :class="isActive(item.path)
                  ? 'bg-ember-500/15 text-ember-400 border border-ember-500/20'
                  : 'text-ink-400 hover:text-ink-200 hover:bg-surface-700'">
                <span class="text-base">{{ item.icon }}</span>
                {{ item.label }}
              </RouterLink>
            </template>
          </div>
        </template>
      </nav>

      <!-- User footer -->
      <div class="px-4 py-4 border-t border-surface-700">
        <div class="flex items-center gap-3 mb-3">
          <div class="w-8 h-8 rounded-xl bg-surface-600 flex items-center justify-center
                      text-xs font-bold text-ember-400">
            {{ auth.user?.username?.[0]?.toUpperCase() }}
          </div>
          <div class="flex-1 min-w-0">
            <div class="text-sm font-medium text-ink-200 truncate">{{ auth.user?.username }}</div>
            <div class="text-xs text-ink-500 capitalize">{{ auth.user?.role?.replace('_',' ') }}</div>
          </div>
        </div>
        <button @click="doLogout" class="btn btn-ghost w-full text-sm justify-start gap-2 text-ink-500">
          <span>↩</span> Sign out
        </button>
      </div>
    </aside>

    <!-- Mobile top bar (title only — navigation lives in the bottom bar) -->
    <div v-if="auth.isAuth" class="lg:hidden fixed top-0 left-0 right-0 z-40
      bg-surface-900/90 backdrop-blur-md border-b border-surface-600 flex items-center
      gap-2 px-4 py-3 pt-safe">
      <div class="w-7 h-7 rounded-lg bg-ember-500 flex items-center justify-center">
        <span class="text-surface-900 font-black text-xs">C</span>
      </div>
      <span class="font-bold text-sm text-ink-100">CAMPO Admin</span>
    </div>

    <!-- Mobile menu drawer -->
    <Teleport to="body">
      <Transition name="drawer">
        <div v-if="mobileMenu && auth.isAuth"
          class="fixed inset-0 z-50 flex lg:hidden"
          @click.self="mobileMenu = false">
          <div class="absolute inset-0 bg-black/50" @click="mobileMenu = false" />
          <div class="relative w-72 bg-surface-900 h-full overflow-y-auto flex flex-col border-r border-surface-600">
            <div class="flex items-center justify-between px-5 py-4 border-b border-surface-700">
              <div class="flex items-center gap-2">
                <div class="w-8 h-8 rounded-xl bg-ember-500 flex items-center justify-center shadow-ember">
                  <span class="text-surface-900 font-black text-xs">C</span>
                </div>
                <span class="font-bold text-ink-100">CAMPO</span>
              </div>
              <button @click="mobileMenu = false" class="btn-ghost btn p-1 rounded-lg text-ink-400">✕</button>
            </div>
            <div v-if="['full_admin','admin'].includes(auth.user?.role)" class="px-3 pt-3 pb-1">
              <RouterLink to="/payments" @click="mobileMenu = false"
                class="flex items-center justify-center gap-2 w-full py-3 rounded-xl font-semibold text-sm
                       transition-all bg-ember-500 text-surface-900 hover:bg-ember-400 shadow-ember">
                💳 Take Payments
              </RouterLink>
            </div>
            <nav class="flex-1 px-3 py-4 space-y-1">
              <template v-for="group in navGroups" :key="group.label">
                <div v-if="canSeeGroup(group)" class="mb-4">
                  <div class="section-label px-3 mb-1">{{ group.label }}</div>
                  <template v-for="item in group.items.filter(canSeeItem)" :key="item.href || item.path">
                    <button v-if="item.action" @click="handleAction(item.action)"
                      class="flex items-center gap-3 px-3 py-3 rounded-xl text-sm font-medium w-full transition-all text-ink-400 hover:text-ink-200 hover:bg-surface-700">
                      <span class="text-base">{{ item.icon }}</span>{{ item.label }}
                    </button>
                    <a v-else-if="item.href" :href="item.href" target="_blank"
                      class="flex items-center gap-3 px-3 py-3 rounded-xl text-sm font-medium transition-all text-ink-400 hover:text-ink-200 hover:bg-surface-700">
                      <span class="text-base">{{ item.icon }}</span>{{ item.label }}
                    </a>
                    <RouterLink v-else :to="item.path" @click="mobileMenu = false"
                      class="flex items-center gap-3 px-3 py-3 rounded-xl text-sm font-medium transition-all"
                      :class="isActive(item.path) ? 'bg-ember-500/15 text-ember-400' : 'text-ink-400 hover:text-ink-200 hover:bg-surface-700'">
                      <span class="text-base">{{ item.icon }}</span>{{ item.label }}
                    </RouterLink>
                  </template>
                </div>
              </template>
            </nav>
            <div class="px-4 py-4 border-t border-surface-700">
              <button @click="doLogout" class="btn btn-ghost w-full text-sm">↩ Sign out</button>
            </div>
          </div>
        </div>
      </Transition>
    </Teleport>

    <!-- Mobile bottom tab bar -->
    <nav v-if="auth.isAuth" class="lg:hidden fixed bottom-0 left-0 right-0 z-40
      bg-surface-900/95 backdrop-blur-md border-t border-surface-600
      flex items-stretch justify-around px-1 pt-1 pb-safe">
      <RouterLink v-for="item in bottomNav.filter(canSeeBottom)" :key="item.path" :to="item.path"
        class="flex-1 flex flex-col items-center justify-center gap-0.5 py-1.5 rounded-lg transition-colors"
        :class="isActive(item.path) ? 'text-ember-400' : 'text-ink-500 hover:text-ink-300'">
        <span class="text-xl leading-none">{{ item.icon }}</span>
        <span class="text-[10px] font-medium leading-none">{{ item.label }}</span>
      </RouterLink>
      <button @click="mobileMenu = true"
        class="flex-1 flex flex-col items-center justify-center gap-0.5 py-1.5 rounded-lg transition-colors"
        :class="mobileMenu ? 'text-ember-400' : 'text-ink-500 hover:text-ink-300'">
        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/>
        </svg>
        <span class="text-[10px] font-medium leading-none">Menu</span>
      </button>
    </nav>

    <!-- Main content -->
    <main class="flex-1 min-w-0" :class="auth.isAuth ? 'pt-14 lg:pt-0 pb-24 lg:pb-0' : ''">
      <RouterView v-slot="{ Component }">
        <Transition name="page" mode="out-in">
          <component :is="Component" />
        </Transition>
      </RouterView>
    </main>

    <AppToast ref="toast" />
  </div>
</template>

<script setup>
import { ref, provide } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth.js'
import AppToast from '@/components/AppToast.vue'

const auth          = useAuthStore()
const route         = useRoute()
const router        = useRouter()
const toast         = ref(null)
const mobileMenu    = ref(false)

provide('toast', { add: (...a) => toast.value?.add(...a) })

const navGroups = [
  {
    label: 'Camp',
    roles: ['full_admin','admin'],
    items: [
      { path: '/camps',   label: 'Camps',   icon: '🏕️' },
      { path: '/members', label: 'Members', icon: '👥' },
      { path: '/rates',   label: 'Rates',   icon: '🏷️' },
      { path: '/sites',      label: 'Sites',      icon: '🏠' },
      { path: '/map',        label: 'Map',        icon: '🗺️' },
      { path: '/import',       label: 'Import',      icon: '📥' },
      { path: '/prepayments',  label: 'Prepayments', icon: '💰' },
      { path: '/payment-records', label: 'Payment Records', icon: '📊' },
      { path: '/camp-summary',    label: 'Camp Summary',    icon: '📋' },
    ]
  },
  {
    label: 'System',
    roles: ['full_admin'],
    items: [
      { path: '/square-sync',       label: 'Square Sync',       icon: '💳' },
      { path: '/settings',          label: 'Settings',          icon: '⚙️' },
      { path: '/feature-requests',  label: 'Feature Requests',  icon: '💡' },
    ]
  },
  {
    label: 'Intranet',
    roles: ['full_admin','admin','intranet_admin'],
    items: [
      { path: '/intranet-admin',                    label: 'Manage',          icon: '📝' },
      { href: 'https://campo.urbantek.online',      label: 'Open Intranet',   icon: '📲' },
    ]
  },
]

// Mobile bottom tab bar — quick access to the main operations pages.
// (The "Menu" button opens the full role-filtered drawer for everything else.)
const bottomNav = [
  { path: '/dashboard', label: 'Dashboard', icon: '📊', roles: ['full_admin','admin'] },
  { path: '/sites',     label: 'Sites',     icon: '🏠', roles: ['full_admin','admin'] },
  { path: '/map',       label: 'Map',       icon: '🗺️', roles: ['full_admin','admin'] },
  { path: '/members',   label: 'Members',   icon: '👥', roles: ['full_admin','admin'] },
]

function canSeeGroup(g)  { return g.roles.includes(auth.user?.role) }
function canSeeItem(item){ return true }
function canSeeBottom(item){ return item.roles.includes(auth.user?.role) }
function isActive(path)  { return route.path === path || route.path.startsWith(path + '/') }

function handleAction(action) {}

async function doLogout() {
  await auth.logout()
  router.push('/login')
}
</script>

<style scoped>
.page-enter-active, .page-leave-active { transition: opacity .15s ease; }
.page-enter-from, .page-leave-to { opacity: 0; }
.drawer-enter-active, .drawer-leave-active { transition: all .25s ease; }
.drawer-enter-from, .drawer-leave-to { opacity: 0; }
.drawer-enter-from .relative, .drawer-leave-to .relative { transform: translateX(-100%); }
</style>
