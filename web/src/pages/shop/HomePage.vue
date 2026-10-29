<template>
  <div class="shell py-3 sm:py-4 space-y-4 sm:space-y-5">
    <!-- Hero: category list + CSS-only banner (no large image download, renders instantly) -->
    <section class="grid lg:grid-cols-[14rem_1fr] gap-4">
      <aside class="hidden lg:block bg-white rounded-xl py-2 overflow-y-auto" :class="heroAds.length ? 'min-h-0' : 'h-[19rem]'">
        <RouterLink v-for="c in shop.categories" :key="c.id" :to="`/c/${c.slug}`" class="flex items-center gap-3 px-4 py-2 text-sm hover:text-brand-700">
          {{ c.name }}
        </RouterLink>
        <RouterLink to="/search?type=bundle" class="flex items-center gap-3 px-4 py-2 text-sm hover:text-brand-700">Packages</RouterLink>
      </aside>
      <AdSlider v-if="heroAds.length" :ads="heroAds" :interval="5000" />
      <div v-else class="rounded-xl overflow-hidden relative min-h-44 sm:h-[19rem] bg-brand-50 border border-brand-100 text-ink">
        <div class="relative h-full flex flex-col justify-center px-5 py-6 sm:px-10 max-w-2xl">
          <h1 class="text-[1.65rem] leading-[1.15] sm:text-[2.6rem] sm:leading-[1.1] font-extrabold tracking-tight">
            Genuine products.<br /><span class="text-brand-600">Shop prices, delivered.</span>
          </h1>
          <p class="mt-2 sm:mt-3 text-sm sm:text-lg text-muted">Phones, laptops, electronics and home essentials, with warranty.</p>
          <ul class="hidden sm:flex flex-wrap gap-x-5 gap-y-1 mt-3 text-sm text-ink/80">
            <li class="inline-flex items-center gap-1.5"><Icon name="check" :size="16" class="text-brand-600" /> Genuine brands</li>
            <li class="inline-flex items-center gap-1.5"><Icon name="check" :size="16" class="text-brand-600" /> Warranty included</li>
            <li class="inline-flex items-center gap-1.5"><Icon name="check" :size="16" class="text-brand-600" /> Delivered to your door</li>
          </ul>
          <div class="mt-4 sm:mt-5 flex gap-2">
            <RouterLink to="/search?sort=newest" class="btn rounded-full bg-brand-600 text-white hover:bg-brand-700 px-6">Shop now</RouterLink>
            <RouterLink v-if="home?.bundles.length" to="/search?type=bundle" class="btn rounded-full border border-brand-600 text-brand-700 bg-white hover:bg-brand-100">See packages</RouterLink>
          </div>
        </div>
      </div>
    </section>

    <!-- Phones: category shortcuts (the icon bar is desktop-only) -->
    <section v-if="shop.categories.length" class="md:hidden bg-white rounded-xl p-3">
      <div class="flex gap-2 overflow-x-auto [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">
        <RouterLink v-for="c in shop.categories" :key="c.id" :to="`/c/${c.slug}`" class="shrink-0 w-20 text-center">
          <div class="h-14 w-14 mx-auto rounded-full bg-brand-50 text-brand-700 grid place-items-center"><Icon :name="iconFor(c.name)" :size="24" /></div>
          <div class="text-xs mt-1.5 leading-tight line-clamp-2">{{ c.name }}</div>
        </RouterLink>
      </div>
    </section>

    <template v-if="home">
      <template v-for="(s, i) in sections" :key="s.key">
        <ProductRow :title="s.title" :subtitle="s.subtitle" :items="s.items" :link="s.link" :tone="s.tone" :eager="s.eager" :autoplay="s.key === 'deals' ? 5000 : undefined" :per-step="3" />
        <!-- Wide adverts: after the 1st section, then after every 3rd. -->
        <AdStrip v-if="stripAt(i)" :ad="stripAt(i)!" />
      </template>
      <div v-if="!home.new_arrivals.length" class="bg-white rounded-xl p-10 text-center text-muted">The shop has no products online yet.</div>
    </template>
    <template v-else-if="!failed">
      <div v-for="n in 2" :key="n" class="rounded-xl p-4 bg-white">
        <div class="skeleton h-6 w-48 mb-4"></div>
        <div class="grid grid-cols-2 sm:grid-cols-4 lg:grid-cols-6 gap-3">
          <div v-for="i in 6" :key="i" class="rounded-lg p-2 space-y-2 border border-line"><div class="skeleton aspect-square"></div><div class="skeleton h-3"></div><div class="skeleton h-3 w-1/2"></div></div>
        </div>
      </div>
    </template>
    <div v-else class="bg-white rounded-xl p-8 text-center">
      <p class="text-muted">Could not load the shop. Check your connection.</p>
      <button class="btn-primary mt-3" @click="load">Try again</button>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed, ref } from 'vue'
import AdSlider from '@/components/AdSlider.vue'
import AdStrip from '@/components/AdStrip.vue'
import Icon from '@/components/Icon.vue'
import { iconFor } from '@/lib/categoryIcon'
import ProductRow, { type Tone } from '@/components/ProductRow.vue'
import type { Ad } from '@/lib/ads'
import { loadHome, shop, type HomeData, type ProductCard } from '@/stores/shop'

const home = ref<HomeData | null>(shop.home)
const failed = ref(false)

type Section = { key: string; title: string; subtitle: string; items: ProductCard[]; link: string; tone: Tone; eager?: boolean }

// Every section is built from the live catalogue; empty ones are skipped.
const sections = computed<Section[]>(() => {
  const h = home.value
  if (!h) return []
  const out: Section[] = []
  if (h.deals.length) out.push({ key: 'deals', title: 'Top Deals', subtitle: 'Prices cut on popular items', items: h.deals, link: '/search?on_sale=1', tone: 'accent', eager: true })
  ;(h.category_sections ?? []).forEach((s, i) => {
    out.push({ key: `c-${s.category.slug}`, title: s.category.name, subtitle: `Top picks in ${s.category.name}`, items: s.items, link: `/c/${s.category.slug}`, tone: 'plain', eager: !h.deals.length && i === 0 })
    // Packages slot in after the second category, like a campaign break.
    if (i === 1 && h.bundles.length) out.push({ key: 'bundles', title: 'Save More with Packages', subtitle: 'Everything you need in one go', items: h.bundles, link: '/search?type=bundle', tone: 'accent' })
  })
  if (h.bundles.length && !out.some((s) => s.key === 'bundles')) out.push({ key: 'bundles', title: 'Save More with Packages', subtitle: 'Everything you need in one go', items: h.bundles, link: '/search?type=bundle', tone: 'accent' })
  if (h.new_arrivals.length) out.push({ key: 'new', title: 'New Arrivals', subtitle: 'Just landed in the shop', items: h.new_arrivals, link: '/search?sort=newest', tone: 'plain' })
  return out
})

const heroAds = computed<Ad[]>(() => (home.value as any)?.ads?.hero ?? [])
const stripAds = computed<Ad[]>(() => (home.value as any)?.ads?.strip ?? [])
// Strip adverts go after section 1, 4, 7, ... (never two banners in a row).
const stripAt = (i: number): Ad | undefined => (i % 3 === 0 ? stripAds.value[i / 3] : undefined)

function load() {
  failed.value = false
  loadHome().then((h) => (home.value = h)).catch(() => (failed.value = true))
}
load()
</script>
