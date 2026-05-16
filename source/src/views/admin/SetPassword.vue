<template>
  <div class="min-h-dvh bg-surface-900 flex items-center justify-center p-4">
    <div class="w-full max-w-sm">
      <div class="text-center mb-8">
        <div class="w-16 h-16 rounded-2xl bg-ember-500 flex items-center justify-center
                    shadow-ember mx-auto mb-4">
          <span class="text-surface-900 font-black text-2xl">C</span>
        </div>
        <h1 class="text-2xl font-bold text-ink-100">CAMPO</h1>
        <p class="text-ink-500 text-sm mt-1">{{ pageSubtitle }}</p>
      </div>

      <!-- Loading state -->
      <div v-if="checking" class="card p-6 text-center">
        <div class="w-8 h-8 border-2 border-ember-400 border-t-transparent rounded-full animate-spin mx-auto mb-3"></div>
        <p class="text-sm text-ink-400">Validating link…</p>
      </div>

      <!-- Invalid token -->
      <div v-else-if="tokenError" class="card p-6 text-center space-y-4">
        <div class="w-12 h-12 rounded-full bg-red-500/20 flex items-center justify-center mx-auto">
          <svg class="w-6 h-6 text-red-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
          </svg>
        </div>
        <div>
          <p class="font-medium text-ink-100">Link invalid or expired</p>
          <p class="text-sm text-ink-400 mt-1">{{ tokenError }}</p>
        </div>
        <router-link to="/login" class="btn btn-ghost w-full block text-center">Back to sign in</router-link>
      </div>

      <!-- Success -->
      <div v-else-if="done" class="card p-6 text-center space-y-4">
        <div class="w-12 h-12 rounded-full bg-green-500/20 flex items-center justify-center mx-auto">
          <svg class="w-6 h-6 text-green-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
          </svg>
        </div>
        <div>
          <p class="font-medium text-ink-100">Password set!</p>
          <p class="text-sm text-ink-400 mt-1">You can now sign in with your new password.</p>
        </div>
        <router-link to="/login" class="btn btn-primary w-full block text-center">Sign in</router-link>
      </div>

      <!-- Set password form -->
      <form v-else @submit.prevent="submit" class="card p-6 space-y-4">
        <p class="text-sm text-ink-400">
          {{ isActivation ? `Welcome, ${username}! Choose a password to activate your account.` : `Choose a new password for ${username}.` }}
        </p>
        <div>
          <label class="field-label">New password</label>
          <input v-model="password" type="password" autocomplete="new-password"
            placeholder="At least 8 characters" required minlength="8" />
        </div>
        <div>
          <label class="field-label">Confirm password</label>
          <input v-model="confirm" type="password" autocomplete="new-password"
            placeholder="Repeat your password" required />
        </div>
        <p v-if="error" class="text-red-400 text-sm">{{ error }}</p>
        <button type="submit" :disabled="loading" class="btn btn-primary w-full">
          {{ loading ? 'Saving…' : (isActivation ? 'Activate account' : 'Reset password') }}
        </button>
      </form>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { useRoute } from 'vue-router'
import { auth as authApi } from '@/api.js'

const route    = useRoute()
const token    = route.query.token || ''

const checking    = ref(true)
const tokenError  = ref('')
const tokenType   = ref('')
const username    = ref('')
const password    = ref('')
const confirm     = ref('')
const error       = ref('')
const loading     = ref(false)
const done        = ref(false)

const isActivation = computed(() => tokenType.value === 'activation')
const pageSubtitle = computed(() => {
  if (tokenType.value === 'activation') return 'Activate Account'
  if (tokenType.value === 'password_reset') return 'Reset Password'
  return 'Set Password'
})

onMounted(async () => {
  if (!token) {
    tokenError.value = 'No token found in the link. Please check your email and try again.'
    checking.value = false
    return
  }
  try {
    const res = await authApi.tokenCheck(token)
    tokenType.value = res.type
    username.value  = res.username
  } catch (e) {
    tokenError.value = e.data?.message || 'This link has expired or is invalid.'
  } finally {
    checking.value = false
  }
})

async function submit() {
  error.value = ''
  if (password.value !== confirm.value) {
    error.value = 'Passwords do not match.'
    return
  }
  if (password.value.length < 8) {
    error.value = 'Password must be at least 8 characters.'
    return
  }
  loading.value = true
  try {
    if (isActivation.value) {
      await authApi.activate(token, password.value)
    } else {
      await authApi.completeReset(token, password.value)
    }
    done.value = true
  } catch (e) {
    error.value = e.data?.message || 'Something went wrong. Please try again.'
  } finally {
    loading.value = false
  }
}
</script>
