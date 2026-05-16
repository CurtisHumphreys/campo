<template>
  <div class="p-4 space-y-4 animate-fade-in">
    <h2 class="text-xl font-bold text-ink-100">Ask Admin</h2>
    <p class="text-sm text-ink-400">Have a question or need help? Send a message to the camp team.</p>
    <div v-if="sent" class="card p-6 text-center">
      <div class="text-4xl mb-3">✉️</div>
      <h3 class="font-semibold text-ink-100 mb-1">Message Sent</h3>
      <p class="text-sm text-ink-400 mb-4">The camp team will get back to you.</p>
      <button @click="sent = false" class="btn btn-secondary">Send Another</button>
    </div>
    <form v-else @submit.prevent="submit" class="space-y-4">
      <div><label class="field-label">Your Name</label><input v-model="form.submitter_name" required /></div>
      <div><label class="field-label">Site Number</label><input v-model="form.site_number" required /></div>
      <div>
        <label class="field-label">Category</label>
        <select v-model="form.category">
          <option v-for="c in categories" :key="c">{{ c }}</option>
        </select>
      </div>
      <div><label class="field-label">Message</label><textarea v-model="form.message" required rows="5" class="w-full resize-none" placeholder="What would you like to ask?" /></div>
      <button type="submit" :disabled="submitting" class="btn btn-primary w-full btn-lg">{{ submitting ? 'Sending...' : 'Send Message' }}</button>
    </form>
  </div>
</template>
<script setup>
import { ref, inject } from 'vue'
import { publicApi } from '@/api.js'
const toast = inject('toast')
const submitting = ref(false), sent = ref(false)
const categories = ['General Question','Lost & Found','Maintenance','Medical','Other']
const form = ref({ submitter_name:'', site_number:'', category:'General Question', message:'' })
async function submit() {
  submitting.value = true
  try { await publicApi.submitMessage(form.value); sent.value = true; form.value = { submitter_name:'', site_number:'', category:'General Question', message:'' } }
  catch { toast.add('Failed to send message', 'error') }
  finally { submitting.value = false }
}
</script>
