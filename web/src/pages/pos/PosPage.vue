<template>
  <div class="h-[100dvh] flex flex-col bg-page select-none">
    <!-- Top bar -->
    <header class="h-14 bg-[#111827] text-white flex items-center gap-2 sm:gap-3 px-3 sm:px-4 shrink-0">
      <span class="h-8 w-8 rounded-lg bg-brand-600 grid place-items-center font-black shrink-0">{{ (pos.settings.shop_name || 'P').charAt(0) }}</span>
      <span class="min-w-0 leading-tight">
        <span class="block font-bold truncate">{{ pos.settings.shop_name || 'POS' }}</span>
        <span class="block text-[11px] text-gray-400 truncate">{{ pos.location?.name }} · {{ auth.user?.name }}</span>
      </span>
      <div class="flex-1"></div>
      <button class="flex items-center gap-1.5 text-xs font-semibold rounded-full px-3 h-8" :class="pos.online ? 'bg-green-600/20 text-green-300' : 'bg-red-600 text-white'" title="Sync status" @click="queueOpen = true">
        <Icon :name="pos.online ? 'check' : 'wifioff'" :size="14" />
        <span>{{ pos.online ? (pos.syncing ? 'Syncing…' : 'Online') : 'Offline' }}</span>
        <span v-if="pendingCount" class="bg-white text-ink rounded-full px-1.5 font-bold">{{ pendingCount }}</span>
      </button>
      <button class="pos-top" @click="shiftOpen = true"><Icon name="clock" :size="14" /> {{ pos.shift ? 'Shift' : 'Open shift' }}</button>
      <RouterLink to="/admin/sales" class="pos-top hidden sm:inline-flex"><Icon name="receipt" :size="14" /> Sales</RouterLink>
      <RouterLink v-if="can('reports.operational') || can('products.view')" :to="can('reports.operational') ? '/admin' : '/admin/products'" class="pos-top hidden md:inline-flex"><Icon name="home" :size="14" /> Back office</RouterLink>
    </header>

    <div v-if="!pos.ready" class="flex-1 grid place-items-center text-muted">{{ pos.error || 'Loading products…' }}</div>
    <div v-else class="flex-1 min-h-0 grid md:grid-cols-[1fr_23.75rem] lg:grid-cols-[1fr_26.25rem]">
      <!-- Products -->
      <section class="flex flex-col min-h-0 p-2 sm:p-3 gap-2" :class="{ 'hidden md:flex': mobileTab === 'cart' }">
        <div class="relative">
          <span class="absolute left-3 top-1/2 -translate-y-1/2 text-muted"><Icon name="search" :size="18" /></span>
          <input
            ref="searchEl"
            v-model="term"
            class="input h-12 pl-10 text-base"
            placeholder="Scan barcode or search name / SKU   (F2)"
            autocomplete="off"
            enterkeyhint="search"
            @keydown.enter.prevent="onEnter"
            @keydown.down.prevent="focusResult(0)"
          />
        </div>
        <p v-if="message" class="text-sm px-1" :class="messageKind === 'error' ? 'text-bad' : 'text-ok'">{{ message }}</p>
        <div class="flex-1 min-h-0 overflow-y-auto grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5 2xl:grid-cols-6 gap-2.5 content-start pb-2" ref="resultsEl">
          <button
            v-for="p in results"
            :key="p.id"
            class="group relative card text-left p-2 hover:border-brand-500 hover:shadow-md focus:border-brand-600 focus:ring-4 focus:ring-brand-100 outline-none active:scale-[0.98] transition flex flex-col"
            :class="{ 'opacity-50': availableOf(p) <= 0 }"
            @click="add(p)"
          >
            <!-- picture (when the product has one) helps cashiers find items at a glance -->
            <span class="h-20 sm:h-24 rounded-lg bg-gray-50 grid place-items-center overflow-hidden mb-2">
              <img v-if="p.img" :src="img(p.img)" alt="" loading="lazy" class="max-h-full max-w-full object-contain mix-blend-multiply" />
              <Icon v-else name="box" :size="26" class="text-gray-300" />
            </span>
            <span class="text-[13px] font-medium leading-snug line-clamp-2 min-h-[2.4em]">{{ p.n }}</span>
            <span class="mt-1.5 flex items-end justify-between gap-1">
              <b class="text-sm">{{ money(p.p, false) }}</b>
              <span class="text-[11px] font-semibold rounded-full px-1.5" :class="availableOf(p) <= 0 ? 'bg-red-50 text-bad' : availableOf(p) <= 3 ? 'bg-amber-50 text-warn' : 'text-muted'">{{ availableOf(p) <= 0 ? 'Out' : `${availableOf(p)} left` }}</span>
            </span>
            <span v-if="p.t === 'b'" class="absolute top-3 left-3 badge bg-ink text-white">Package</span>
          </button>
          <p v-if="!results.length" class="col-span-full text-center text-muted py-10">No product matches “{{ term }}”.</p>
        </div>
      </section>

      <!-- Cart -->
      <aside class="bg-white border-l border-line flex flex-col min-h-0" :class="{ 'hidden md:flex': mobileTab === 'products' }">
        <div class="flex items-center gap-2 px-3 py-2 border-b border-line">
          <button class="btn-outline btn-sm flex-1 justify-start truncate" @click="customerOpen = true">
            <Icon name="user" :size="14" /> {{ pos.customer ? pos.customer.name : 'Walk-in customer (F4)' }}
          </button>
          <button v-if="pos.cart.length" class="btn-ghost btn-sm text-bad" @click="confirmClear">Clear</button>
        </div>
        <ul class="flex-1 min-h-0 overflow-y-auto divide-y divide-line">
          <li v-for="l in pos.cart" :key="l.id" class="px-3 py-2">
            <div class="flex justify-between gap-2 text-sm">
              <span class="font-medium leading-tight">{{ l.name }}</span>
              <b class="whitespace-nowrap">{{ money(lineTotal(l), false) }}</b>
            </div>
            <div class="flex items-center gap-2 mt-1.5">
              <div class="flex items-center border border-line rounded-md">
                <button class="h-9 w-9 grid place-items-center" aria-label="Fewer" @click="qty(l.id, l.qty - 1)"><Icon name="minus" :size="14" /></button>
                <input :value="l.qty" class="w-12 h-9 text-center font-semibold outline-none" inputmode="numeric" aria-label="Quantity" @change="qty(l.id, Number(($event.target as HTMLInputElement).value) || 0)" />
                <button class="h-9 w-9 grid place-items-center" aria-label="More" @click="qty(l.id, l.qty + 1)"><Icon name="plus" :size="14" /></button>
              </div>
              <span class="text-xs text-muted">× {{ money(l.price, false) }}</span>
              <input v-model="l.discount" class="input h-9 w-24 ml-auto text-right" inputmode="decimal" placeholder="Discount" aria-label="Line discount" />
            </div>
          </li>
          <li v-if="!pos.cart.length" class="p-8 text-center text-muted text-sm">Scan or tap products to start a sale.</li>
        </ul>
        <div class="border-t border-line p-3 space-y-1 text-sm">
          <div class="flex justify-between"><span>Subtotal</span><span class="num">{{ money(fromCents(cartCents.sub)) }}</span></div>
          <div class="flex justify-between items-center gap-2">
            <span>Discount</span>
            <span class="flex items-center gap-2">
              <input v-model="pos.cartDiscount" class="input h-8 w-28 text-right" inputmode="decimal" placeholder="0" aria-label="Cart discount" />
              <span class="num w-24" :class="cartCents.disc ? 'text-bad' : 'text-muted'">{{ cartCents.disc ? '-' : '' }}{{ money(fromCents(cartCents.disc), false) }}</span>
            </span>
          </div>
          <div v-if="cartCents.tax" class="flex justify-between text-muted"><span>{{ pos.settings.tax_label }} {{ pos.settings.tax_inclusive === '1' ? '(incl.)' : '' }}</span><span class="num">{{ money(fromCents(cartCents.tax), false) }}</span></div>
          <div class="flex justify-between text-xl font-black pt-1"><span>Total</span><span class="num">{{ money(fromCents(cartCents.total)) }}</span></div>
          <button class="btn-primary w-full h-14 text-lg mt-2" :disabled="!pos.cart.length" @click="openPay">Pay (F9)</button>
        </div>
      </aside>
    </div>

    <!-- Mobile tab switcher -->
    <nav v-if="pos.ready" class="md:hidden grid grid-cols-2 border-t border-line bg-white shrink-0">
      <button class="h-12 font-semibold" :class="mobileTab === 'products' ? 'text-brand-700' : 'text-muted'" @click="mobileTab = 'products'">Products</button>
      <button class="h-12 font-semibold" :class="mobileTab === 'cart' ? 'text-brand-700' : 'text-muted'" @click="mobileTab = 'cart'">Cart ({{ pos.cart.length }}) · {{ money(fromCents(cartCents.total), false) }}</button>
    </nav>

    <PayModal v-if="payOpen" @close="payOpen = false" @done="onSaleDone" />
    <ReceiptModal v-if="receipt" :receipt="receipt" @close="receipt = null; focusSearch()" />
    <ShiftModal v-if="shiftOpen" @close="shiftOpen = false; focusSearch()" />
    <CustomerModal v-if="customerOpen" @close="customerOpen = false; focusSearch()" />
    <QueueModal v-if="queueOpen" @close="queueOpen = false" />
  </div>
</template>

<script setup lang="ts">
import { computed, defineAsyncComponent, nextTick, onMounted, onUnmounted, ref, shallowRef } from 'vue'
import Icon from '@/components/Icon.vue'
import { img } from '@/lib/api'
import { fromCents, money, toCents } from '@/lib/money'
import { auth, can } from '@/stores/auth'
import { addProduct, availableOf, bootstrap, cartCents, clearCart, findByCode, pendingCount, pos, refreshCatalog, search, setLineQty, syncQueue, type CartLine, type PosProduct } from './posStore'

// Dialogs load on first use, keeping the till's first paint small.
const PayModal = defineAsyncComponent(() => import('./PayModal.vue'))
const ReceiptModal = defineAsyncComponent(() => import('./ReceiptModal.vue'))
const ShiftModal = defineAsyncComponent(() => import('./ShiftModal.vue'))
const CustomerModal = defineAsyncComponent(() => import('./CustomerModal.vue'))
const QueueModal = defineAsyncComponent(() => import('./QueueModal.vue'))

const term = ref('')
const searchEl = ref<HTMLInputElement>()
const resultsEl = ref<HTMLElement>()
const message = ref('')
const messageKind = ref<'ok' | 'error'>('ok')
const payOpen = ref(false)
const shiftOpen = ref(false)
const customerOpen = ref(false)
const queueOpen = ref(false)
const receipt = shallowRef<any>(null)
const mobileTab = ref<'products' | 'cart'>('products')

// Searching an in-memory array is ~1 ms for thousands of products, so no debounce is needed.
const results = computed(() => (pos.ready ? search(term.value) : []))
const lineTotal = (l: CartLine) => fromCents(toCents(l.price) * l.qty - toCents(l.discount || '0'))

function say(text: string, kind: 'ok' | 'error' = 'ok') {
  message.value = text
  messageKind.value = kind
  if (kind === 'error') navigator.vibrate?.(120)
}

function add(p: PosProduct) {
  const err = addProduct(p)
  err ? say(err, 'error') : say(`Added ${p.n}`)
}

function onEnter() {
  const code = term.value.trim()
  if (!code) return
  const exact = findByCode(code)
  if (exact) {
    add(exact)
    term.value = ''
    return
  }
  if (results.value.length === 1) {
    add(results.value[0])
    term.value = ''
  } else if (!results.value.length) say(`No product with code “${code}”`, 'error')
}

function qty(id: number, n: number) {
  const err = setLineQty(id, n)
  if (err) say(err, 'error')
}

function focusResult(i: number) {
  ;(resultsEl.value?.querySelectorAll('button')[i] as HTMLElement | undefined)?.focus()
}
const focusSearch = () => nextTick(() => searchEl.value?.focus())

function confirmClear() {
  if (confirm('Clear this sale?')) clearCart()
}

function openPay() {
  if (!pos.cart.length) return
  if (!pos.shift && pos.settings.require_shift === '1') {
    say('Open a shift before selling', 'error')
    shiftOpen.value = true
    return
  }
  payOpen.value = true
}

function onSaleDone(r: any) {
  payOpen.value = false
  receipt.value = r
  mobileTab.value = 'products'
  term.value = ''
  say(r.provisional ? 'Saved offline. It will sync automatically' : `Sale ${r.number} completed`)
}

// Barcode scanners "type" very fast and end with Enter. Catch scans even when focus is elsewhere.
let buf = ''
let last = 0
function onKey(e: KeyboardEvent) {
  if (e.key === 'F2') { e.preventDefault(); focusSearch() }
  else if (e.key === 'F4') { e.preventDefault(); customerOpen.value = true }
  else if (e.key === 'F9' || (e.key === 'Enter' && e.ctrlKey)) { e.preventDefault(); openPay() }
  const target = e.target as HTMLElement
  if (payOpen.value || target.tagName === 'INPUT' || target.tagName === 'TEXTAREA') return
  const now = performance.now()
  if (now - last > 60) buf = ''
  last = now
  if (e.key === 'Enter' && buf.length >= 4) {
    const p = findByCode(buf)
    p ? add(p) : say(`No product with code “${buf}”`, 'error')
    buf = ''
  } else if (e.key.length === 1) buf += e.key
}

let timer: number | undefined
const goOnline = () => { pos.online = true; syncQueue() }
const goOffline = () => (pos.online = false)
onMounted(async () => {
  addEventListener('keydown', onKey)
  addEventListener('online', goOnline)
  addEventListener('offline', goOffline)
  try {
    await bootstrap()
  } catch (e: any) {
    pos.error = e?.message ?? 'Could not load the till'
  }
  focusSearch()
  syncQueue()
  // Background refresh: push queued sales and pull stock changes from other tills.
  timer = window.setInterval(() => {
    if (document.visibilityState !== 'visible') return
    pendingCount.value ? syncQueue() : refreshCatalog().catch(() => {})
  }, 20000)
})
onUnmounted(() => {
  removeEventListener('keydown', onKey)
  removeEventListener('online', goOnline)
  removeEventListener('offline', goOffline)
  clearInterval(timer)
})
</script>

<style scoped>
.pos-top {
  display: inline-flex; align-items: center; gap: 0.375rem; height: 2rem; padding: 0 0.75rem; border-radius: 9999px;
  font-size: 0.75rem; font-weight: 600; background: rgb(255 255 255 / 0.08); color: #e5e7eb;
}
.pos-top:hover { background: rgb(255 255 255 / 0.16); color: #fff; }
</style>
