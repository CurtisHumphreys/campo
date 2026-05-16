<template>
  <div class="p-4 space-y-4 animate-fade-in">
    <h2 class="text-xl font-bold text-ink-100">Site Waitlist</h2>
    <p class="text-sm text-ink-400">Add your name to the waiting list for a permanent camp site.</p>

    <!-- Success state -->
    <div v-if="submitted" class="card p-6 text-center space-y-3">
      <div class="text-4xl">✅</div>
      <h3 class="font-semibold text-ink-100">You're on the list!</h3>
      <p class="text-sm text-ink-400">We'll be in touch when a site becomes available.</p>
      <button @click="reset" class="btn btn-secondary">Submit Another</button>
    </div>

    <!-- Form -->
    <form v-else @submit.prevent="submit" class="space-y-4">

      <!-- Name -->
      <div class="grid grid-cols-2 gap-3">
        <div>
          <label class="field-label">First Name *</label>
          <input v-model="form.first_name" required placeholder="Jane" />
        </div>
        <div>
          <label class="field-label">Last Name *</label>
          <input v-model="form.last_name" required placeholder="Smith" />
        </div>
      </div>

      <!-- Phone -->
      <div>
        <label class="field-label">Phone *</label>
        <input v-model="form.phone" type="tel" required placeholder="04xx xxx xxx" />
      </div>

      <!-- Home assembly -->
      <div>
        <label class="field-label">Home Assembly</label>
        <input v-model="form.home_assembly" placeholder="e.g. Adelaide City" />
      </div>

      <!-- Site type -->
      <div>
        <label class="field-label">Site Type Preference</label>
        <select v-model="form.site_type">
          <option value="Powered Site">Powered Site</option>
          <option value="Unpowered Site">Unpowered Site</option>
          <option value="Dorms (KFC)">Dorms (KFC)</option>
          <option value="Family Room">Family Room</option>
          <option value="No preference">No preference</option>
        </select>
      </div>

      <!-- Occupants -->
      <div class="grid grid-cols-2 gap-3">
        <div>
          <label class="field-label">Adults</label>
          <input v-model.number="form.adults" type="number" min="1" max="20" />
        </div>
        <div>
          <label class="field-label">Children</label>
          <input v-model.number="form.kids" type="number" min="0" max="20" />
        </div>
      </div>

      <!-- Intended stay -->
      <div>
        <label class="field-label">How many days do you typically stay?</label>
        <input v-model="form.intended_days" placeholder="e.g. 10" />
      </div>

      <!-- Special considerations -->
      <div>
        <label class="field-label">Special Considerations</label>
        <textarea v-model="form.special_considerations" rows="2" class="resize-none"
          placeholder="Accessibility needs, medical requirements, etc." />
      </div>

      <!-- Overflow -->
      <div>
        <label class="field-label">Would you consider an overflow site if one becomes available first?</label>
        <select v-model="form.overflow_willing">
          <option value="Yes">Yes</option>
          <option value="No">No</option>
        </select>
      </div>

      <!-- Comments -->
      <div>
        <label class="field-label">Additional Comments</label>
        <textarea v-model="form.additional_comments" rows="3" class="resize-none"
          placeholder="Anything else you'd like us to know…" />
      </div>

      <button type="submit" :disabled="submitting" class="btn btn-primary w-full btn-lg">
        {{ submitting ? 'Submitting…' : 'Join Waitlist' }}
      </button>
    </form>
  </div>
</template>

<script setup>
import { ref, inject } from 'vue'
import { publicApi } from '@/api.js'

const toast = inject('toast')
const submitting = ref(false)
const submitted  = ref(false)

const blank = () => ({
  first_name: '', last_name: '', phone: '', home_assembly: '',
  site_type: 'Powered Site', adults: 1, kids: 0,
  intended_days: '', special_considerations: '',
  overflow_willing: 'No', additional_comments: '',
})
const form = ref(blank())

async function submit() {
  submitting.value = true
  try {
    await publicApi.submitWaitlist(form.value)
    submitted.value = true
  } catch {
    toast?.add('Failed to submit — please try again', 'error')
  } finally {
    submitting.value = false
  }
}

function reset() {
  form.value = blank()
  submitted.value = false
}
</script>
