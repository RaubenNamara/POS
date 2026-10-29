<template>
  <div class="space-y-5">
    <!-- Period -->
    <div class="flex flex-wrap items-center justify-between gap-3">
      <p class="text-sm text-muted">What is left, what is running out, what sells most, profit and losses.</p>
      <div class="flex gap-1 bg-white border border-line rounded-full p-1 text-sm shadow-sm" role="tablist" aria-label="Period">
        <button v-for="p in periods" :key="p" role="tab" :aria-selected="days === p" class="px-4 h-8 rounded-full font-medium transition-colors"
          :class="days === p ? 'bg-ink text-white' : 'text-muted hover:text-ink'" @click="days = p">Last {{ p }} days</button>
      </div>
    </div>

    <p v-if="error" class="card p-4 text-bad">{{ error }}</p>
    <div v-if="!d && !error" class="grid grid-cols-2 lg:grid-cols-5 gap-3"><div v-for="i in 5" :key="i" class="skeleton h-28"></div></div>

    <template v-if="d">
      <!-- Headline numbers -->
      <section class="grid grid-cols-1 min-[420px]:grid-cols-2 lg:grid-cols-5 gap-3">
        <Kpi icon="box" color="blue" label="Stock remaining" :value="`${d.summary.units.toLocaleString()} units`"
          :sub="d.financial ? `${money(d.summary.cost_value)} at cost · ${d.summary.products} products` : `${d.summary.products} products`" @click="show('all')" />
        <Kpi icon="alert" color="amber" label="Running out" :value="String(d.summary.low_stock + d.summary.running_out)"
          :sub="`${d.summary.low_stock} at alert level · ${d.summary.running_out} under ${d.soon_days} days left`" @click="show('soon')" />
        <Kpi icon="x" color="red" label="Out of stock" :value="String(d.summary.out_of_stock)" sub="Customers cannot buy these" @click="show('out')" />
        <Kpi v-if="d.financial" icon="chart" color="green" label="Profit made" :value="money(d.profit.gross_profit)"
          :sub="`${d.profit.margin_pct}% margin on ${money(d.profit.revenue, false)} sales`" @click="profitEl?.scrollIntoView({ behavior: 'smooth' })" />
        <Kpi v-if="d.financial" icon="trash" color="red" label="Losses" :value="money(d.losses.total)"
          :sub="toCents(d.losses.total) ? 'Damaged, missing or sold below cost' : 'No stock lost in this period'" @click="profitEl?.scrollIntoView({ behavior: 'smooth' })" />
      </section>

      <!-- Restock soon -->
      <section class="card overflow-hidden">
        <header class="px-4 sm:px-5 py-4 flex flex-wrap items-center justify-between gap-3">
          <Head icon="truck" color="amber" title="Restock soon" :sub="`Out of stock, at the alert level, or under ${d.soon_days} days left at today's selling speed`" />
          <RouterLink v-if="can('purchases.receive') && d.restock.length" to="/admin/purchase-orders/new" class="btn-primary btn-sm"><Icon name="plus" :size="14" /> New purchase order</RouterLink>
        </header>
        <div v-if="d.restock.length" class="grid sm:grid-cols-2 xl:grid-cols-3 gap-3 px-4 sm:px-5 pb-5">
          <!-- one tile per product to reorder -->
          <div v-for="r in d.restock" :key="r.id" class="rounded-xl border border-line p-3.5 flex flex-col gap-3 hover:border-gray-300 transition-colors">
            <ProductCell :r="r" />
            <Meter :r="r" />
            <div class="flex items-center justify-between gap-2 text-sm">
              <Left :r="r" />
              <span class="rounded-lg bg-brand-50 text-brand-700 px-2.5 py-1 text-xs">Order <b class="text-sm">{{ r.reorder }}</b></span>
            </div>
          </div>
        </div>
        <div v-else class="mx-4 sm:mx-5 mb-5 rounded-xl bg-green-50 text-ok text-sm px-4 py-4 flex items-center gap-2"><Icon name="check" :size="18" /> Nothing needs restocking right now.</div>
      </section>

      <!-- Most consumed / most profitable -->
      <section class="grid lg:grid-cols-2 gap-3 [&>*]:min-w-0">
        <div class="card p-4 sm:p-5">
          <Head icon="layers" color="blue" title="Most consumed" :sub="`Units that left the shelf, last ${d.days} days (items in packages included)`" />
          <ol class="mt-5 space-y-3.5 text-sm">
            <li v-for="(r, i) in d.most_used" :key="r.id" :title="`${r.name}: ${r.used} used, ${r.stock} left`">
              <div class="flex items-center justify-between gap-2">
                <span class="flex items-center gap-2.5 min-w-0"><Rank :i="i" /><RouterLink :to="`/admin/products/${r.id}`" class="truncate hover:text-brand-700">{{ r.name }}</RouterLink></span>
                <span class="whitespace-nowrap text-xs text-muted"><b class="text-sm text-ink">{{ r.used }}</b> used</span>
              </div>
              <Bar :pct="(r.used / maxUsed) * 100" />
            </li>
            <li v-if="!d.most_used.length" class="text-muted">Nothing sold in this period.</li>
          </ol>
        </div>
        <div v-if="d.financial" class="card p-4 sm:p-5">
          <Head icon="chart" color="green" title="Most profitable" sub="What customers paid minus what the items cost you" />
          <ol class="mt-5 space-y-3.5 text-sm">
            <li v-for="(r, i) in d.most_profitable" :key="r.id" :title="`${r.name}: ${money(r.profit)} profit on ${r.units} sold`">
              <div class="flex items-center justify-between gap-2">
                <span class="flex items-center gap-2.5 min-w-0"><Rank :i="i" /><RouterLink :to="`/admin/products/${r.id}`" class="truncate hover:text-brand-700">{{ r.name }}</RouterLink></span>
                <span class="whitespace-nowrap text-xs text-muted"><b class="text-sm" :class="toCents(r.profit) < 0 ? 'text-bad' : 'text-ink'">{{ money(r.profit, false) }}</b> · {{ r.units }} sold</span>
              </div>
              <Bar :pct="Math.max(0, (toCents(r.profit) / maxProfit) * 100)" />
            </li>
            <li v-if="!d.most_profitable.length" class="text-muted">No completed sales in this period.</li>
          </ol>
        </div>
      </section>

      <!-- Profit and losses -->
      <section v-if="d.financial" ref="profitEl" class="grid lg:grid-cols-[1.6fr_1fr] gap-3 [&>*]:min-w-0 scroll-mt-20">
        <div class="card p-4 sm:p-5">
          <Head icon="wallet" color="green" title="Profit" :sub="`Last ${d.days} days · ${d.profit.sales_count} completed sales`" />
          <div class="mt-5 grid grid-cols-2 gap-4">
            <div>
              <div class="text-[13px] text-muted">Gross profit</div>
              <div class="text-2xl sm:text-3xl font-bold tracking-tight" :class="toCents(d.profit.gross_profit) < 0 ? 'text-bad' : ''">{{ money(d.profit.gross_profit) }}</div>
              <div class="text-xs text-muted mt-0.5">{{ d.profit.margin_pct }}% of sales</div>
            </div>
            <div>
              <div class="text-[13px] text-muted">Net profit <span class="text-xs">(after {{ money(d.profit.expenses, false) }} expenses)</span></div>
              <div class="text-2xl sm:text-3xl font-bold tracking-tight" :class="toCents(d.profit.net_profit) < 0 ? 'text-bad' : ''">{{ money(d.profit.net_profit) }}</div>
            </div>
          </div>

          <!-- Where each shilling of sales went: cost of the items vs profit kept -->
          <div v-if="split" class="mt-6">
            <div class="flex justify-between text-xs text-muted mb-2"><span>Sales <b class="text-ink">{{ money(d.profit.revenue) }}</b></span><span>100%</span></div>
            <div class="flex h-3.5 gap-0.5" role="img" :aria-label="`Of ${money(d.profit.revenue)} sales, ${money(d.profit.cogs)} was the cost of the items and ${money(d.profit.gross_profit)} was profit`">
              <div class="bg-gray-300 rounded-l-full first:rounded-l-full" :class="split.profit <= 0 && 'rounded-r-full'" :style="{ width: `${split.cost}%` }" :title="`Cost of items sold: ${money(d.profit.cogs)}`"></div>
              <div v-if="split.profit > 0" class="bg-brand-600 rounded-r-full" :style="{ width: `${split.profit}%` }" :title="`Gross profit: ${money(d.profit.gross_profit)}`"></div>
            </div>
            <div class="mt-3 flex flex-wrap gap-x-6 gap-y-1.5 text-xs">
              <span class="inline-flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-sm bg-gray-300"></span> Cost of items sold <b>{{ money(d.profit.cogs, false) }}</b> <span class="text-muted">({{ split.cost.toFixed(1) }}%)</span></span>
              <span class="inline-flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-sm bg-brand-600"></span> Profit kept <b>{{ money(d.profit.gross_profit, false) }}</b> <span class="text-muted">({{ split.profit.toFixed(1) }}%)</span></span>
            </div>
          </div>

          <p v-if="toCents(d.losses.found_value)" class="mt-5 text-xs rounded-lg bg-amber-50 text-amber-800 px-3 py-2.5 flex gap-2">
            <Icon name="alert" :size="16" class="shrink-0 mt-px" />
            <span>Net profit includes <b>{{ money(d.losses.found_value) }}</b> of stock added with Adjust / count ({{ d.losses.found_units }} units).
              New stock you bought should be added with Receive goods, so it is not counted as profit.</span>
          </p>
        </div>

        <div class="card p-4 sm:p-5 flex flex-col">
          <Head icon="trash" color="red" title="Losses" :sub="`Last ${d.days} days`" />
          <!-- nothing lost: a calm, compact message -->
          <div v-if="!lossRows.length" class="flex-1 flex flex-col items-center justify-center text-center py-8">
            <span class="h-12 w-12 rounded-full bg-green-50 text-ok grid place-items-center"><Icon name="check" :size="22" /></span>
            <div class="font-semibold mt-3">No losses</div>
            <p class="text-xs text-muted mt-1 max-w-[16rem]">Nothing damaged, missing or sold below cost in this period.</p>
          </div>
          <template v-else>
            <div class="mt-4">
              <div class="text-[13px] text-muted">Total lost</div>
              <div class="text-2xl sm:text-3xl font-bold tracking-tight text-bad">{{ minus(d.losses.total) }}</div>
            </div>
            <ul class="mt-4 space-y-3 text-sm">
              <li v-for="l in lossRows" :key="l.key" :title="`${l.name}: ${l.why}, ${l.units} units, ${money(l.value)}`">
                <div class="flex justify-between gap-3">
                  <span class="min-w-0"><span class="block truncate">{{ l.name }}</span><span class="text-xs text-muted">{{ l.why }} · {{ l.units }} units</span></span>
                  <b class="whitespace-nowrap">{{ minus(l.value, false) }}</b>
                </div>
                <div class="mt-1.5 h-1.5 rounded-full bg-gray-100 overflow-hidden"><div class="h-full rounded-full bg-red-500" :style="{ width: `${Math.max(3, (toCents(l.value) / maxLoss) * 100)}%` }"></div></div>
              </li>
            </ul>
          </template>
          <p v-if="d.losses.returns_count" class="text-xs text-muted mt-4">Also {{ d.losses.returns_count }} return(s) worth {{ money(d.losses.returns_value) }}, already taken out of sales.</p>
        </div>
      </section>

      <!-- Every product -->
      <section ref="tableEl" class="card overflow-hidden scroll-mt-20">
        <header class="px-4 sm:px-5 py-4 flex flex-wrap items-center justify-between gap-3">
          <Head icon="box" color="blue" title="Stock remaining" :sub="`${shown.length} of ${d.products.length} products`" />
          <div class="flex flex-wrap gap-2 items-center">
            <div class="relative">
              <Icon name="search" :size="16" class="absolute left-3 top-1/2 -translate-y-1/2 text-muted pointer-events-none" />
              <input v-model="q" class="input h-9 w-52 pl-9" placeholder="Search product" aria-label="Search product" />
            </div>
            <!-- quick filters -->
            <div class="flex gap-1 bg-gray-100 rounded-lg p-1 text-xs font-medium overflow-x-auto max-w-full">
              <button v-for="f in FILTERS" :key="f.k" class="px-2.5 h-7 rounded-md whitespace-nowrap transition-colors"
                :class="filter === f.k ? 'bg-white shadow-sm text-ink' : 'text-muted hover:text-ink'" @click="filter = f.k">
                {{ f.l }} <span class="text-muted">{{ count(f.k) }}</span>
              </button>
            </div>
          </div>
        </header>
        <!-- phones: one card per product -->
        <ul class="md:hidden divide-y divide-line border-t border-line">
          <li v-for="r in shown" :key="r.id" class="px-4 py-3.5 space-y-2.5">
            <div class="flex items-start justify-between gap-3"><ProductCell :r="r" /><Status :s="r.status" class="shrink-0 mt-1" /></div>
            <Meter :r="r" />
            <div class="flex justify-between gap-3 text-xs text-muted">
              <Left :r="r" /><span>{{ r.used }} used<template v-if="d.financial"> · {{ money(r.stock_value, false) }}</template></span>
            </div>
          </li>
          <li v-if="!shown.length" class="text-center text-muted py-10 text-sm">No products match.</li>
        </ul>
        <table class="tbl hidden md:table">
          <thead><tr>
            <th>Product</th><th class="min-w-44">Stock level</th><th class="num">Used ({{ d.days }} days)</th>
            <th>Time left</th><th>Status</th><th v-if="d.financial" class="num">Value at cost</th>
          </tr></thead>
          <tbody>
            <tr v-for="r in shown" :key="r.id">
              <td class="wrap"><ProductCell :r="r" /></td>
              <td><Meter :r="r" /></td>
              <td class="num">{{ r.used || '-' }}</td>
              <td><Left :r="r" /></td>
              <td><Status :s="r.status" /></td>
              <td v-if="d.financial" class="num">{{ money(r.stock_value, false) }}</td>
            </tr>
            <tr v-if="!shown.length"><td :colspan="d.financial ? 6 : 5" class="text-center text-muted py-10">No products match.</td></tr>
          </tbody>
        </table>
      </section>
    </template>
  </div>
</template>

<script setup lang="ts">
import { computed, defineComponent, h, ref, watch } from 'vue'
import { RouterLink } from 'vue-router'
import Icon from '@/components/Icon.vue'
import { errorText, get, img } from '@/lib/api'
import { configureMoney, minus, money, toCents } from '@/lib/money'
import { can } from '@/stores/auth'
import { pulse } from '@/stores/pulse'

type Filter = 'all' | 'soon' | 'out' | 'not_selling' | 'ok'
const periods = [7, 30, 90] as const
const days = ref<number>(30)
const d = ref<any>(null)
const error = ref('')
const q = ref('')
const filter = ref<Filter>('all')
const profitEl = ref<HTMLElement>()
const tableEl = ref<HTMLElement>()
// Clicking a headline card shows those products in the full list below.
function show(f: Filter) {
  filter.value = f
  tableEl.value?.scrollIntoView({ behavior: 'smooth' })
}
let moneyReady = false

async function load() {
  error.value = ''
  try {
    if (!moneyReady) {
      const home = await get('/shop/home')
      configureMoney(home.settings.currency, home.settings.currency_decimals)
      moneyReady = true
    }
    d.value = await get('/admin/reports/stock_insights', { days: days.value })
  } catch (e) {
    error.value = errorText(e)
  }
}
load()
watch(days, load)
watch(() => pulse.version, load) // a sale or stock change anywhere refreshes the page

const maxUsed = computed(() => Math.max(1, ...(d.value?.most_used ?? []).map((r: any) => r.used)))
const maxProfit = computed(() => Math.max(1, ...(d.value?.most_profitable ?? []).map((r: any) => toCents(r.profit))))

// Sales split into cost of the items and the profit kept (percent of sales).
const split = computed(() => {
  const rev = toCents(d.value?.profit?.revenue ?? '0')
  if (rev <= 0) return null
  const profit = Math.max(0, Math.min(100, (toCents(d.value.profit.gross_profit) / rev) * 100))
  return { cost: 100 - profit, profit }
})

const lossRows = computed(() => {
  const l = d.value?.losses
  if (!l) return []
  return [
    ...l.write_offs.map((w: any) => ({ key: `w${w.id}${w.reason}`, name: w.name, why: reasonLabel(w.reason), units: w.units, value: w.value })),
    ...l.below_cost.map((b: any) => ({ key: `b${b.id}`, name: b.name, why: 'Sold below cost', units: b.units, value: b.value })),
  ].sort((a, b) => toCents(b.value) - toCents(a.value))
})
const maxLoss = computed(() => Math.max(1, ...lossRows.value.map((l) => toCents(l.value))))

const FILTERS: { k: Filter; l: string }[] = [
  { k: 'all', l: 'All' }, { k: 'soon', l: 'Running out' }, { k: 'out', l: 'Out' }, { k: 'not_selling', l: 'Not selling' }, { k: 'ok', l: 'Healthy' },
]
const matches = (r: any, f: Filter) => f === 'all' || (f === 'soon' ? ['low', 'running_out'].includes(r.status) : r.status === f)
const count = (f: Filter) => (d.value?.products ?? []).filter((r: any) => matches(r, f)).length
const shown = computed(() => {
  const term = q.value.trim().toLowerCase()
  return (d.value?.products ?? [])
    .filter((r: any) => matches(r, filter.value))
    .filter((r: any) => !term || r.name.toLowerCase().includes(term) || (r.sku ?? '').toLowerCase().includes(term))
    .sort((a: any, b: any) => order(a) - order(b) || a.name.localeCompare(b.name))
})
const RANK: Record<string, number> = { out: 0, low: 1, running_out: 2, ok: 3, not_selling: 4 }
const order = (r: any) => RANK[r.status] ?? 9

// Status: reserved colours, always with a label (never colour alone).
const STATUS: Record<string, { label: string; dot: string; text: string; bar: string }> = {
  out: { label: 'Out of stock', dot: 'bg-red-500', text: 'text-bad', bar: 'bg-red-500' },
  low: { label: 'Low', dot: 'bg-amber-500', text: 'text-warn', bar: 'bg-amber-500' },
  running_out: { label: 'Running out', dot: 'bg-amber-500', text: 'text-warn', bar: 'bg-amber-500' },
  not_selling: { label: 'Not selling', dot: 'bg-gray-400', text: 'text-muted', bar: 'bg-gray-400' },
  ok: { label: 'Healthy', dot: 'bg-green-500', text: 'text-ok', bar: 'bg-green-500' },
}
const reasonLabel = (t: string) => ({ damaged: 'Damaged', missing: 'Missing', count: 'Count shortfall', adjustment: 'Adjusted down' } as Record<string, string>)[t] ?? t

// ---- small pieces ----
const CHIP: Record<string, string> = {
  blue: 'bg-blue-50 text-blue-600', green: 'bg-green-50 text-green-700', red: 'bg-red-50 text-red-600', amber: 'bg-amber-50 text-amber-700',
}
const Kpi = defineComponent({
  inheritAttrs: false,
  props: { label: String, value: String, sub: String, icon: String, color: String },
  setup: (p, { attrs }) => () => h(attrs.onClick ? 'button' : 'div', {
    class: ['card p-4 sm:p-5 text-left block w-full', attrs.onClick && 'hover:border-brand-500 hover:-translate-y-px hover:shadow-md transition'],
    onClick: attrs.onClick,
  }, [
    h('div', { class: 'flex items-start justify-between gap-2' }, [
      h('div', { class: 'text-[13px] text-muted font-medium' }, p.label),
      h('span', { class: ['h-9 w-9 -mt-1 -mr-1 shrink-0 rounded-lg grid place-items-center', CHIP[p.color ?? 'blue']] }, h(Icon, { name: p.icon ?? 'box', size: 18 })),
    ]),
    h('div', { class: 'text-xl xl:text-2xl font-bold tracking-tight mt-1' }, p.value),
    h('div', { class: 'text-xs text-muted mt-1' }, p.sub),
  ]),
})
const Head = defineComponent({
  props: { icon: String, color: String, title: String, sub: String },
  setup: (p) => () => h('div', { class: 'flex items-center gap-3 min-w-0' }, [
    h('span', { class: ['h-9 w-9 shrink-0 rounded-lg grid place-items-center', CHIP[p.color ?? 'blue']] }, h(Icon, { name: p.icon ?? 'box', size: 18 })),
    h('div', { class: 'min-w-0' }, [h('h3', { class: 'font-bold leading-tight' }, p.title), p.sub ? h('p', { class: 'text-xs text-muted mt-0.5' }, p.sub) : null]),
  ]),
})
const ProductCell = defineComponent({
  props: { r: { type: Object, required: true } },
  setup: (p) => () => h(RouterLink, { to: `/admin/products/${p.r.id}`, class: 'flex items-center gap-3 min-w-0 hover:text-brand-700' }, () => [
    h('span', { class: 'h-10 w-10 shrink-0 rounded-lg bg-gray-50 ring-1 ring-line grid place-items-center overflow-hidden' },
      p.r.image ? h('img', { src: img(p.r.image), alt: '', loading: 'lazy', class: 'max-h-full max-w-full object-contain mix-blend-multiply' }) : h(Icon, { name: 'box', size: 16, class: 'text-gray-300' })),
    h('span', { class: 'min-w-0' }, [h('span', { class: 'block font-medium truncate' }, p.r.name), h('span', { class: 'block text-xs text-muted' }, p.r.sku)]),
  ]),
})
// Stock level meter: how full the shelf is compared with a healthy level
// (about 30 days of sales, and at least three times the alert level).
const Meter = defineComponent({
  props: { r: { type: Object, required: true } },
  setup: (p) => () => {
    const r = p.r as any
    const target = Math.max(r.min_stock * 3, Math.ceil(r.per_day * 30), 1)
    const pct = Math.max(r.stock > 0 ? 4 : 0, Math.min(100, (Math.max(0, r.stock) / target) * 100))
    return h('div', { class: 'min-w-36', title: `${r.stock} in stock · alert at ${r.min_stock}` }, [
      h('div', { class: 'flex items-baseline justify-between gap-2 text-xs' }, [
        h('span', null, [h('b', { class: ['text-sm', r.stock <= 0 ? 'text-bad' : 'text-ink'] }, String(r.stock)), h('span', { class: 'text-muted' }, ' in stock')]),
        h('span', { class: 'text-muted' }, `alert at ${r.min_stock}`),
      ]),
      h('div', { class: 'mt-1.5 h-1.5 rounded-full bg-gray-100 overflow-hidden' }, h('div', { class: ['h-full rounded-full', STATUS[r.status]?.bar ?? 'bg-gray-400'], style: { width: `${pct}%` } })),
    ])
  },
})
const Left = defineComponent({
  props: { r: { type: Object, required: true } },
  setup: (p) => () => {
    const r = p.r as any
    if (r.stock <= 0) return h('span', { class: 'text-sm font-semibold text-bad' }, 'None left')
    if (r.days_left === null) return h('span', { class: 'text-xs text-muted' }, 'No recent sales')
    const cls = r.days_left < 7 ? 'text-bad' : r.days_left < 14 ? 'text-warn' : 'text-ink'
    return h('span', { class: ['text-sm font-medium', cls] }, r.days_left === 0 ? 'Under a day' : `About ${r.days_left} day${r.days_left === 1 ? '' : 's'}`)
  },
})
const Status = defineComponent({
  props: { s: { type: String, required: true } },
  setup: (p) => () => {
    const st = STATUS[p.s] ?? STATUS.ok
    return h('span', { class: ['inline-flex items-center gap-1.5 text-xs font-semibold', st.text] }, [h('span', { class: ['h-2 w-2 rounded-full', st.dot] }), st.label])
  },
})
const Rank = defineComponent({
  props: { i: { type: Number, required: true } },
  setup: (p) => () => h('span', { class: ['h-6 w-6 shrink-0 rounded-full grid place-items-center text-xs font-bold', p.i === 0 ? 'bg-brand-600 text-white' : 'bg-gray-100 text-muted'] }, String(p.i + 1)),
})
const Bar = defineComponent({
  props: { pct: { type: Number, required: true }, color: { type: String, default: 'bg-brand-600' } },
  setup: (p) => () => h('div', { class: 'mt-1.5 ml-[2.125rem] h-1.5 rounded-full bg-gray-100 overflow-hidden' }, h('div', { class: ['h-full rounded-full', p.color], style: { width: `${Math.max(3, Math.min(100, p.pct))}%` } })),
})
</script>
