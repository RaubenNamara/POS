<template>
  <!-- Sign-in and set-up pages: just the logo, nothing else to tap away to. -->
  <div v-if="focus" class="min-h-screen flex flex-col bg-page">
    <header class="bg-white border-b border-line">
      <div class="shell h-14 sm:h-16 flex items-center justify-between">
        <RouterLink to="/" class="flex items-center gap-1.5" aria-label="Home">
          <img v-if="shop.settings.shop_logo" :src="img(shop.settings.shop_logo)" alt="" class="h-8 sm:h-9 w-auto" />
          <span class="font-black text-lg sm:text-2xl tracking-tight uppercase text-ink">{{ shopName }}</span>
          <span class="h-5 w-5 sm:h-6 sm:w-6 rounded-full bg-brand-500 text-white grid place-items-center"><Icon name="cart" :size="12" /></span>
        </RouterLink>
        <span class="inline-flex items-center gap-1.5 text-xs sm:text-sm text-muted"><Icon name="shield" :size="16" /> Secure</span>
      </div>
    </header>
    <main class="flex-1"><RouterView /></main>
    <p class="text-center text-xs text-muted py-6">&copy; {{ year }} {{ shopName }}</p>
  </div>

  <div v-else class="min-h-screen flex flex-col bg-page pb-[calc(3.75rem+env(safe-area-inset-bottom))] md:pb-0">
    <header class="bg-white sticky top-0 z-40 shadow-[0_1px_3px_rgb(0_0_0/0.08)]">
      <!-- Top row: logo · search · account · help · cart -->
      <div class="shell h-14 sm:h-16 flex items-center gap-2 sm:gap-4 lg:gap-6">
        <button class="lg:hidden btn-ghost px-2 -ml-2" aria-label="Categories" @click="menuOpen = true"><Icon name="menu" /></button>
        <RouterLink to="/" class="flex items-center gap-1.5 shrink-0" aria-label="Home">
          <img v-if="shop.settings.shop_logo" :src="img(shop.settings.shop_logo)" alt="" class="h-8 sm:h-9 w-auto" />
          <span class="font-black text-lg sm:text-2xl tracking-tight uppercase text-ink">{{ shopName }}</span>
          <span class="h-5 w-5 sm:h-6 sm:w-6 rounded-full bg-brand-500 text-white grid place-items-center"><Icon name="cart" :size="12" /></span>
        </RouterLink>

        <form class="hidden md:flex flex-1 min-w-0 items-center gap-2" role="search" @submit.prevent="doSearch">
          <SearchBox v-model="q" />
          <button class="h-11 px-6 rounded-full bg-brand-500 hover:bg-brand-600 text-white font-semibold text-sm shadow-sm" type="submit">Search</button>
        </form>
        <div class="flex-1 md:hidden"></div>

        <!-- Account -->
        <div ref="accountEl" class="relative">
          <button class="h-11 px-2 sm:px-3 rounded-md flex items-center gap-1.5 text-[15px] font-medium hover:bg-gray-100" :class="{ 'bg-gray-100': open === 'account' }" :aria-expanded="open === 'account'" @click="toggle('account')">
            <Icon name="user" :size="22" />
            <span class="hidden sm:inline max-w-[9rem] truncate">{{ auth.user ? `Hi, ${auth.user.name.split(' ')[0]}` : 'Account' }}</span>
            <Icon name="down" :size="16" class="hidden sm:block transition-transform" :class="{ 'rotate-180': open === 'account' }" />
          </button>
          <div v-if="open === 'account'" class="dropdown w-56 right-0 sm:left-0 sm:right-auto">
            <div class="p-3 border-b border-line">
              <RouterLink v-if="!auth.user" to="/login" class="btn-primary w-full h-11 shadow" @click="open = null">Sign In</RouterLink>
              <template v-else>
                <div class="font-semibold truncate">{{ auth.user.name }}</div>
                <div class="text-xs text-muted truncate">{{ auth.user.email }}</div>
              </template>
            </div>
            <RouterLink v-if="auth.user?.is_staff" :to="staffHome" class="dd-item" @click="open = null"><Icon name="settings" :size="18" /> Staff area</RouterLink>
            <RouterLink :to="auth.user ? '/account/location' : '/login?next=/account/location'" class="dd-item" @click="open = null"><Icon name="user" :size="18" /> My Account</RouterLink>
            <RouterLink :to="auth.user ? '/account/orders' : '/login?next=/account/orders'" class="dd-item" @click="open = null"><Icon name="receipt" :size="18" /> Orders</RouterLink>
            <RouterLink to="/account/wishlist" class="dd-item" @click="open = null"><Icon name="heart" :size="18" /> Wishlist <span v-if="wishlistCount" class="ml-auto badge bg-brand-100 text-brand-700">{{ wishlistCount }}</span></RouterLink>
            <button v-if="auth.user" class="dd-item w-full text-bad border-t border-line" @click="signOut"><Icon name="logout" :size="18" /> Sign out</button>
          </div>
        </div>

        <!-- Help -->
        <div ref="helpEl" class="relative hidden sm:block">
          <button class="h-11 px-3 rounded-md flex items-center gap-1.5 text-[15px] font-medium hover:bg-gray-100" :class="{ 'bg-gray-100': open === 'help' }" :aria-expanded="open === 'help'" @click="toggle('help')">
            <Icon name="help" :size="22" /> <span>Help</span> <Icon name="down" :size="16" class="transition-transform" :class="{ 'rotate-180': open === 'help' }" />
          </button>
          <div v-if="open === 'help'" class="dropdown w-60 right-0">
            <RouterLink v-for="h in helpLinks" :key="h.to" :to="h.to" class="dd-item" @click="open = null">{{ h.label }}</RouterLink>
            <div v-if="shop.settings.shop_phone || whatsapp" class="p-3 space-y-2 border-t border-line">
              <a v-if="shop.settings.shop_phone" :href="`tel:${shop.settings.shop_phone.replace(/\s+/g, '')}`" class="btn-primary w-full h-11"><Icon name="phone" :size="18" /> Call us</a>
              <a v-if="whatsapp" :href="whatsapp" target="_blank" rel="noopener" class="btn w-full h-11 border-2 border-[#25d366] text-[#128c4b] hover:bg-green-50"><Icon name="whatsapp" :size="18" /> WhatsApp</a>
            </div>
          </div>
        </div>

        <RouterLink to="/cart" class="relative h-11 px-2 sm:px-3 rounded-md flex items-center gap-1.5 text-[15px] font-medium hover:bg-gray-100" aria-label="Cart">
          <Icon name="cart" :size="22" />
          <span class="hidden sm:inline">Cart</span>
          <span v-if="cartCount" class="absolute top-0.5 left-5 min-w-5 h-5 px-1 rounded-full bg-brand-500 text-white text-[11px] font-bold grid place-items-center">{{ cartCount }}</span>
        </RouterLink>
      </div>

      <!-- Phone: search gets its own full-width row -->
      <form class="md:hidden shell pb-2 flex gap-2" role="search" @submit.prevent="doSearch">
        <SearchBox v-model="q" />
        <button class="h-11 px-4 rounded-full bg-brand-500 text-white font-semibold text-sm" type="submit" aria-label="Search"><Icon name="search" :size="18" /></button>
      </form>

      <!-- Category bar with icons (desktop/tablet) -->
      <nav class="hidden md:block border-t border-line/70" aria-label="Categories">
        <div class="shell flex items-center gap-1 overflow-x-auto [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">
          <RouterLink v-for="c in shop.categories" :key="c.id" :to="`/c/${c.slug}`" class="cat-link" active-class="!text-brand-700">
            <Icon :name="iconFor(c.name)" :size="20" /> {{ c.name }}
          </RouterLink>
          <RouterLink to="/search?type=bundle" class="cat-link"><Icon name="layers" :size="20" /> Packages</RouterLink>
        </div>
      </nav>
    </header>

    <!-- Mobile category drawer -->
    <div v-if="menuOpen" class="fixed inset-0 z-50 bg-black/40" @click.self="menuOpen = false">
      <aside class="bg-white w-80 max-w-[85vw] h-full overflow-y-auto">
        <div class="flex items-center justify-between px-4 h-14 border-b border-line">
          <span class="font-bold">Categories</span>
          <button class="btn-ghost px-2" aria-label="Close" @click="menuOpen = false"><Icon name="x" /></button>
        </div>
        <div class="py-2">
          <template v-for="c in shop.categories" :key="c.id">
            <RouterLink :to="`/c/${c.slug}`" class="flex items-center gap-3 px-4 py-2.5 font-semibold" @click="menuOpen = false"><Icon :name="iconFor(c.name)" :size="20" class="text-brand-600" /> {{ c.name }}</RouterLink>
            <RouterLink v-for="s in c.children" :key="s.id" :to="`/c/${s.slug}`" class="block pl-12 pr-4 py-2 text-sm text-muted" @click="menuOpen = false">{{ s.name }}</RouterLink>
          </template>
          <RouterLink to="/search?type=bundle" class="flex items-center gap-3 px-4 py-2.5 font-semibold" @click="menuOpen = false"><Icon name="layers" :size="20" class="text-brand-600" /> Packages</RouterLink>
          <hr class="my-2 border-line" />
          <RouterLink v-for="h in helpLinks" :key="h.to" :to="h.to" class="block px-4 py-2 text-sm" @click="menuOpen = false">{{ h.label }}</RouterLink>
          <a v-if="whatsapp" :href="whatsapp" target="_blank" rel="noopener" class="flex items-center gap-2 px-4 py-2 text-sm text-[#128c4b] font-semibold"><Icon name="whatsapp" :size="18" /> Chat on WhatsApp</a>
        </div>
      </aside>
    </div>

    <main class="flex-1"><RouterView /></main>

    <footer class="bg-[#3e2b1f] text-[#eadfd4] text-sm mt-8 md:mt-10">
      <div class="shell py-6 md:py-10 grid gap-0 md:grid-cols-[1.3fr_1fr_1fr_1.2fr] md:gap-8">
        <!-- Brand + contact -->
        <div class="space-y-3 pb-5 md:pb-0">
          <div class="font-black text-white text-xl uppercase tracking-tight">{{ shopName }}</div>
          <p v-if="shop.settings.shop_address" class="flex gap-2"><Icon name="home" :size="16" class="shrink-0 mt-0.5 text-brand-500" /> {{ shop.settings.shop_address }}</p>
          <div v-if="shop.settings.shop_phone || whatsapp || shop.settings.shop_email" class="flex flex-wrap gap-2">
            <a v-if="shop.settings.shop_phone" :href="`tel:${shop.settings.shop_phone.replace(/\s+/g, '')}`" class="foot-btn"><Icon name="phone" :size="16" /> {{ shop.settings.shop_phone }}</a>
            <a v-if="whatsapp" :href="whatsapp" target="_blank" rel="noopener" class="foot-btn"><Icon name="whatsapp" :size="16" /> WhatsApp</a>
            <a v-if="shop.settings.shop_email" :href="`mailto:${shop.settings.shop_email}`" class="foot-btn truncate max-w-full">{{ shop.settings.shop_email }}</a>
          </div>
          <AppBadges class="pt-1" />
        </div>

        <!-- Link groups: tap-to-open on phones, always open in columns on larger screens -->
        <details v-for="g in footerGroups" :key="g.title" class="foot-group" :open="wide">
          <summary class="foot-head">{{ g.title }} <Icon name="down" :size="18" class="chev md:hidden" /></summary>
          <ul class="foot-list">
            <li v-for="l in g.links" :key="l.to"><RouterLink :to="l.to" class="hover:text-white">{{ l.label }}</RouterLink></li>
          </ul>
        </details>

        <details class="foot-group" :open="wide">
          <summary class="foot-head">Payment <Icon name="down" :size="18" class="chev md:hidden" /></summary>
          <div class="foot-list">
            <div class="flex flex-wrap gap-1.5">
              <span v-if="shop.settings.online_payments === '1'" class="pay-chip">MTN MoMo</span>
              <span v-if="shop.settings.online_payments === '1'" class="pay-chip">Airtel Money</span>
              <span v-if="shop.settings.online_payments === '1'" class="pay-chip">Visa / Mastercard</span>
              <span v-if="shop.settings.cod_enabled !== '0'" class="pay-chip">Pay on delivery</span>
            </div>
            <p class="text-xs text-[#c9b4a2] mt-2">Pay on delivery in {{ shop.settings.cod_districts || 'Kampala, Wakiso' }}<span v-if="shop.settings.online_payments === '1'">; elsewhere pay online when you order</span>.</p>
          </div>
        </details>
      </div>
      <div class="border-t border-white/10">
        <div class="shell py-4 text-xs text-[#c9b4a2] flex flex-col sm:flex-row gap-1 sm:justify-between">
          <span>&copy; {{ year }} {{ shopName }}. All rights reserved.</span>
          <RouterLink to="/help/returns" class="hover:text-white">Returns policy</RouterLink>
        </div>
      </div>
    </footer>

    <!-- Phone tab bar -->
    <nav class="md:hidden fixed inset-x-0 bottom-0 z-40 bg-white border-t border-line grid grid-cols-4 pb-[env(safe-area-inset-bottom)]" aria-label="Main">
      <RouterLink to="/" class="tab" :class="{ on: route.name === 'home' }"><Icon name="home" :size="22" /><span>Home</span></RouterLink>
      <button class="tab" :class="{ on: menuOpen }" @click="menuOpen = true"><Icon name="menu" :size="22" /><span>Categories</span></button>
      <RouterLink to="/cart" class="tab relative" :class="{ on: route.name === 'cart' || route.name === 'checkout' }">
        <Icon name="cart" :size="22" /><span>Cart</span>
        <span v-if="cartCount" class="absolute top-1 left-1/2 ml-1.5 min-w-4.5 h-4.5 px-1 rounded-full bg-brand-500 text-white text-[10px] font-bold grid place-items-center">{{ cartCount }}</span>
      </RouterLink>
      <RouterLink :to="auth.user ? '/account/orders' : '/login'" class="tab" :class="{ on: String(route.name ?? '').match(/^(orders|order|location|wishlist|login|register)$/) }">
        <Icon name="user" :size="22" /><span>{{ auth.user ? 'Account' : 'Sign in' }}</span>
      </RouterLink>
    </nav>

    <button v-show="showTop" class="fixed bottom-[calc(4.5rem+env(safe-area-inset-bottom))] md:bottom-5 right-4 md:right-5 z-30 h-11 w-11 md:h-12 md:w-12 rounded-full bg-white border-2 border-ink text-ink grid place-items-center shadow-lg hover:bg-gray-50" aria-label="Back to top" @click="toTop">
      <Icon name="up" :size="22" />
    </button>
  </div>
</template>

<script setup lang="ts">
import { computed, defineComponent, h, onMounted, onUnmounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import AppBadges from '@/components/AppBadges.vue'
import Icon from '@/components/Icon.vue'
import { img } from '@/lib/api'
import { iconFor } from '@/lib/categoryIcon'
import { auth, logout } from '@/stores/auth'
import { cartCount } from '@/stores/cart'
import { loadHome, shop } from '@/stores/shop'
import { wishlistCount } from '@/stores/wishlist'

const route = useRoute()
const router = useRouter()
const focus = computed(() => !!route.meta.focus)
const q = ref(String(route.query.q ?? ''))
const menuOpen = ref(false)
const open = ref<'account' | 'help' | null>(null)
const accountEl = ref<HTMLElement>()
const helpEl = ref<HTMLElement>()
const showTop = ref(false)
const shopName = computed(() => shop.settings.shop_name || 'Duka Shop')
const staffHome = computed(() => (auth.user?.abilities.includes('reports.operational') ? '/admin' : auth.user?.abilities.includes('pos.sell') ? '/pos' : '/admin/products'))
const whatsapp = computed(() => (shop.settings.shop_whatsapp ? `https://wa.me/${shop.settings.shop_whatsapp}` : ''))
const year = new Date().getFullYear()
// Footer groups start open on tablets/desktops, collapsed on phones.
const wide = ref(matchMedia('(min-width: 768px)').matches)
const mq = matchMedia('(min-width: 768px)')
const onMq = () => (wide.value = mq.matches)
const helpLinks = [
  { to: '/help', label: 'Help Center' },
  { to: '/help/place-order', label: 'Place an Order' },
  { to: '/help/pay', label: 'Payment Options' },
  { to: '/help/track', label: 'Delivery & Track your Order' },
  { to: '/help/returns', label: 'Returns and Refunds' },
  { to: '/help/warranty', label: 'Warranty' },
]

const footerGroups = computed(() => [
  { title: 'Shop', links: [...shop.categories.slice(0, 7).map((c) => ({ to: `/c/${c.slug}`, label: c.name })), { to: '/search?type=bundle', label: 'Packages' }] },
  { title: 'Need help?', links: helpLinks },
])

const SearchBox = defineComponent({
  props: { modelValue: String },
  emits: ['update:modelValue'],
  setup: (p, { emit }) => () =>
    h('label', { class: 'relative flex-1 min-w-0' }, [
      h('span', { class: 'sr-only' }, 'Search products'),
      h(Icon, { name: 'search', size: 20, class: 'absolute left-3.5 top-1/2 -translate-y-1/2 text-muted pointer-events-none' }),
      h('input', {
        value: p.modelValue, type: 'search', autocomplete: 'off', enterkeyhint: 'search',
        placeholder: innerWidth < 768 ? 'Search products' : 'Search products, brands and categories',
        class: 'w-full h-11 rounded-full bg-gray-100 border border-transparent pl-11 pr-4 text-base sm:text-[15px] outline-none focus:bg-white focus:border-brand-500 focus:ring-2 focus:ring-brand-100',
        onInput: (e: Event) => emit('update:modelValue', (e.target as HTMLInputElement).value),
      }),
    ]),
})

const toggle = (which: 'account' | 'help') => (open.value = open.value === which ? null : which)
function onDocClick(e: MouseEvent) {
  const t = e.target as Node
  if (open.value && !accountEl.value?.contains(t) && !helpEl.value?.contains(t)) open.value = null
}
const onKey = (e: KeyboardEvent) => e.key === 'Escape' && (open.value = null)
const onScroll = () => (showTop.value = scrollY > 900)
const toTop = () => scrollTo({ top: 0, behavior: 'smooth' })

loadHome().catch(() => {})
watch(() => route.query.q, (v) => (q.value = String(v ?? '')))
watch(() => route.fullPath, () => (open.value = null))
watch(shopName, (n) => (document.title = n), { immediate: true })
onMounted(() => {
  document.addEventListener('click', onDocClick)
  addEventListener('keydown', onKey)
  addEventListener('scroll', onScroll, { passive: true })
  mq.addEventListener('change', onMq)
})
onUnmounted(() => {
  document.removeEventListener('click', onDocClick)
  removeEventListener('keydown', onKey)
  removeEventListener('scroll', onScroll)
  mq.removeEventListener('change', onMq)
})

function doSearch() {
  const term = q.value.trim()
  router.push({ name: 'search', query: term ? { q: term } : {} })
}
async function signOut() {
  open.value = null
  await logout()
  router.replace('/')
}
</script>

<style scoped>
.dropdown {
  position: absolute;
  top: calc(100% + 0.375rem);
  z-index: 50;
  background: #fff;
  border-radius: 0.5rem;
  box-shadow: 0 8px 28px rgb(0 0 0 / 0.16);
  overflow: hidden;
  padding-bottom: 0.25rem;
}
.dd-item { display: flex; align-items: center; gap: 0.75rem; padding: 0.625rem 1rem; font-size: 0.9375rem; }
.dd-item:hover { background: #f3f4f6; }
.cat-link { display: inline-flex; align-items: center; gap: 0.5rem; white-space: nowrap; padding: 0.75rem 0.75rem; font-size: 0.9375rem; color: var(--color-ink); border-radius: 0.375rem; }
.cat-link:hover { color: var(--color-brand-700); }

.foot-btn { display: inline-flex; align-items: center; gap: 0.375rem; border: 1px solid rgb(255 255 255 / 0.18); border-radius: 9999px; padding: 0.375rem 0.75rem; color: #fff; }
.foot-btn:hover { background: rgb(255 255 255 / 0.08); }
.foot-group { border-top: 1px solid rgb(255 255 255 / 0.1); }
.foot-head { list-style: none; cursor: pointer; display: flex; align-items: center; justify-content: space-between; padding: 0.875rem 0; font-weight: 700; color: #fff; }
.foot-head::-webkit-details-marker { display: none; }
.foot-group[open] .chev { transform: rotate(180deg); }
.chev { transition: transform 0.15s; color: rgb(255 255 255 / 0.6); }
.foot-list { padding-bottom: 0.875rem; }
.foot-list li { padding: 0.3rem 0; }
.pay-chip { background: rgb(255 255 255 / 0.08); border: 1px solid rgb(255 255 255 / 0.12); color: #fff; border-radius: 0.375rem; padding: 0.2rem 0.5rem; font-size: 0.75rem; font-weight: 600; }
@media (min-width: 768px) {
  .foot-group { border-top: 0; }
  .foot-head { padding: 0 0 0.75rem; cursor: default; pointer-events: none; }
  .foot-list { padding-bottom: 0; }
}

.tab { display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 0.125rem; height: 3.75rem; font-size: 0.6875rem; font-weight: 600; color: var(--color-muted); }
.tab.on { color: var(--color-brand-600); }
</style>
