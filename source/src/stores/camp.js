import { defineStore } from 'pinia'
import { ref } from 'vue'
import { camps } from '@/api.js'

export const useCampStore = defineStore('camp', () => {
  const active = ref(null)
  const list = ref([])

  async function loadActive() {
    try {
      const r = await camps.active()
      active.value = r.camp || null
    } catch { active.value = null }
  }

  async function loadAll() {
    try {
      const r = await camps.list()
      list.value = r.camps || []
    } catch { list.value = [] }
  }

  return { active, list, loadActive, loadAll }
})
