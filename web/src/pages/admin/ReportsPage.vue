<template>
  <div class="space-y-4">
    <div class="card p-3 flex flex-wrap gap-2 items-end">
      <div><label class="label">Report</label>
        <select v-model="report" class="input">
          <optgroup v-for="g in visibleGroups" :key="g.title" :label="g.title">
            <option v-for="r in g.items" :key="r.k" :value="r.k">{{ r.l }}</option>
          </optgroup>
        </select></div>
      <div><label class="label">From</label><input v-model="from" type="date" class="input" /></div>
      <div><label class="label">To</label><input v-model="to" type="date" class="input" /></div>
      <div class="flex gap-1">
        <button v-for="p in presets" :key="p.l" class="btn-outline btn-sm h-10" @click="preset(p.d)">{{ p.l }}</button>
      </div>
      <div class="flex-1"></div>
      <button class="btn-primary" :disabled="loading" @click="load">{{ loading ? 'Loading…' : 'Run report' }}</button>
      <div v-if="exportable" class="flex gap-1">
        <button v-for="fmt in ['csv', 'xlsx', 'pdf']" :key="fmt" class="btn-outline h-10 uppercase text-xs" :disabled="exporting" @click="exportAs(fmt)">{{ fmt }}</button>
      </div>
    </div>
    <p v-if="exportMsg" class="text-sm text-muted">{{ exportMsg }}</p>
    <p v-if="error" class="card p-3 text-bad">{{ error }}</p>

    <!-- Profit and loss -->
    <div v-if="data && report === 'profit_loss'" class="card p-4 max-w-2xl">
      <h3 class="font-bold mb-3">Profit and loss · {{ data.from }} to {{ data.to }}</h3>
      <table class="w-full text-sm">
        <PL l="Gross sales" :v="data.gross_sales" /><PL l="Less discounts" :v="`-${data.discounts}`" sub /><PL l="Delivery fees" :v="data.delivery_fees" sub />
        <PL l="Less returns" :v="`-${data.returns_value}`" sub /><PL l="Less tax collected" :v="`-${data.tax_collected}`" sub />
        <PL l="Net revenue" :v="data.net_revenue" b /><PL l="Cost of goods sold" :v="`-${data.cogs}`" />
        <PL l="Gross profit" :v="data.gross_profit" b :note="`${data.gross_margin_pct}% margin`" />
        <PL l="Operating expenses" :v="`-${data.expenses}`" /><PL v-if="toCents(data.stock_write_offs) >= 0" l="Stock written off (damaged, missing, counts)" :v="`-${data.stock_write_offs}`" />
        <PL v-else l="Stock found (added with Adjust / count)" :v="String(-toCents(data.stock_write_offs) / 100)" plus />
        <PL l="Net profit" :v="data.net_profit" b />
      </table>
      <p class="text-xs text-muted mt-3">{{ data.sales_count }} completed sales, {{ data.returns_count }} returns, {{ data.expenses_count }} expenses. Credit sales count as revenue when made; stock purchases are not expenses until sold (weighted-average cost).</p>
    </div>

    <div v-else-if="data && report === 'cash_flow'" class="card overflow-x-auto">
      <table class="tbl"><thead><tr><th>Method</th><th class="num">Received</th><th class="num">Refunded</th><th class="num">Expenses</th><th class="num">Supplier payments</th><th class="num">Net</th></tr></thead>
        <tbody><tr v-for="m in data.methods" :key="m.method"><td>{{ m.method }}</td><td class="num">{{ money(m.received, false) }}</td><td class="num">{{ money(m.refunded, false) }}</td><td class="num">{{ money(m.expenses, false) }}</td><td class="num">{{ money(m.supplier_payments, false) }}</td><td class="num font-semibold">{{ money(m.net, false) }}</td></tr>
          <tr class="font-bold"><td>Total</td><td class="num">{{ money(data.totals.received, false) }}</td><td class="num">{{ money(data.totals.refunded, false) }}</td><td class="num">{{ money(data.totals.expenses, false) }}</td><td class="num">{{ money(data.totals.supplier_payments, false) }}</td><td class="num">{{ money(data.totals.net, false) }}</td></tr></tbody></table>
    </div>

    <div v-else-if="data && report === 'reconcile'" class="card p-4">
      <p v-if="data.ok" class="text-ok font-semibold flex items-center gap-2"><Icon name="check" /> All stock levels, sale totals, payments and returns tie out to their transactions.</p>
      <ul v-else class="list-disc pl-5 text-bad text-sm"><li v-for="p in data.problems" :key="p">{{ p }}</li></ul>
      <p class="text-xs text-muted mt-2">Checked {{ new Date(data.checked_at).toLocaleString() }}</p>
    </div>

    <div v-else-if="data && report === 'sales_series'" class="card p-4">
      <BarChart title="Net sales" value-label="Net sales" :points="data.map((p: any) => ({ label: p.period, value: toCents(p.revenue) / 100, count: p.count }))" :format="(v) => money(String(v))" />
    </div>

    <div v-else-if="data && table" class="card overflow-x-auto">
      <table class="tbl">
        <thead><tr><th v-for="h in table.headers" :key="h">{{ h }}</th></tr></thead>
        <tbody><tr v-for="(r, i) in table.rows" :key="i"><td v-for="(c, j) in r" :key="j" :class="{ num: isNum(c) }">{{ isNum(c) ? fmtNum(c) : c }}</td></tr>
          <tr v-if="!table.rows.length"><td :colspan="table.headers.length" class="text-center text-muted py-6">No data for this period.</td></tr></tbody>
      </table>
      <p v-if="table.truncated" class="text-xs text-muted p-3">Showing the first 200 rows. Export for the full report.</p>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed, defineComponent, h, ref, watch } from 'vue'
import BarChart from '@/components/BarChart.vue'
import Icon from '@/components/Icon.vue'
import { errorText, get, post } from '@/lib/api'
import { money, toCents } from '@/lib/money'
import { can } from '@/stores/auth'
import { loadLookups } from '@/stores/lookups'

loadLookups()
const groups = [
  { title: 'Money', fin: true, items: [{ k: 'profit_loss', l: 'Profit & loss' }, { k: 'cash_flow', l: 'Cash flow by method' }, { k: 'sales_series', l: 'Sales by day' }, { k: 'expenses', l: 'Expenses' }, { k: 'reconcile', l: 'Reconciliation check' }] },
  { title: 'Sales', fin: true, items: [{ k: 'sales', l: 'Sales list' }, { k: 'sale_items', l: 'Items sold' }, { k: 'top_products', l: 'Best sellers' }, { k: 'cashier_performance', l: 'Cashier performance' }, { k: 'returns', l: 'Returns & refunds' }, { k: 'customer_debts', l: 'Customer debts' }] },
  { title: 'Stock', fin: false, items: [{ k: 'low_stock', l: 'Low / out of stock' }, { k: 'slow_moving', l: 'Slow moving' }, { k: 'stock_movements', l: 'Stock movements' }] },
  { title: 'Stock value & buying', fin: true, items: [{ k: 'inventory', l: 'Inventory valuation' }, { k: 'purchases', l: 'Purchases' }, { k: 'supplier_balances', l: 'Supplier balances' }] },
]
const visibleGroups = computed(() => groups.filter((g) => !g.fin || can('reports.financial')))
const report = ref(can('reports.financial') ? 'profit_loss' : 'low_stock')
const iso = (d: Date) => d.toISOString().slice(0, 10)
const now = new Date()
const from = ref(iso(new Date(now.getFullYear(), now.getMonth(), 1)))
const to = ref(iso(now))
const presets = [{ l: 'Today', d: 0 }, { l: '7 days', d: 6 }, { l: '30 days', d: 29 }, { l: 'Year', d: 364 }]
const data = ref<any>(null)
const loading = ref(false)
const error = ref('')
const exporting = ref(false)
const exportMsg = ref('')
const exportable = computed(() => !['reconcile', 'sales_series'].includes(report.value))

const table = computed(() => {
  const d = data.value
  if (!d) return null
  if (d.headers) return d
  if (Array.isArray(d)) return { headers: d.length ? Object.keys(d[0]) : [], rows: d.map((r: any) => Object.values(r)), truncated: false }
  if (report.value === 'inventory') return { headers: ['Measure', 'Value'], rows: Object.entries(d).filter(([, v]) => typeof v !== 'object').map(([k, v]) => [k.replace(/_/g, ' '), v]), truncated: false }
  return null
})

function preset(days: number) {
  const t = new Date()
  to.value = iso(t)
  from.value = iso(new Date(t.getTime() - days * 86400000))
  load()
}

async function load() {
  loading.value = true
  error.value = ''
  data.value = null
  try {
    // 'inventory' shows the valuation summary on screen; its export lists every product.
    data.value = await get(`/admin/reports/${report.value}`, { from: from.value, to: to.value, group: 'day', days: 30 })
  } catch (e) {
    error.value = errorText(e)
  } finally {
    loading.value = false
  }
}
watch(report, load)
load()

async function exportAs(format: string) {
  exporting.value = true
  exportMsg.value = 'Preparing export…'
  try {
    let job = await post('/admin/exports', { report: report.value, format, from: from.value, to: to.value })
    // Big exports run on the queue; poll until done.
    for (let i = 0; i < 120 && ['queued', 'running'].includes(job.status); i++) {
      exportMsg.value = 'Large report: generating in the background…'
      await new Promise((r) => setTimeout(r, 2000))
      job = await get(`/admin/exports/${job.id}`)
    }
    if (job.status !== 'done') throw new Error(job.error || 'Export did not finish; try again later.')
    exportMsg.value = `Ready: ${job.rows} rows.`
    location.href = `/api/admin/exports/${job.id}/download`
  } catch (e) {
    exportMsg.value = errorText(e)
  } finally {
    exporting.value = false
  }
}

const isNum = (v: unknown) => typeof v === 'string' && /^-?\d+\.\d{2,4}$/.test(v)
const fmtNum = (v: string) => money(v, false)

const PL = defineComponent({
  props: { l: String, v: String, b: Boolean, sub: Boolean, note: String, plus: Boolean },
  setup: (p) => () => h('tr', { class: [p.b && 'font-bold border-t border-line', p.sub && 'text-muted'] }, [
    h('td', { class: ['py-1.5', p.sub && 'pl-4'] }, [p.l, p.note ? h('span', { class: 'ml-2 text-xs font-normal text-muted' }, p.note) : null]),
    h('td', { class: ['py-1.5 num', toCents(p.v ?? '0') < 0 && p.b ? 'text-bad' : '', p.plus && 'text-ok'] }, (p.plus ? '+' : '') + money(p.v ?? '0')),
  ]),
})
</script>
