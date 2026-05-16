<template>
  <div class="flex flex-col h-full animate-fade-in">

    <LoadingSpinner v-if="loading" :full="true" label="Loading map…" />

    <div v-else-if="mapError" class="flex-1 flex items-center justify-center p-8">
      <EmptyState icon="🗺️" title="Map not available" :subtitle="mapError" />
    </div>

    <div v-else class="flex-1 relative">
      <div ref="mapEl" class="absolute inset-0" />

      <!-- Selected site panel -->
      <Transition name="slide-up">
        <div v-if="selected"
          class="absolute bottom-4 left-4 right-4
            bg-surface-700 border border-surface-500 rounded-2xl p-4 shadow-modal">
          <div class="flex items-start justify-between gap-2">
            <div>
              <div class="flex items-center gap-2 mb-1">
                <span class="text-ember-400 font-bold text-lg">Site {{ selected.site_number }}</span>
                <span v-if="selected.map_occupants" class="badge badge-sage">Occupied</span>
                <span v-else class="badge badge-muted">Vacant</span>
              </div>
              <p v-if="selected.map_occupants" class="text-ink-200 text-sm">{{ selected.map_occupants }}</p>
              <p v-if="selected.site_type" class="text-ink-500 text-xs mt-0.5 capitalize">{{ selected.site_type }}</p>
            </div>
            <button @click="selected = null" class="btn btn-ghost p-1 text-ink-400">✕</button>
          </div>
        </div>
      </Transition>
    </div>

  </div>
</template>

<script setup>
import { ref, onMounted, onUnmounted } from 'vue'
import { publicApi } from '@/api.js'
import LoadingSpinner from '@/components/LoadingSpinner.vue'
import EmptyState from '@/components/EmptyState.vue'

const loading  = ref(true)
const mapError = ref('')
const selected = ref(null)
const mapEl    = ref(null)

let googleMap = null
let sites     = []
const markers = new Map()

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

function makeIcon(site, isSelected = false) {
  const assigned = !!site.map_occupants
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
    anchor:     new google.maps.Point(15, 15),
  }
}

function selectSite(site) {
  selected.value = site
  markers.forEach((m, id) => {
    const s = sites.find(x => x.id === id)
    if (s) m.setIcon(makeIcon(s, id === site.id))
  })
}

function buildMarkers() {
  markers.forEach(m => m.setMap(null))
  markers.clear()
  sites.forEach(s => {
    if (!s.map_lat || !s.map_lng) return
    const m = new google.maps.Marker({
      position: { lat: parseFloat(s.map_lat), lng: parseFloat(s.map_lng) },
      map: googleMap,
      icon: makeIcon(s),
      title: `Site ${s.site_number}`,
      optimized: false,
    })
    m.addListener('click', () => selectSite(s))
    markers.set(s.id, m)
  })
}

onMounted(async () => {
  let config = {}
  try { config = await publicApi.mapConfig() } catch {}

  if (!config.google_maps_api_key) {
    mapError.value = 'The site map hasn\'t been set up yet.'
    loading.value = false
    return
  }

  try {
    const data = await publicApi.sitesMap()
    sites = Array.isArray(data) ? data : (data.sites ?? [])
  } catch {}

  loading.value = false

  try {
    await loadGoogleMaps(config.google_maps_api_key)
  } catch {
    mapError.value = 'Could not load the map. Please try again later.'
    return
  }

  const lat = config.map_center_lat ? parseFloat(config.map_center_lat) : -35.4332400094919
  const lng = config.map_center_lng ? parseFloat(config.map_center_lng) : 138.32729667017747

  googleMap = new google.maps.Map(mapEl.value, {
    center: { lat, lng },
    zoom: 20,
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

  buildMarkers()
})

onUnmounted(() => {
  markers.forEach(m => m.setMap(null))
  markers.clear()
  googleMap = null
})
</script>

<style scoped>
.slide-up-enter-active, .slide-up-leave-active { transition: all .25s ease; }
.slide-up-enter-from, .slide-up-leave-to { transform: translateY(16px); opacity: 0; }
</style>
