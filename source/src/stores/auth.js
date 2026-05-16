import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import { auth as authApi } from '@/api.js'

export const useAuthStore = defineStore('auth', () => {
  const user = ref(null)
  const checked = ref(false)

  const isAuth        = computed(() => !!user.value)
  const isFullAdmin   = computed(() => user.value?.role === 'full_admin')
  const isAdmin       = computed(() => ['full_admin','admin'].includes(user.value?.role))
  const isIntranetAdmin = computed(() => !!user.value)

  async function check() {
    try {
      const r = await authApi.check()
      user.value = r.authenticated ? r.user : null
    } catch { user.value = null }
    checked.value = true
    return user.value
  }

  async function login(username, password) {
    const r = await authApi.login(username, password)
    if (r.success) user.value = r.user
    return r
  }

  async function logout() {
    await authApi.logout()
    user.value = null
  }

  return { user, checked, isAuth, isFullAdmin, isAdmin, isIntranetAdmin, check, login, logout }
})
