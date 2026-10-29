<template>
  <!-- Home-page advert slider: one advert every few seconds, always moving forward. -->
  <section
    ref="root"
    class="relative overflow-hidden rounded-xl bg-gray-100 aspect-[4/3] sm:aspect-[3/1]"
    aria-roledescription="carousel"
    aria-label="Adverts"
    @mouseenter="hovering = true" @mouseleave="hovering = false"
    @focusin="focused = true" @focusout="focused = false"
    @touchstart.passive="onTouchStart" @touchend.passive="onTouchEnd"
  >
    <div class="flex h-full" :class="{ 'transition-transform duration-500 ease-out': animate }" :style="{ transform: `translateX(-${index * 100}%)` }" @transitionend="onEnd">
      <component
        :is="slideTag(ad)"
        v-for="(ad, i) in slides"
        :key="`${ad.id}-${i}`"
        v-bind="slideAttrs(ad)"
        class="relative block h-full w-full shrink-0"
        :aria-hidden="i === ads.length ? 'true' : undefined"
        :tabindex="i === ads.length ? -1 : undefined"
        :aria-label="`${ad.title} (advert ${(i % ads.length) + 1} of ${ads.length})`"
        @click="ad.link_url && countAdClick(ad.id)"
      >
        <picture>
          <source v-if="ad.image_mobile_path" :srcset="img(ad.image_mobile_path)" media="(max-width: 639px)" />
          <img :src="img(ad.image_path)" :alt="ad.title" class="absolute inset-0 h-full w-full object-cover"
            :loading="i === 0 ? 'eager' : 'lazy'" :fetchpriority="i === 0 ? 'high' : 'auto'" decoding="async" />
        </picture>
        <div v-if="ad.show_text" class="absolute inset-0 flex flex-col justify-end sm:justify-center p-5 sm:p-10 max-w-xl text-white" style="text-shadow: 0 1px 6px rgb(0 0 0 / 0.55)">
          <p class="text-2xl sm:text-4xl font-black leading-tight">{{ ad.title }}</p>
          <p v-if="ad.subtitle" class="mt-1 sm:mt-2 text-sm sm:text-lg">{{ ad.subtitle }}</p>
        </div>
      </component>
    </div>

    <template v-if="ads.length > 1">
      <button class="nav left-2" aria-label="Previous advert" @click="prev"><Icon name="left" :size="20" /></button>
      <button class="nav right-2" aria-label="Next advert" @click="next"><Icon name="chevron" :size="20" /></button>
      <div class="absolute bottom-2.5 inset-x-0 flex items-center justify-center gap-1.5">
        <button v-for="(ad, i) in ads" :key="ad.id" class="h-2 rounded-full transition-all" :class="i === current ? 'w-6 bg-white' : 'w-2 bg-white/60'"
          :aria-label="`Show advert ${i + 1}`" :aria-current="i === current" @click="goTo(i)"></button>
        <button v-if="canAnimate" class="ml-2 h-6 w-6 grid place-items-center rounded-full bg-black/30 text-white" :aria-label="stopped ? 'Play adverts' : 'Pause adverts'" @click="stopped = !stopped">
          <Icon :name="stopped ? 'play' : 'pause'" :size="12" />
        </button>
      </div>
    </template>
  </section>
</template>

<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import Icon from './Icon.vue'
import { img } from '@/lib/api'
import { countAdClick, isExternal, type Ad } from '@/lib/ads'

const props = withDefaults(defineProps<{ ads: Ad[]; interval?: number }>(), { interval: 5000 })

// The first advert is repeated at the end: moving from the last advert to "the first again"
// is still a step forward; then we jump back invisibly. So the motion is always one direction.
const slides = computed(() => (props.ads.length > 1 ? [...props.ads, props.ads[0]] : props.ads))
const index = ref(0)
const animate = ref(true)
const current = computed(() => index.value % props.ads.length)

const slideTag = (ad: Ad) => (!ad.link_url ? 'div' : isExternal(ad.link_url) ? 'a' : RouterLink)
const slideAttrs = (ad: Ad) => (!ad.link_url ? {} : isExternal(ad.link_url) ? { href: ad.link_url, target: '_blank', rel: 'noopener sponsored' } : { to: ad.link_url })

function next() {
  if (props.ads.length < 2) return
  animate.value = true
  index.value = Math.min(index.value + 1, props.ads.length)
}
function prev() {
  if (props.ads.length < 2) return
  if (index.value === 0) {
    // Jump to the clone at the end without animation, then step back one.
    animate.value = false
    index.value = props.ads.length
    requestAnimationFrame(() => requestAnimationFrame(() => { animate.value = true; index.value = props.ads.length - 1 }))
    return
  }
  animate.value = true
  index.value--
}
function goTo(i: number) {
  animate.value = true
  index.value = i
}
function onEnd() {
  if (index.value === props.ads.length) {
    animate.value = false
    index.value = 0
  }
}

// Swipe on phones.
let touchX = 0
const touchedAt = ref(0)
function onTouchStart(e: TouchEvent) {
  touchX = e.touches[0].clientX
  touchedAt.value = Date.now()
}
function onTouchEnd(e: TouchEvent) {
  const dx = e.changedTouches[0].clientX - touchX
  if (Math.abs(dx) > 40) (dx < 0 ? next : prev)()
}

// Auto-advance: paused on hover/focus/touch, off-screen, hidden tab, or reduced motion.
const canAnimate = typeof matchMedia === 'function' && !matchMedia('(prefers-reduced-motion: reduce)').matches
const stopped = ref(false)
const hovering = ref(false)
const focused = ref(false)
const root = ref<HTMLElement>()
let onScreen = true
let timer: number | undefined
let io: IntersectionObserver | undefined
onMounted(() => {
  if (!canAnimate || props.ads.length < 2) return
  io = new IntersectionObserver(([e]) => (onScreen = e.isIntersecting), { threshold: 0.3 })
  if (root.value) io.observe(root.value)
  timer = window.setInterval(() => {
    if (stopped.value || hovering.value || focused.value || !onScreen || document.hidden || Date.now() - touchedAt.value < 6000) return
    next()
  }, props.interval)
})
onUnmounted(() => {
  clearInterval(timer)
  io?.disconnect()
})
</script>

<style scoped>
.nav {
  position: absolute; top: 50%; transform: translateY(-50%); height: 2.5rem; width: 2.5rem; border-radius: 9999px;
  background: rgb(255 255 255 / 0.85); color: var(--color-ink); display: none; place-items: center; box-shadow: 0 2px 8px rgb(0 0 0 / 0.2);
}
@media (hover: hover) and (min-width: 640px) { .nav { display: grid; } }
</style>
