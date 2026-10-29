<template>
  <!-- A home-page section: coloured panel, big title, "See All" pill, sliding row of product cards. -->
  <section class="relative rounded-xl overflow-hidden" :class="theme.panel">
    <header class="flex items-center justify-between gap-3 px-3 sm:px-4 py-2.5 sm:py-3 mb-3" :class="theme.head">
      <div class="min-w-0">
        <h2 class="font-bold text-lg sm:text-xl leading-tight" :class="theme.title">{{ title }}</h2>
        <p v-if="subtitle" class="text-xs sm:text-sm mt-0.5" :class="theme.sub">{{ subtitle }}</p>
      </div>
      <div class="shrink-0 flex items-center gap-3">
        <button v-if="autoplay && canAnimate" type="button" class="h-8 w-8 grid place-items-center rounded-full" :class="tone === 'accent' ? 'bg-white/20 text-white hover:bg-white/30' : 'bg-gray-100 text-ink hover:bg-gray-200'"
          :aria-label="stopped ? 'Play the sliding products' : 'Pause the sliding products'" :title="stopped ? 'Play' : 'Pause'" @click="stopped = !stopped">
          <Icon :name="stopped ? 'play' : 'pause'" :size="14" />
        </button>
        <RouterLink :to="link" class="inline-flex items-center gap-1 text-sm font-semibold" :class="theme.pill">
          See All <Icon name="arrow" :size="16" />
        </RouterLink>
      </div>
    </header>

    <div class="relative" @mouseenter="hovering = true" @mouseleave="hovering = false" @focusin="focused = true" @focusout="focused = false" @touchstart.passive="touchedAt = Date.now()">
      <div
        ref="track"
        class="grid grid-flow-col auto-cols-[44%] min-[480px]:auto-cols-[31%] md:auto-cols-[23%] lg:auto-cols-[calc((100%-5*0.75rem)/6)] min-[2300px]:auto-cols-[calc((100%-7*0.75rem)/8)] gap-3 overflow-x-auto scroll-smooth snap-x snap-mandatory px-3 sm:px-4 pb-3 sm:pb-4 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden"
        @scroll.passive="updateArrows"
      >
        <!-- When auto-sliding, the list is repeated so the motion can keep going forward forever. -->
        <ProductCard v-for="(p, i) in shown" :key="`${p.id}-${i}`" :p="p" :eager="eager && i < 2" class="snap-start" :class="theme.card"
          :inert="i >= items.length || undefined" :aria-hidden="i >= items.length || undefined" />
      </div>
      <!-- Arrows on pointer devices; touch users swipe. -->
      <button v-show="canPrev" class="arrow left-1.5" aria-label="Previous products" @click="scrollBy(-1)"><Icon name="left" :size="20" /></button>
      <button v-show="canNext" class="arrow right-1.5" aria-label="More products" @click="scrollBy(1)"><Icon name="chevron" :size="20" /></button>
    </div>
  </section>
</template>

<script setup lang="ts">
import { computed, nextTick, onMounted, onUnmounted, ref } from 'vue'
import Icon from './Icon.vue'
import ProductCard from './ProductCard.vue'
import type { ProductCard as Card } from '@/stores/shop'

// Calm palette: white panels on the light-grey page; coffee brown (#6F4E37) is the single accent colour.
export type Tone = 'accent' | 'plain'
const props = withDefaults(defineProps<{ title: string; subtitle?: string; items: Card[]; link: string; tone?: Tone; eager?: boolean; autoplay?: number; perStep?: number }>(), { tone: 'plain', perStep: 1 })

const THEMES: Record<Tone, { panel: string; head: string; title: string; sub: string; pill: string; card: string }> = {
  accent: { panel: 'bg-white', head: 'bg-brand-600', title: 'text-white', sub: 'text-white/90', pill: 'text-white hover:underline', card: 'border border-line' },
  plain: { panel: 'bg-white', head: 'border-b border-line', title: 'text-ink', sub: 'text-muted', pill: 'text-brand-700 hover:underline', card: 'border border-line' },
}
const theme = computed(() => THEMES[props.tone])

const track = ref<HTMLElement>()
const canPrev = ref(false)
const canNext = ref(false)
function updateArrows() {
  const el = track.value
  if (!el) return
  canPrev.value = el.scrollLeft > 4
  canNext.value = el.scrollLeft + el.clientWidth < el.scrollWidth - 4
}
function scrollBy(dir: number) {
  const el = track.value
  if (el) el.scrollBy({ left: dir * el.clientWidth * 0.9, behavior: 'smooth' })
}
onMounted(() => nextTick(updateArrows))

// ---- Auto-slide (only rows given `autoplay`, e.g. Top Deals): `perStep` products every N ms
// (fewer on small screens, never more than are in view, so no product is skipped unseen), always
// forward. The products are repeated three times; after sliding past the first copy the row jumps
// back by exactly one copy (looks identical), so it never slides backwards.
// Stops while hovered, focused or just touched, when scrolled out of view or the tab is
// hidden, and never runs for people who ask their device to reduce motion.
const canAnimate = typeof matchMedia === 'function' && !matchMedia('(prefers-reduced-motion: reduce)').matches
const looping = computed(() => !!props.autoplay && canAnimate && props.items.length > 1)
const shown = computed(() => (looping.value ? [...props.items, ...props.items, ...props.items] : props.items))
const stopped = ref(false)
const hovering = ref(false)
const focused = ref(false)
const touchedAt = ref(0)
let onScreen = false
let timer: number | undefined
let io: IntersectionObserver | undefined

function stepSize(el: HTMLElement) {
  const card = el.firstElementChild as HTMLElement | null
  return card ? card.offsetWidth + 12 : 0 // 12 px = gap-3
}

/** If we have slid into the second copy, jump back by one copy (same picture, no motion). */
function wrap() {
  const el = track.value
  if (!el || !looping.value) return
  const setWidth = props.items.length * stepSize(el)
  if (setWidth && el.scrollLeft >= setWidth - 2) el.scrollTo({ left: el.scrollLeft - setWidth, behavior: 'instant' as ScrollBehavior })
}

function step() {
  const el = track.value
  if (!el || stopped.value || hovering.value || focused.value || !onScreen || document.hidden) return
  if (Date.now() - touchedAt.value < 8000) return // the shopper is swiping: leave it alone
  const size = stepSize(el)
  if (!size || props.items.length * size <= el.clientWidth) return // everything already fits
  wrap()
  const cards = Math.max(1, Math.min(props.perStep, Math.floor((el.clientWidth + 12) / size)))
  el.scrollBy({ left: cards * size, behavior: 'smooth' })
  window.setTimeout(wrap, 1000)
}

onMounted(() => {
  if (!props.autoplay || !canAnimate || !track.value) return
  io = new IntersectionObserver(([e]) => (onScreen = e.isIntersecting), { threshold: 0.4 })
  io.observe(track.value)
  timer = window.setInterval(step, props.autoplay)
})
onUnmounted(() => {
  clearInterval(timer)
  io?.disconnect()
})
</script>

<style scoped>
.arrow {
  position: absolute;
  top: calc(50% - 1.5rem);
  height: 2.75rem;
  width: 2.75rem;
  border-radius: 9999px;
  background: #fff;
  color: var(--color-ink);
  display: none;
  place-items: center;
  box-shadow: 0 2px 8px rgb(0 0 0 / 0.25);
}
@media (hover: hover) and (min-width: 768px) {
  .arrow { display: grid; }
}
.arrow:hover { background: #f3f4f6; }
</style>
