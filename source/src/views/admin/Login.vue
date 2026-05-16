<template>
  <div class="min-h-dvh bg-surface-900 flex items-center justify-center p-4">
    <div class="w-full max-w-sm">
      <!-- Logo -->
      <div class="text-center mb-8">
        <div class="w-16 h-16 rounded-2xl bg-ember-500 flex items-center justify-center
                    shadow-ember mx-auto mb-4">
          <span class="text-surface-900 font-black text-2xl">C</span>
        </div>
        <h1 class="text-2xl font-bold text-ink-100">CAMPO</h1>
        <p class="text-ink-500 text-sm mt-1">Admin Portal</p>
      </div>

      <!-- Form -->
      <form @submit.prevent="submit" class="card p-6 space-y-4">
        <div>
          <label class="field-label">Username</label>
          <input v-model="form.username" type="text" autocomplete="username"
            placeholder="Enter username" required />
        </div>
        <div>
          <label class="field-label">Password</label>
          <input v-model="form.password" type="password" autocomplete="current-password"
            placeholder="Enter password" required />
        </div>
        <p v-if="error" class="text-red-400 text-sm">{{ error }}</p>
        <button type="submit" :disabled="loading"
          class="btn btn-primary w-full">
          {{ loading ? 'Signing in…' : 'Sign in' }}
        </button>
      </form>
      <p class="text-center mt-4">
        <router-link to="/forgot-password" class="text-sm text-ink-500 hover:text-ink-300 transition-colors">
          Forgot your password?
        </router-link>
      </p>
    </div>
  </div>
</template>

<script setup>
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth.js'

const auth   = useAuthStore()
const router = useRouter()

const form    = ref({ username: '', password: '' })
const error   = ref('')
const loading = ref(false)

async function submit() {
  error.value   = ''
  loading.value = true
  try {
    await auth.login(form.value.username, form.value.password)
    router.push('/dashboard')
  } catch (e) {
    error.value = e.message || 'Invalid credentials'
  } finally {
    loading.value = false
  }
}
</script>
