<template>
  <div class="space-y-3">
    <!-- No location yet: one clear action. -->
    <div v-if="!modelValue" class="space-y-2">
      <button type="button" class="btn-primary w-full h-12 text-base" :disabled="locating" @click="useGps">
        <Icon name="locate" :size="20" /> {{ locating ? 'Finding your location…' : 'Use my current location' }}
      </button>
      <p class="text-xs text-muted text-center">{{ mapsKey ? 'Or tap the map below to drop a pin.' : 'Your browser will ask to share your location. Tap Allow.' }}</p>
    </div>

    <!-- Location found: a calm summary with a way to redo it. -->
    <div v-else class="flex items-center gap-3 rounded-lg border border-line bg-gray-50 px-3 py-3">
      <span class="h-10 w-10 shrink-0 rounded-full bg-green-100 text-ok grid place-items-center"><Icon name="pin" :size="20" /></span>
      <div class="min-w-0 flex-1">
        <div class="font-semibold text-sm">{{ label || 'Location found' }}</div>
        <div class="text-xs text-muted">{{ detail }}{{ mapsKey ? '. Drag the pin to adjust.' : '' }}</div>
      </div>
      <button type="button" class="text-sm font-semibold text-brand-700 hover:underline shrink-0" :disabled="locating" @click="useGps">{{ locating ? 'Finding…' : 'Update' }}</button>
    </div>
    <p v-if="gpsError" class="text-sm text-bad" role="alert">{{ gpsError }}</p>

    <div v-if="mapsKey" class="relative rounded-lg overflow-hidden border border-line" style="aspect-ratio: 16 / 10">
      <div ref="mapEl" class="absolute inset-0"></div>
      <div v-if="!mapReady" class="absolute inset-0 grid place-items-center text-sm text-muted bg-gray-50">{{ mapError || 'Loading map…' }}</div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import Icon from './Icon.vue'
import { currentPosition, loadMaps } from '@/lib/google'

type Pin = { lat: number; lng: number }
// `label`: the area name once known (e.g. "Kira, Wakiso"), shown instead of "Location found".
const props = defineProps<{ modelValue: Pin | null; mapsKey?: string; label?: string | null }>()
const emit = defineEmits<{ 'update:modelValue': [p: Pin] }>()

const mapEl = ref<HTMLElement>()
const mapReady = ref(false)
const mapError = ref('')
const locating = ref(false)
const gpsError = ref('')
const accuracy = ref(0)
// Where the pin came from, so the summary line is accurate.
const source = ref<'saved' | 'gps' | 'map'>('saved')
const detail = computed(() =>
  source.value === 'gps' ? (accuracy.value ? `From your device, accurate to about ${accuracy.value} m` : 'From your device')
  : source.value === 'map' ? 'Pinned on the map' : 'Your saved location')
let map: any = null
let marker: any = null
const KAMPALA = { lat: 0.3476, lng: 32.5825 }

async function useGps() {
  locating.value = true
  gpsError.value = ''
  try {
    const p = await currentPosition()
    accuracy.value = p.accuracy
    source.value = 'gps'
    set({ lat: p.lat, lng: p.lng }, true)
  } catch (e: any) {
    gpsError.value = e.message
  } finally {
    locating.value = false
  }
}

function set(p: Pin, pan = false) {
  emit('update:modelValue', { lat: +p.lat.toFixed(7), lng: +p.lng.toFixed(7) })
  if (marker) marker.setPosition(p)
  if (map && pan) {
    map.panTo(p)
    map.setZoom(17)
  }
}

onMounted(async () => {
  if (!props.mapsKey) return
  try {
    const maps = await loadMaps(props.mapsKey)
    const start = props.modelValue ?? KAMPALA
    map = new maps.Map(mapEl.value, { center: start, zoom: props.modelValue ? 16 : 11, mapTypeControl: false, streetViewControl: false, fullscreenControl: false, gestureHandling: 'greedy' })
    marker = new maps.Marker({ position: start, map, draggable: true })
    marker.addListener('dragend', () => {
      source.value = 'map'
      set({ lat: marker.getPosition().lat(), lng: marker.getPosition().lng() })
    })
    map.addListener('click', (e: any) => {
      source.value = 'map'
      set({ lat: e.latLng.lat(), lng: e.latLng.lng() })
    })
    mapReady.value = true
  } catch (e: any) {
    mapError.value = e.message
  }
})

watch(() => props.modelValue, (p) => p && marker?.setPosition(p))
</script>
