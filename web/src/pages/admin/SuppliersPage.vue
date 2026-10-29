<template>
  <div class="space-y-3">
    <div class="card p-3 flex flex-wrap gap-2 items-end">
      <div class="flex-1 min-w-48"><label class="label">Search</label><input v-model="filters.q" class="input" /></div>
      <label class="flex items-center gap-2 text-sm h-10"><input v-model="filters.owing" type="checkbox" class="accent-brand-600" /> Owed money only</label>
      <button v-if="can('suppliers.manage')" class="btn-primary" @click="open = true"><Icon name="plus" :size="16" /> New supplier</button>
    </div>
    <div class="card overflow-x-auto">
      <table class="tbl">
        <thead><tr><th>Supplier</th><th>Contact</th><th>Phone</th><th class="num">Balance owed</th></tr></thead>
        <tbody>
          <tr v-for="s in rows" :key="s.id" class="hover:bg-gray-50" :class="{ 'cursor-pointer': can('suppliers.manage') }" @click="can('suppliers.manage') && router.push(`/admin/suppliers/${s.id}`)">
            <td class="font-medium">{{ s.name }}</td><td>{{ s.contact_person }}</td><td>{{ s.phone }}</td>
            <td class="num font-semibold" :class="Number(s.balance) > 0 ? 'text-warn' : ''">{{ money(s.balance) }}</td>
          </tr>
          <tr v-if="!rows.length"><td colspan="4" class="text-center text-muted py-8">No suppliers.</td></tr>
        </tbody>
      </table>
    </div>
    <Pager :page="meta.current_page" :last="meta.last_page" @go="go" />
    <Modal :open="open" title="New supplier" @close="open = false">
      <SupplierForm @saved="open = false; load()" />
    </Modal>
  </div>
</template>

<script setup lang="ts">
import { ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import Icon from '@/components/Icon.vue'
import Modal from '@/components/Modal.vue'
import Pager from '@/components/Pager.vue'
import SupplierForm from '@/components/SupplierForm.vue'
import { money } from '@/lib/money'
import { usePaged } from '@/lib/usePaged'
import { can } from '@/stores/auth'
import { loadLookups } from '@/stores/lookups'

const route = useRoute()
const router = useRouter()
const open = ref(false)
loadLookups()
const { filters, rows, meta, go, load } = usePaged<any>('/admin/suppliers', { q: '', owing: route.query.owing === '1' })
</script>
