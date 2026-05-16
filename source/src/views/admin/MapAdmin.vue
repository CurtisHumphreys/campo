<template>
  <div class="relative flex flex-col h-[calc(100dvh-56px)] lg:h-dvh">

    <!-- Toolbar -->
    <div class="px-4 py-3 border-b border-surface-700 flex items-center gap-3 flex-none bg-surface-900" style="z-index:1001">
      <h1 class="text-lg font-bold text-ink-100">Site Map</h1>
      <div class="ml-auto flex items-center gap-2 flex-wrap">
        <input v-model="searchQuery" @input="onSearch" type="text"
          placeholder="Search site # or name…"
          class="text-sm w-44" />
        <button @click="toggleEdit"
          class="btn btn-sm border"
          :class="editMode
            ? 'bg-ember-500/20 text-ember-400 border-ember-500/40'
            : 'btn-secondary border-surface-600'">
          {{ editMode ? '✓ Done Editing' : '📍 Edit Pins' }}
        </button>
      </div>
    </div>

    <!-- Edit mode bar -->
    <div v-if="editMode"
      class="px-4 py-2 bg-ember-500/10 border-b border-ember-500/20 flex items-center gap-3 flex-none flex-wrap"
      style="z-index:1001">
      <span class="text-xs text-ember-400 flex-none">Pin site:</span>
      <select v-model="placingSiteId" class="text-sm flex-1 min-w-32 max-w-xs">
        <option :value="null">— select a site —</option>
        <option v-for="s in sites" :key="s.site_id" :value="s.site_id">
          Site {{ s.site_number }}{{ s.map_lat ? ' ✓' : '' }}
        </option>
      </select>
      <span class="text-xs text-ink-500 hidden sm:inline">· click map to place</span>
      <button v-if="auth.user?.role === 'full_admin'"
        @click="openCenterPanel"
        class="btn btn-sm btn-secondary ml-auto flex-none text-xs">
        🎯 Set Center
      </button>
    </div>

    <!-- Center coordinates panel -->
    <div v-if="showCenterPanel && editMode"
      class="px-4 py-3 bg-surface-800 border-b border-surface-600 flex items-end gap-3 flex-wrap"
      style="z-index:1001">
      <div class="flex items-center gap-2 text-xs text-ink-400 flex-none">
        <span>Map center</span>
      </div>
      <div class="flex items-center gap-2">
        <label class="text-xs text-ink-500">Lat</label>
        <input v-model="centerForm.lat" type="text" class="w-32 text-sm py-1" placeholder="-35.4332…" />
      </div>
      <div class="flex items-center gap-2">
        <label class="text-xs text-ink-500">Lng</label>
        <input v-model="centerForm.lng" type="text" class="w-32 text-sm py-1" placeholder="138.3272…" />
      </div>
      <button @click="useCurrentView" class="btn btn-sm btn-secondary text-xs flex-none">
        Use Current View
      </button>
      <button @click="saveCenter" :disabled="savingCenter" class="btn btn-sm btn-primary text-xs flex-none">
        {{ savingCenter ? 'Saving…' : 'Save' }}
      </button>
      <button @click="showCenterPanel = false" class="btn btn-sm btn-ghost text-xs flex-none text-ink-500">
        Cancel
      </button>
    </div>

    <!-- Map container -->
    <div ref="mapEl" class="flex-1" />

    <!-- No API key warning -->
    <div v-if="mapError" class="absolute inset-0 flex items-center justify-center bg-surface-900/80 z-50">
      <div class="bg-surface-800 border border-surface-600 rounded-2xl p-6 max-w-sm text-center space-y-2">
        <div class="text-2xl">🗺️</div>
        <p class="text-sm text-ink-300">{{ mapError }}</p>
      </div>
    </div>

    <!-- Selected site panel -->
    <Transition name="slide-up">
      <div v-if="selected"
        class="absolute bottom-6 right-4 w-64 bg-surface-800 border border-surface-600 rounded-2xl p-4 shadow-xl"
        style="z-index:1000">
        <div class="flex items-start justify-between mb-2">
          <div>
            <div class="font-bold text-ember-400 text-lg leading-none">Site {{ selected.site_number }}</div>
            <div class="text-xs text-ink-500 capitalize mt-0.5">{{ selected.site_type || 'General' }}</div>
          </div>
          <button @click="selected = null" class="btn btn-ghost p-1 text-ink-500 text-base leading-none">✕</button>
        </div>
        <div v-if="selected.household_name" class="text-sm font-medium text-ink-200 mb-1">
          🏠 {{ selected.household_name }}
          <span class="text-ink-500 font-normal text-xs">({{ selected.member_count }})</span>
        </div>
        <div v-else class="text-sm text-ink-500 italic mb-1">Unassigned</div>
        <div class="text-xs text-ink-500 flex gap-3">
          <span>👥 {{ selected.capacity }}</span>
          <span v-if="selected.power" class="text-amber-400">⚡ Power</span>
        </div>
        <button v-if="editMode && selected.map_lat"
          @click="clearPin"
          class="mt-3 w-full text-sm px-3 py-1.5 rounded-lg bg-red-600/15 text-red-400 hover:bg-red-600/25 border border-red-600/20 transition-colors">
          Remove Pin
        </button>
      </div>
    </Transition>

  </div>
</template>

<script setup>
import { ref, onMounted, onUnmounted, nextTick } from 'vue'
import { api } from '@/api.js'

function loadGoogleMaps(apiKey) {
  return new Promise((resolve, reject) => {
    if (window.google?.maps) { resolve(); return }
    const s = document.createElement('script')
    s.src = `https://maps.googleapis.com/maps/api/js?key=${apiKey}&v=weekly`
    s.async = true
    s.onload = resolve
    s.onerror = () => reject(new Error('Script load failed'))
    document.head.appendChild(s)
  })
}
import { useAuthStore } from '@/stores/auth.js'

const auth = useAuthStore()

const mapEl           = ref(null)
const activeCamp      = ref(null)
const sites           = ref([])
const selected        = ref(null)
const editMode        = ref(false)
const placingSiteId   = ref(null)
const showCenterPanel = ref(false)
const centerForm      = ref({ lat: '', lng: '', zoom: '' })
const savingCenter    = ref(false)
const mapError        = ref('')
const searchQuery     = ref('')

let googleMap  = null
let clickListener = null
const markers  = new Map() // site_id → google.maps.Marker

// ── Icons ──────────────────────────────────────────────────────────────────────
function makeIcon(site, isSelected = false) {
  const assigned = !!site.household_id
  const bg     = isSelected ? '#f59e0b' : assigned ? '#b85a1a' : '#2d2d2d'
  const border = isSelected ? '#fcd34d' : assigned ? '#d97706' : '#555555'
  const color  = isSelected ? '#1c1410' : '#f0ece4'
  const num    = site.site_number ?? ''
  const svg = `<svg xmlns="http://www.w3.org/2000/svg" width="30" height="30">` +
    `<circle cx="15" cy="15" r="13" fill="${bg}" stroke="${border}" stroke-width="2.5"/>` +
    `<text x="15" y="19.5" text-anchor="middle" fill="${color}" font-size="10" font-weight="700" font-family="system-ui,sans-serif">${num}</text>` +
    `</svg>`
  return {
    url: `data:image/svg+xml;charset=UTF-8,${encodeURIComponent(svg)}`,
    scaledSize: new google.maps.Size(30, 30),
    anchor: new google.maps.Point(15, 15),
  }
}

// ── Marker management ──────────────────────────────────────────────────────────
function addMarker(site) {
  if (!site.map_lat || !site.map_lng || !googleMap) return
  const m = new google.maps.Marker({
    position: { lat: parseFloat(site.map_lat), lng: parseFloat(site.map_lng) },
    map: googleMap,
    icon: makeIcon(site),
    title: `Site ${site.site_number}`,
    optimized: false,
  })
  m.addListener('click', () => selectSite(site))
  markers.set(site.site_id, m)
}

function matchesSearch(site) {
  const q = searchQuery.value.trim().toLowerCase()
  if (!q) return true
  if (String(site.site_number).toLowerCase().includes(q)) return true
  if ((site.household_name || '').toLowerCase().includes(q)) return true
  return false
}

function refreshMarkers() {
  markers.forEach(m => m.setMap(null))
  markers.clear()
  sites.value.forEach(s => { if (matchesSearch(s)) addMarker(s) })
}

function onSearch() {
  refreshMarkers()
  const q = searchQuery.value.trim()
  if (!q || !googleMap) return
  const match = sites.value.find(s => matchesSearch(s) && s.map_lat)
  if (match) {
    googleMap.panTo({ lat: parseFloat(match.map_lat), lng: parseFloat(match.map_lng) })
    selectSite(match)
  }
}

function selectSite(site) {
  selected.value = site
  markers.forEach((m, id) => {
    const s = sites.value.find(x => x.site_id === id)
    if (s) m.setIcon(makeIcon(s, id === site.site_id))
  })
}

// ── Data ───────────────────────────────────────────────────────────────────────
async function loadData() {
  try {
    const [campRes, allocRes] = await Promise.all([
      api.camps.active(),
      api.siteAllocations.list(),
    ])
    activeCamp.value = campRes
    sites.value = allocRes.allocations ?? []
    refreshMarkers()
  } catch {}
}

// ── Edit mode ──────────────────────────────────────────────────────────────────
function toggleEdit() { editMode.value = !editMode.value; placingSiteId.value = null }

async function onMapClick(e) {
  if (!editMode.value || !placingSiteId.value) return
  const lat = e.latLng.lat()
  const lng = e.latLng.lng()
  try {
    await api.sites.pin(placingSiteId.value, { map_lat: lat, map_lng: lng })
    const site = sites.value.find(s => s.site_id === placingSiteId.value)
    if (site) {
      site.map_lat = lat
      site.map_lng = lng
      refreshMarkers()
      selectSite(site)
    }
  } catch {}
}

async function clearPin() {
  if (!selected.value?.map_lat) return
  try {
    await api.sites.pin(selected.value.site_id, { map_lat: null, map_lng: null })
    const m = markers.get(selected.value.site_id)
    if (m) { m.setMap(null); markers.delete(selected.value.site_id) }
    selected.value.map_lat = null
    selected.value.map_lng = null
    selected.value = null
  } catch {}
}

// ── Map center ─────────────────────────────────────────────────────────────────
function openCenterPanel() {
  const c = googleMap.getCenter()
  centerForm.value = { lat: c.lat().toFixed(8), lng: c.lng().toFixed(8), zoom: googleMap.getZoom() }
  showCenterPanel.value = true
}

function useCurrentView() {
  const c = googleMap.getCenter()
  centerForm.value = { lat: c.lat().toFixed(8), lng: c.lng().toFixed(8), zoom: googleMap.getZoom() }
}

async function saveCenter() {
  if (!activeCamp.value) return
  savingCenter.value = true
  try {
    await api.camps.setMapCenter(activeCamp.value.id, {
      map_center_lat: parseFloat(centerForm.value.lat),
      map_center_lng: parseFloat(centerForm.value.lng),
      map_zoom:       parseInt(centerForm.value.zoom) || 18,
    })
    activeCamp.value.map_center_lat = centerForm.value.lat
    activeCamp.value.map_center_lng = centerForm.value.lng
    activeCamp.value.map_zoom       = centerForm.value.zoom
    googleMap.setCenter({ lat: parseFloat(centerForm.value.lat), lng: parseFloat(centerForm.value.lng) })
    googleMap.setZoom(parseInt(centerForm.value.zoom) || 18)
    showCenterPanel.value = false
  } catch {}
  savingCenter.value = false
}

// ── Lifecycle ──────────────────────────────────────────────────────────────────
onMounted(async () => {
  await loadData()
  await nextTick()

  // Fetch API key from server
  let apiKey = ''
  try {
    const cfg = await api.get('/config/maps')
    apiKey = cfg.google_maps_api_key ?? ''
  } catch {}

  if (!apiKey) {
    mapError.value = 'Google Maps API key is not configured. Add it in Settings.'
    return
  }

  try {
    await loadGoogleMaps(apiKey)
  } catch {
    mapError.value = 'Failed to load Google Maps. Check that the API key is valid.'
    return
  }

  const camp     = activeCamp.value
  const initLat  = camp?.map_center_lat ? parseFloat(camp.map_center_lat) : -35.4332400094919
  const initLng  = camp?.map_center_lng ? parseFloat(camp.map_center_lng) : 138.32729667017747
  const initZoom = camp?.map_zoom       ? parseInt(camp.map_zoom)          : 18

  googleMap = new google.maps.Map(mapEl.value, {
    center: { lat: initLat, lng: initLng },
    zoom: initZoom,
    maxZoom: 22,
    mapTypeId: 'hybrid',
    mapTypeControl: true,
    mapTypeControlOptions: {
      style: google.maps.MapTypeControlStyle.HORIZONTAL_BAR,
      mapTypeIds: ['hybrid', 'satellite', 'roadmap'],
    },
    streetViewControl: false,
    fullscreenControl: true,
    zoomControl: true,
    gestureHandling: 'greedy',
  })

  clickListener = googleMap.addListener('click', onMapClick)
  refreshMarkers()
})

onUnmounted(() => {
  if (clickListener) google.maps.event.removeListener(clickListener)
  markers.forEach(m => m.setMap(null))
  markers.clear()
  googleMap = null
})
</script>

<style scoped>
.slide-up-enter-active, .slide-up-leave-active { transition: all .2s ease; }
.slide-up-enter-from, .slide-up-leave-to { transform: translateY(8px); opacity: 0; }
</style>
