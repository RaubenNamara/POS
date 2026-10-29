<template>
  <div class="space-y-3">
    <div class="card p-3 flex flex-wrap gap-2 items-end">
      <div class="flex-1 min-w-48"><label class="label">Search</label><input v-model="filters.q" class="input" placeholder="Name, phone or email" /></div>
      <label class="flex items-center gap-2 text-sm h-10"><input v-model="filters.owing" type="checkbox" class="accent-brand-600" /> Owing money</label>
      <button v-if="can('customers.manage')" class="btn-primary" @click="open = true"><Icon name="plus" :size="16" /> New customer</button>
    </div>
    <div class="card overflow-x-auto">
      <table class="tbl">
        <thead><tr><th>Name</th><th>Phone</th><th class="num">Purchases</th><th>Last purchase</th><th class="num">Credit limit</th><th class="num">Owes</th></tr></thead>
        <tbody>
          <tr v-for="c in rows" :key="c.id" class="hover:bg-gray-50 cursor-pointer" @click="router.push(`/admin/customers/${c.id}`)">
            <td class="font-medium">{{ c.name }}</td><td>{{ c.phone }}</td><td class="num">{{ c.sales_count }}</td>
            <td class="text-xs">{{ c.last_purchase ? new Date(c.last_purchase).toLocaleDateString() : '' }}</td>
            <td class="num">{{ Number(c.credit_limit) ? money(c.credit_limit, false) : '-' }}</td>
            <td class="num font-semibold" :class="Number(c.balance) > 0 ? 'text-warn' : 'text-muted'">{{ money(c.balance, false) }}</td>
          </tr>
          <tr v-if="!rows.length"><td colspan="6" class="text-center text-muted py-8">No customers.</td></tr>
        </tbody>
      </table>
    </div>
    <Pager :page="meta.current_page" :last="meta.last_page" @go="go" />
    <Modal :open="open" title="New customer" @close="open = false"><CustomerForm @saved="open = false; load()" /></Modal>
  </div>
</template>

<script setup lang="ts">
import { ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import CustomerForm from '@/components/CustomerForm.vue'
import Icon from '@/components/Icon.vue'
import Modal from '@/components/Modal.vue'
import Pager from '@/components/Pager.vue'
import { money } from '@/lib/money'
import { usePaged } from '@/lib/usePaged'
import { can } from '@/stores/auth'
import { loadLookups } from '@/stores/lookups'

const route = useRoute()
const router = useRouter()
const open = ref(false)
loadLookups()
const { filters, rows, meta, go, load } = usePaged<any>('/admin/customers', { q: '', owing: route.query.owing === '1' })
</script>
