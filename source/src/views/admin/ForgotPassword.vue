<template>
  <div class="min-h-dvh bg-surface-900 flex items-center justify-center p-4">
    <div class="w-full max-w-sm">
      <div class="text-center mb-8">
        <div class="w-16 h-16 rounded-2xl bg-ember-500 flex items-center justify-center
                    shadow-ember mx-auto mb-4">
          <span class="text-surface-900 font-black text-2xl">C</span>
        </div>
        <h1 class="text-2xl font-bold text-ink-100">CAMPO</h1>
        <p class="text-ink-500 text-sm mt-1">Password Reset</p>
      </div>

      <div v-if="sent" class="card p-6 text-center space-y-4">
        <div class="w-12 h-12 rounded-full bg-green-500/20 flex items-center justify-center mx-auto">
          <svg class="w-6 h-6 text-green-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
          </svg>
        </div>
        <div>
          <p class="font-medium text-ink-100">Check your email</p>
          <p class="text-sm text-ink-400 mt-1">
            If that username has an email address on file, a reset link has been sent. It expires in 1 hour.
          </p>
        </div>
        <router-link to="/login" class="btn btn-ghost w-full block text-center">Back to sign in</router-link>
      </div>

      <form v-else @submit.prevent="submit" class="card p-6 space-y-4">
        <p class="text-sm text-ink-400">Enter your username and we'll send a reset link to your registered email address.</p>
        <div>
          <label class="field-label">Username</label>
          <input v-model="username" type="text" autocomplete="username"
            placeholder="Your username" required />
        </div>
        <p v-if="error" class="text-red-400 text-sm">{{ error }}</p>
        <button type="submit" :disabled="loading" class="btn btn-primary w-full">
          {{ loading ? 'Sending…' : 'Send reset link' }}
        </button>
        <div class="text-center">
          <router-link to="/login" class="text-sm text-ink-500 hover:text-ink-300 transition-colors">
            Back to sign in
          </router-link>
        </div>
      </form>
    </div>
  </div>
</template>

<script setup>
import { ref } from 'vue'
import { auth as authApi } from '@/api.js'

const username = ref('')
const loading  = ref(false)
const error    = ref('')
const sent     = ref(false)

async function submit() {
  error.value   = ''
  loading.value = true
  try {
    await authApi.requestReset(username.value)
    sent.value = true
  } catch (e) {
    error.value = e.data?.message || 'Something went wrong. Please try again.'
  } finally {
    loading.value = false
  }
}
</script>
