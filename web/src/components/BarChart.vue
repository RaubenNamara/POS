<template>
  <div>
    <div class="flex items-center justify-between mb-2">
      <h3 class="font-semibold text-sm">{{ title }}</h3>
      <button class="text-xs text-brand-700 font-semibold" @click="asTable = !asTable">{{ asTable ? 'Show chart' : 'Show table' }}</button>
    </div>
    <div v-if="!points.length" class="h-48 grid place-items-center text-sm text-muted">No sales in this period yet.</div>
    <table v-else-if="asTable" class="tbl">
      <thead><tr><th>Period</th><th class="num">{{ valueLabel }}</th><th class="num">Sales</th></tr></thead>
      <tbody><tr v-for="p in points" :key="p.label"><td>{{ p.label }}</td><td class="num">{{ format(p.value) }}</td><td class="num">{{ p.count }}</td></tr></tbody>
    </table>
    <div v-else ref="box" class="relative" @mouseleave="hover = -1">
      <!-- Single series, one hue, thin rounded-top bars on a recessive grid; values on hover. -->
      <svg :viewBox="`0 0 ${W} ${H}`" :style="{ height: `${H}px` }" class="w-full" role="img" :aria-label="`${title}: bar chart`">
        <g v-for="t in ticks" :key="t">
          <line :x1="padL" :x2="W" :y1="y(t)" :y2="y(t)" stroke="#e5e7eb" stroke-width="1" />
          <text :x="padL - 6" :y="y(t) + 4" text-anchor="end" font-size="10" fill="#6b7280">{{ short(t) }}</text>
        </g>
        <g v-for="(p, i) in points" :key="p.label">
          <rect :x="x(i)" :y="padT" :width="slot" :height="H - padT - padB" fill="transparent" @mouseenter="hover = i" />
          <path :d="bar(i, p.value)" :fill="hover === -1 || hover === i ? '#6f4e37' : '#cdb4a0'" pointer-events="none" />
        </g>
        <text v-for="i in labelIdx" :key="`l${i}`" :x="x(i) + slot / 2" :y="H - 6" text-anchor="middle" font-size="10" fill="#6b7280">{{ points[i].label.slice(5) }}</text>
      </svg>
      <div v-if="hover >= 0" class="absolute top-0 pointer-events-none bg-ink text-white text-xs rounded px-2 py-1 shadow" :style="{ left: `${Math.min(85, ((x(hover) + slot / 2) / W) * 100)}%`, transform: 'translateX(-50%)' }">
        <b>{{ points[hover].label }}</b><br />{{ valueLabel }}: {{ format(points[hover].value) }}<br />{{ points[hover].count }} sales
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'

const props = defineProps<{ title: string; valueLabel: string; points: { label: string; value: number; count: number }[]; format: (v: number) => string }>()
const asTable = ref(false)
const hover = ref(-1)
// The drawing uses the card's real width (1 unit = 1 CSS px), so it fills a phone or a 27" screen
// without stretching text; height grows with width but stays readable.
const box = ref<HTMLElement>()
const W = ref(600)
const H = computed(() => Math.round(Math.min(380, Math.max(180, W.value * 0.32))))
let ro: ResizeObserver | undefined
onMounted(() => {
  ro = new ResizeObserver(([e]) => (W.value = Math.max(280, Math.round(e.contentRect.width))))
  if (box.value) ro.observe(box.value)
})
// The chart's container is re-created when switching back from the table view.
watch(box, (el, old) => {
  if (old) ro?.unobserve(old)
  if (el) ro?.observe(el)
})
onUnmounted(() => ro?.disconnect())
const padL = 44
const padT = 8
const padB = 22
const gap = 2

const max = computed(() => {
  const m = Math.max(0, ...props.points.map((p) => p.value))
  if (m <= 0) return 1
  const mag = 10 ** Math.floor(Math.log10(m))
  return Math.ceil(m / mag) * mag
})
const ticks = computed(() => [0, 0.25, 0.5, 0.75, 1].map((f) => f * max.value))
const slot = computed(() => (W.value - padL) / Math.max(1, props.points.length))
const x = (i: number) => padL + i * slot.value
const y = (v: number) => padT + (1 - v / max.value) * (H.value - padT - padB)
const labelIdx = computed(() => {
  const n = props.points.length
  const step = Math.max(1, Math.ceil(n / Math.max(4, Math.floor(W.value / 110))))
  return [...Array(n).keys()].filter((i) => i % step === 0)
})

function bar(i: number, v: number) {
  const w = Math.max(1, slot.value - gap * 2)
  const x0 = x(i) + gap
  const y0 = y(Math.max(0, v))
  const base = y(0)
  const r = Math.min(4, w / 2, base - y0)
  if (base - y0 < 0.5) return ''
  return `M${x0},${base}V${y0 + r}Q${x0},${y0} ${x0 + r},${y0}H${x0 + w - r}Q${x0 + w},${y0} ${x0 + w},${y0 + r}V${base}Z`
}

function short(v: number) {
  if (v >= 1e9) return `${+(v / 1e9).toFixed(1)}B`
  if (v >= 1e6) return `${+(v / 1e6).toFixed(1)}M`
  if (v >= 1e3) return `${+(v / 1e3).toFixed(0)}k`
  return String(Math.round(v))
}
</script>
