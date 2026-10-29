<template>
  <div class="space-y-3 max-w-5xl 2xl:max-w-[max(80rem,70vw)]">
    <p class="card p-3 text-sm text-muted">
      Sales rung up while a till was offline are checked when they sync. If stock had already run out (sold elsewhere) or an
      offline rule was broken, the sale is held here and <b>nothing was deducted</b>. Fix the cause (e.g. do a stock count if goods were
      really there), then <b>Retry</b> to record the sale for the original cashier, or <b>Void</b> it with a note.
    </p>
    <div class="card overflow-x-auto">
      <table class="tbl">
        <thead><tr><th>When</th><th>Cashier</th><th>Items</th><th>Reason</th><th>Status</th><th></th></tr></thead>
        <tbody>
          <tr v-for="c in rows" :key="c.id">
            <td class="text-xs">{{ new Date(c.created_at).toLocaleString() }}<br /><span class="text-muted">rung up {{ c.payload.offline_created_at }}</span></td>
            <td>{{ c.user?.name }}</td>
            <td class="text-xs">{{ c.payload.items.map((i: any) => `${i.quantity} × #${i.product_id}`).join(', ') }}</td>
            <td class="text-sm text-warn">{{ c.reason }}</td>
            <td><span class="badge bg-gray-100">{{ c.status }}</span> <RouterLink v-if="c.sale" :to="`/admin/sales/${c.sale.id}`" class="text-xs text-brand-700">{{ c.sale.number }}</RouterLink></td>
            <td class="whitespace-nowrap"><template v-if="c.status === 'open'">
              <button class="btn-primary btn-sm" @click="retry(c)">Retry</button>
              <button class="btn-ghost btn-sm text-bad" @click="voidIt(c)">Void</button></template></td>
          </tr>
          <tr v-if="!rows.length"><td colspan="6" class="text-center text-ok py-8">No offline sales need review.</td></tr>
        </tbody>
      </table>
    </div>
    <Pager :page="meta.current_page" :last="meta.last_page" @go="go" />
  </div>
</template>

<script setup lang="ts">
import Pager from '@/components/Pager.vue'
import { errorText, post } from '@/lib/api'
import { toast } from '@/lib/toast'
import { usePaged } from '@/lib/usePaged'

const { rows, meta, go, load } = usePaged<any>('/admin/offline-conflicts')
async function retry(c: any) {
  try {
    await post(`/admin/offline-conflicts/${c.id}/retry`)
    toast('Sale recorded')
    load()
  } catch (e) {
    toast(errorText(e), 'error')
  }
}
async function voidIt(c: any) {
  const note = prompt('Why is this offline sale being voided?')
  if (!note) return
  await post(`/admin/offline-conflicts/${c.id}/void`, { note })
  load()
}
</script>
