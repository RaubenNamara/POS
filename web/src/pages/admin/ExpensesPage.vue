<template>
  <div class="grid lg:grid-cols-[1fr_340px] gap-4">
    <div class="space-y-3">
      <div class="card p-3 flex flex-wrap gap-2 items-end">
        <div><label class="label">Category</label><select v-model="filters.category_id" class="input"><option value="">All</option><option v-for="c in lookups.expense_categories" :key="c.id" :value="c.id">{{ c.name }}</option></select></div>
        <div><label class="label">From</label><input v-model="filters.from" type="date" class="input" /></div>
        <div><label class="label">To</label><input v-model="filters.to" type="date" class="input" /></div>
        <label class="flex items-center gap-2 text-sm h-10"><input v-model="filters.voided" type="checkbox" class="accent-brand-600" /> Show voided</label>
      </div>
      <div class="card overflow-x-auto">
        <table class="tbl">
          <thead><tr><th>Date</th><th>Category</th><th>Description</th><th>Method</th><th>By</th><th class="num">Amount</th><th></th></tr></thead>
          <tbody>
            <tr v-for="e in rows" :key="e.id" :class="{ 'line-through text-muted': e.voided_at }">
              <td>{{ e.expense_date?.slice(0, 10) }}</td><td>{{ e.category?.name }}</td><td>{{ e.description }}</td><td>{{ e.method?.name }}</td><td class="text-xs">{{ e.user?.name }}</td>
              <td class="num">{{ money(e.amount, false) }}</td>
              <td><button v-if="!e.voided_at" class="text-xs text-bad" @click="voidIt(e)">Void</button></td>
            </tr>
            <tr v-if="!rows.length"><td colspan="7" class="text-center text-muted py-8">No expenses.</td></tr>
          </tbody>
        </table>
      </div>
      <Pager :page="meta.current_page" :last="meta.last_page" @go="go" />
    </div>
    <form class="card p-4 space-y-3 h-max" @submit.prevent="add">
      <h3 class="font-semibold">Record expense</h3>
      <div><label class="label">Category</label><select v-model="f.expense_category_id" class="input" required><option v-for="c in lookups.expense_categories" :key="c.id" :value="c.id">{{ c.name }}</option></select></div>
      <div><label class="label">Description</label><input v-model="f.description" class="input" required /></div>
      <div class="grid grid-cols-2 gap-2">
        <div><label class="label">Amount</label><input v-model="f.amount" class="input" inputmode="decimal" required /></div>
        <div><label class="label">Date</label><input v-model="f.expense_date" type="date" class="input" :max="today" required /></div>
      </div>
      <div><label class="label">Paid by</label><select v-model="f.payment_method_id" class="input" required><option v-for="m in lookups.payment_methods" :key="m.id" :value="m.id">{{ m.name }}</option></select></div>
      <div><label class="label">Reference</label><input v-model="f.reference" class="input" /></div>
      <p class="text-xs text-muted">Cash expenses are taken from your open till shift. Stock purchases go through Receive goods, not here.</p>
      <p v-if="error" class="text-sm text-bad">{{ error }}</p>
      <button class="btn-primary w-full" :disabled="busy">Save expense</button>
    </form>
  </div>
</template>

<script setup lang="ts">
import { reactive, ref } from 'vue'
import Pager from '@/components/Pager.vue'
import { errorText, post, uuid } from '@/lib/api'
import { money } from '@/lib/money'
import { toast } from '@/lib/toast'
import { usePaged } from '@/lib/usePaged'
import { loadLookups, lookups } from '@/stores/lookups'

const today = new Date().toISOString().slice(0, 10)
const f = reactive<any>({ expense_category_id: null, payment_method_id: null, description: '', amount: '', expense_date: today, reference: '', key: uuid() })
const busy = ref(false)
const error = ref('')
loadLookups().then(() => {
  f.expense_category_id = lookups.expense_categories[0]?.id
  f.payment_method_id = lookups.payment_methods[0]?.id
})
const { filters, rows, meta, go, load } = usePaged<any>('/admin/expenses', { category_id: '', from: '', to: '', voided: false })

async function add() {
  busy.value = true
  error.value = ''
  try {
    const { key, ...body } = f
    await post('/admin/expenses', { ...body, client_uuid: key, reference: f.reference || null })
    toast('Expense recorded')
    Object.assign(f, { description: '', amount: '', reference: '', key: uuid() })
    load()
  } catch (e) {
    error.value = errorText(e)
  } finally {
    busy.value = false
  }
}
async function voidIt(e: any) {
  const reason = prompt('Why is this expense being voided?')
  if (!reason) return
  try {
    await post(`/admin/expenses/${e.id}/void`, { reason })
    load()
  } catch (err) {
    toast(errorText(err), 'error')
  }
}
</script>
