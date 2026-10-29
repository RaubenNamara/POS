<template>
  <div class="space-y-5">
    <div v-if="error" class="card p-4 text-bad">{{ error }}</div>
    <template v-if="d">
      <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
          <h2 class="text-xl sm:text-2xl font-bold">{{ greeting }}, {{ firstName }}</h2>
          <p class="text-sm text-muted mt-0.5">{{ today }} · <span class="inline-flex items-center gap-1"><span class="h-1.5 w-1.5 rounded-full bg-green-500"></span> Live, updated {{ new Date(d.generated_at.replace(' ', 'T')).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) }}</span></p>
        </div>
        <div class="flex gap-1 bg-white border border-line rounded-full p-1 text-sm shadow-sm" role="tablist">
          <button v-for="p in periods" :key="p.k" role="tab" :aria-selected="period === p.k" class="px-4 h-8 rounded-full font-medium transition-colors" :class="period === p.k ? 'bg-ink text-white' : 'text-muted hover:text-ink'" @click="period = p.k">{{ p.l }}</button>
        </div>
      </div>

      <!-- Headline numbers -->
      <section class="grid grid-cols-1 min-[420px]:grid-cols-2 lg:grid-cols-4 gap-3">
        <Stat icon="receipt" color="blue" label="Net sales" :value="money(s.net_revenue)" :sub="`${s.sales_count} sales${s.returns_count ? ` · ${s.returns_count} returns` : ''}`" />
        <template v-if="d.financial">
          <Stat icon="box" color="gray" label="Cost of goods sold" :value="money(s.cogs)" />
          <Stat icon="chart" color="green" label="Gross profit" :value="money(s.gross_profit)" :sub="`${s.gross_margin_pct}% margin`" :tone="tone(s.gross_profit)" />
          <Stat icon="wallet" color="orange" label="Net profit" :value="money(s.net_profit)" :sub="netSub" :tone="tone(s.net_profit)" :hint="netHint" />
        </template>
        <template v-else>
          <Stat icon="box" color="gray" label="Products" :value="String(d.inventory.products)" :sub="`${d.inventory.units} units in stock`" />
          <Stat icon="alert" color="orange" label="Low stock" :value="String(d.inventory.low_stock)" tone="warn" />
          <Stat icon="alert" color="red" label="Out of stock" :value="String(d.inventory.out_of_stock)" tone="bad" />
        </template>
      </section>

      <!-- Things waiting for someone: shown only when there are some. -->
      <section v-if="d.pending_orders || d.open_conflicts || d.inventory.out_of_stock" class="grid sm:grid-cols-2 lg:grid-cols-3 gap-3">
        <Todo v-if="d.pending_orders" to="/admin/orders?status=pending" icon="truck" color="orange" :title="`${d.pending_orders} online ${d.pending_orders === 1 ? 'order' : 'orders'} waiting`" sub="Confirm and send them out" />
        <Todo v-if="d.open_conflicts" to="/admin/offline" icon="wifioff" color="amber" :title="`${d.open_conflicts} offline ${d.open_conflicts === 1 ? 'sale needs' : 'sales need'} review`" sub="Sold while offline, check them" />
        <Todo v-if="d.inventory.out_of_stock" to="/admin/products?stock=out" icon="alert" color="red" :title="`${d.inventory.out_of_stock} ${d.inventory.out_of_stock === 1 ? 'product' : 'products'} out of stock`" sub="Reorder or hide from the shop" />
      </section>

      <section v-if="d.financial" class="grid grid-cols-1 min-[420px]:grid-cols-2 lg:grid-cols-4 gap-3">
        <Stat small label="Inventory capital (at cost)" :value="money(d.inventory.cost_value)" :sub="`${d.inventory.units} units · ${d.inventory.products} products`" />
        <Stat small label="Potential selling value" :value="money(d.inventory.retail_value)" :sub="`${money(d.inventory.potential_profit, false)} potential margin`" />
        <Stat small label="Customers owe you" :value="money(d.balances.receivable)" :sub="`${d.balances.receivable_customers} customers`" link="/admin/customers?owing=1" />
        <Stat small label="You owe suppliers" :value="money(d.balances.payable)" :sub="`${d.balances.payable_suppliers} suppliers`" link="/admin/suppliers?owing=1" />
      </section>

      <section class="grid lg:grid-cols-[2fr_1fr] gap-3 [&>*]:min-w-0">
        <div class="card p-4">
          <BarChart title="Net sales, last 30 days" value-label="Net sales" :points="series" :format="(v) => money(String(v))" />
        </div>
        <div class="card p-4">
          <h3 class="font-semibold text-sm mb-3">Best sellers <span class="text-muted font-normal">(30 days)</span></h3>
          <ol class="space-y-3 text-sm">
            <li v-for="(p, i) in d.top_products" :key="p.product_id ?? i">
              <div class="flex items-center justify-between gap-2">
                <span class="flex items-center gap-2 min-w-0"><span class="h-6 w-6 shrink-0 rounded-full grid place-items-center text-xs font-bold" :class="i === 0 ? 'bg-brand-600 text-white' : 'bg-gray-100 text-muted'">{{ i + 1 }}</span><span class="truncate">{{ p.name }}</span></span>
                <span class="text-muted whitespace-nowrap text-xs">{{ p.units }} sold</span>
              </div>
              <div class="mt-1.5 ml-8 h-1.5 rounded-full bg-gray-100 overflow-hidden"><div class="h-full rounded-full bg-brand-500" :style="{ width: `${Math.max(4, (p.units / topUnits) * 100)}%` }"></div></div>
            </li>
            <li v-if="!d.top_products.length" class="text-muted">No sales yet.</li>
          </ol>
        </div>
      </section>

      <section class="grid lg:grid-cols-3 gap-3 [&>*]:min-w-0">
        <div class="card overflow-hidden">
          <div class="px-4 py-3 flex justify-between border-b border-line"><h3 class="font-semibold text-sm">Recent sales</h3><RouterLink to="/admin/sales" class="text-xs text-brand-700 font-semibold">All</RouterLink></div>
          <ul class="divide-y divide-line text-sm">
            <li v-for="s2 in d.recent_sales" :key="s2.id"><RouterLink :to="`/admin/sales/${s2.id}`" class="flex justify-between px-4 py-2 hover:bg-gray-50">
              <span>{{ s2.number }} <span class="text-xs text-muted">{{ s2.customer?.name ?? (s2.channel === 'online' ? 'online' : '') }}</span></span>
              <span class="num">{{ money(s2.total, false) }}</span></RouterLink></li>
            <li v-if="!d.recent_sales.length" class="px-4 py-3 text-muted">None yet.</li>
          </ul>
        </div>
        <div class="card overflow-hidden">
          <div class="px-4 py-3 flex justify-between border-b border-line"><h3 class="font-semibold text-sm">Low stock</h3><RouterLink to="/admin/insights" class="text-xs text-brand-700 font-semibold">Stock insights</RouterLink></div>
          <ul class="divide-y divide-line text-sm">
            <li v-for="p in d.low_stock" :key="p.id"><RouterLink :to="`/admin/products/${p.id}`" class="flex justify-between px-4 py-2 hover:bg-gray-50">
              <span class="truncate">{{ p.name }}</span><span :class="p.total_stock <= 0 ? 'text-bad' : 'text-warn'" class="font-semibold whitespace-nowrap">{{ p.total_stock }} / min {{ p.min_stock }}</span></RouterLink></li>
            <li v-if="!d.low_stock.length" class="px-4 py-3 text-ok">All products above minimum.</li>
          </ul>
        </div>
        <div class="card overflow-hidden">
          <div class="px-4 py-3 flex justify-between border-b border-line"><h3 class="font-semibold text-sm">Recent purchases</h3><RouterLink to="/admin/goods-receipts" class="text-xs text-brand-700 font-semibold">All</RouterLink></div>
          <ul class="divide-y divide-line text-sm">
            <li v-for="g in d.recent_purchases" :key="g.id" class="flex justify-between px-4 py-2"><span>{{ g.number }} <span class="text-xs text-muted">{{ g.supplier?.name }}</span></span><span class="num">{{ money(g.total, false) }}</span></li>
            <li v-if="!d.recent_purchases.length" class="px-4 py-3 text-muted">None yet.</li>
          </ul>
          <div v-if="d.cash_flow_month" class="px-4 py-3 border-t border-line text-sm">
            <div class="flex justify-between"><span class="text-muted">Cash in this month</span><b class="num">{{ money(d.cash_flow_month.received) }}</b></div>
            <div class="flex justify-between"><span class="text-muted">Net cash movement</span><b class="num">{{ money(d.cash_flow_month.net) }}</b></div>
          </div>
        </div>
      </section>
    </template>
    <div v-else-if="!error" class="grid grid-cols-1 min-[420px]:grid-cols-2 lg:grid-cols-4 gap-3"><div v-for="i in 8" :key="i" class="skeleton h-24"></div></div>
  </div>
</template>

<script setup lang="ts">
import { computed, defineComponent, h, ref, watch } from 'vue'
import { RouterLink } from 'vue-router'
import BarChart from '@/components/BarChart.vue'
import Icon from '@/components/Icon.vue'
import { errorText, get } from '@/lib/api'
import { configureMoney, money, toCents } from '@/lib/money'
import { auth } from '@/stores/auth'
import { pulse } from '@/stores/pulse'

const d = ref<any>(null)
const error = ref('')
const period = ref<'today' | 'week' | 'month'>('today')
const periods = [{ k: 'today', l: 'Today' }, { k: 'week', l: 'This week' }, { k: 'month', l: 'This month' }] as const
const s = computed(() => d.value.summary[period.value])
// Always 30 bars: days without sales are real zeros, not missing points.
const series = computed(() => {
  const byDay = new Map((d.value?.series ?? []).map((p: any) => [p.period, p]))
  const out = []
  for (let i = 29; i >= 0; i--) {
    const dt = new Date(Date.now() - i * 86400000)
    const key = `${dt.getFullYear()}-${String(dt.getMonth() + 1).padStart(2, '0')}-${String(dt.getDate()).padStart(2, '0')}`
    const p: any = byDay.get(key)
    out.push({ label: key, value: p ? toCents(p.revenue) / 100 : 0, count: p?.count ?? 0 })
  }
  return out
})
const tone = (v: string) => (toCents(v) < 0 ? 'bad' : 'ok')
const topUnits = computed(() => Math.max(1, ...(d.value?.top_products ?? []).map((p: any) => Number(p.units))))
const hour = new Date().getHours()
const greeting = hour < 12 ? 'Good morning' : hour < 17 ? 'Good afternoon' : 'Good evening'
const firstName = computed(() => (auth.user?.name ?? '').split(/\s+/)[0])
const today = new Date().toLocaleDateString([], { weekday: 'long', day: 'numeric', month: 'long' })
// Net profit = gross profit - expenses - stock written off (+ stock found by counts/adjustments).
// Say what went into it, so a large adjustment is never hidden inside the number.
const netSub = computed(() => {
  const parts = [`after ${money(s.value.expenses, false)} expenses`]
  const w = toCents(s.value.stock_write_offs ?? '0')
  if (w > 0) parts.push(`${money(s.value.stock_write_offs, false)} stock lost`)
  if (w < 0) parts.push(`+${money(String(-w / 100), false)} stock found`)
  return parts.join(' · ')
})
const netHint = computed(() => (toCents(s.value.stock_write_offs ?? '0') < 0
  ? 'Stock added with Adjust / count is counted as found stock (a gain). New stock you bought should be added with Receive goods instead, so it is not counted as profit.'
  : ''))

async function load() {
  try {
    const [dash, home] = await Promise.all([get('/admin/dashboard'), d.value ? null : get('/shop/home')])
    if (home) configureMoney(home.settings.currency, home.settings.currency_decimals)
    d.value = dash
  } catch (e) {
    error.value = errorText(e)
  }
}
load()
watch(() => pulse.version, load) // a sale anywhere -> refresh

// Soft colour chips for the card icons (one hue per meaning, never loud).
const CHIP: Record<string, string> = {
  blue: 'bg-blue-50 text-blue-600', green: 'bg-green-50 text-green-700', orange: 'bg-brand-50 text-brand-600',
  gray: 'bg-gray-100 text-gray-600', red: 'bg-red-50 text-red-600', amber: 'bg-amber-50 text-amber-700',
}

const Stat = defineComponent({
  props: { label: String, value: String, sub: String, tone: String, link: String, icon: String, color: String, small: Boolean, hint: String },
  setup: (p) => () => {
    const body = [
      h('div', { class: 'flex items-start justify-between gap-2' }, [
        h('div', { class: 'text-[13px] text-muted font-medium' }, p.label),
        p.icon ? h('span', { class: ['h-9 w-9 -mt-1 -mr-1 shrink-0 rounded-lg grid place-items-center', CHIP[p.color ?? 'gray']] }, h(Icon, { name: p.icon, size: 18 })) : null,
      ]),
      h('div', { class: [p.small ? 'text-lg xl:text-xl' : 'text-xl sm:text-2xl xl:text-[1.7rem]', 'font-bold tracking-tight mt-1 num !text-left !whitespace-normal', p.tone === 'bad' ? 'text-bad' : p.tone === 'warn' ? 'text-warn' : 'text-ink'] }, p.value),
      p.sub ? h('div', { class: 'text-xs text-muted mt-1', title: p.hint || undefined }, [p.sub, p.hint ? h('span', { class: 'ml-1 text-warn cursor-help' }, '(?)') : null]) : null,
    ]
    const cls = [p.small ? 'card p-4' : 'card p-4 sm:p-5', 'block']
    return p.link ? h(RouterLink, { to: p.link, class: [...cls, 'hover:border-brand-500 transition-colors'] }, () => body) : h('div', { class: cls }, body)
  },
})

const Todo = defineComponent({
  props: { to: { type: String, required: true }, icon: String, color: String, title: String, sub: String },
  setup: (p) => () => h(RouterLink, { to: p.to, class: 'card p-3.5 flex items-center gap-3 hover:border-brand-500 transition-colors group' }, () => [
    h('span', { class: ['h-10 w-10 shrink-0 rounded-full grid place-items-center', CHIP[p.color ?? 'gray']] }, h(Icon, { name: p.icon ?? 'alert', size: 18 })),
    h('span', { class: 'min-w-0 flex-1' }, [h('span', { class: 'block font-semibold text-sm' }, p.title), h('span', { class: 'block text-xs text-muted' }, p.sub)]),
    h(Icon, { name: 'chevron', size: 18, class: 'text-muted group-hover:text-brand-600' }),
  ]),
})
</script>
