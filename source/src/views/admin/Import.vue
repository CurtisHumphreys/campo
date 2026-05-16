<template>
  <div class="p-6 max-w-3xl mx-auto space-y-6">
    <div>
      <h1 class="text-2xl font-bold text-ink-100">Import Data</h1>
      <p class="text-ink-500 text-sm mt-0.5">Bulk import members and sites from CSV files</p>
    </div>

    <!-- Members import -->
    <div class="card p-5 space-y-4">
      <div class="flex items-center gap-3">
        <span class="text-2xl">👥</span>
        <div>
          <div class="font-semibold text-ink-200">Import Members</div>
          <div class="text-xs text-ink-500 mt-0.5">
            Columns: <code class="text-ember-400">first_name, last_name, household_name, member_type, gender, mobile, email</code>
          </div>
          <div class="text-xs text-ink-600 mt-0.5">
            Households are created automatically if they don't exist.
          </div>
        </div>
      </div>
      <div class="flex gap-3 items-center flex-wrap">
        <label class="btn btn-secondary btn-sm cursor-pointer flex-none">
          Choose CSV
          <input type="file" accept=".csv" @change="e => selectFile(e, 'members')" class="sr-only" />
        </label>
        <span v-if="files.members" class="text-sm text-ink-400 truncate">{{ files.members.name }}</span>
      </div>
      <button v-if="files.members" @click="runImport('members')"
        :disabled="importing.members" class="btn btn-primary btn-sm">
        {{ importing.members ? 'Importing…' : 'Import Members' }}
      </button>
      <ImportResult :result="results.members" />
    </div>

    <!-- Sites import -->
    <div class="card p-5 space-y-4">
      <div class="flex items-center gap-3">
        <span class="text-2xl">🏠</span>
        <div>
          <div class="font-semibold text-ink-200">Import Sites</div>
          <div class="text-xs text-ink-500 mt-0.5">
            Columns: <code class="text-ember-400">site_number, site_type, power, capacity, notes</code>
          </div>
          <div class="text-xs text-ink-600 mt-0.5">
            Existing site numbers are skipped. Map pins are set via the Map view.
          </div>
        </div>
      </div>
      <div class="flex gap-3 items-center flex-wrap">
        <label class="btn btn-secondary btn-sm cursor-pointer flex-none">
          Choose CSV
          <input type="file" accept=".csv" @change="e => selectFile(e, 'sites')" class="sr-only" />
        </label>
        <span v-if="files.sites" class="text-sm text-ink-400 truncate">{{ files.sites.name }}</span>
      </div>
      <button v-if="files.sites" @click="runImport('sites')"
        :disabled="importing.sites" class="btn btn-primary btn-sm">
        {{ importing.sites ? 'Importing…' : 'Import Sites' }}
      </button>
      <ImportResult :result="results.sites" />
    </div>

    <!-- CSV templates -->
    <div class="card p-5 space-y-3">
      <div class="section-label">Download Templates</div>
      <div class="flex flex-wrap gap-2">
        <button @click="downloadTemplate('members')" class="btn btn-ghost btn-sm">↓ Members template</button>
        <button @click="downloadTemplate('sites')"   class="btn btn-ghost btn-sm">↓ Sites template</button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, inject, defineComponent, h } from 'vue'
import { api } from '@/api.js'

const toast = inject('toast')

const files     = ref({ members: null, sites: null })
const importing = ref({ members: false, sites: false })
const results   = ref({ members: null, sites: null })

// Inline result display component
const ImportResult = defineComponent({
  props: ['result'],
  setup(props) {
    return () => {
      const r = props.result
      if (!r) return null
      const isError = r.error || (r.errors?.length && !r.imported)
      const cls = isError
        ? 'rounded-xl p-3 text-sm bg-red-500/10 text-red-400'
        : 'rounded-xl p-3 text-sm bg-emerald-500/10 text-emerald-400'
      const msg = isError
        ? `Error: ${r.error || r.errors?.[0] || 'Import failed'}`
        : [
            `✓ ${r.imported} imported`,
            r.skipped  ? ` · ${r.skipped} skipped` : '',
            r.errors?.length ? ` · ${r.errors.length} error${r.errors.length !== 1 ? 's' : ''}` : '',
          ].join('')
      return h('div', { class: cls }, [
        msg,
        r.errors?.length ? h('ul', { class: 'mt-2 space-y-0.5 text-xs opacity-75 list-disc list-inside' },
          r.errors.slice(0, 5).map(e => h('li', e))
        ) : null,
      ])
    }
  }
})

function selectFile(e, type) {
  files.value[type]   = e.target.files[0] || null
  results.value[type] = null
}

async function runImport(type) {
  importing.value[type] = true
  try {
    const fd = new FormData()
    fd.append('file', files.value[type])
    const res = type === 'members'
      ? await api.imports.members(fd)
      : await api.imports.sites(fd)
    results.value[type] = res
    toast?.add(`Import complete: ${res.imported} rows added`, 'success')
  } catch (e) {
    results.value[type] = { error: e?.data?.message || 'Import failed' }
    toast?.add('Import failed', 'error')
  } finally {
    importing.value[type] = false
  }
}

const TEMPLATES = {
  members: 'first_name,last_name,household_name,member_type,gender,mobile,email\nCurtis,Humphreys,Curtis Humphreys,adult,male,0400000000,curtis@example.com\nJo,Humphreys,Curtis Humphreys,adult,female,0400000001,jo@example.com\nRyder,Humphreys,Curtis Humphreys,youth,male,,\n',
  sites:   'site_number,site_type,power,capacity,notes\n1,caravan,1,6,\n2,tent,0,4,\n3,cabin,1,8,Sleeps 8\n',
}

function downloadTemplate(type) {
  const blob = new Blob([TEMPLATES[type]], { type: 'text/csv' })
  const url  = URL.createObjectURL(blob)
  const a    = Object.assign(document.createElement('a'), { href: url, download: `${type}-template.csv` })
  a.click(); URL.revokeObjectURL(url)
}
</script>
