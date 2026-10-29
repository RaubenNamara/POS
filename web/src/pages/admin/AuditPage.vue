<template>
  <div class="space-y-3">
    <div class="card p-3"><label class="label">Action starts with</label><input v-model="filters.action" class="input max-w-xs" placeholder="e.g. sale, stock, product, user" /></div>
    <div class="card overflow-x-auto">
      <table class="tbl">
        <thead><tr><th>When</th><th>Who</th><th>Action</th><th>Record</th><th>Change</th><th>IP</th></tr></thead>
        <tbody>
          <tr v-for="a in rows" :key="a.id">
            <td class="text-xs whitespace-nowrap">{{ new Date(a.created_at).toLocaleString() }}</td><td>{{ a.user_name ?? 'system' }}</td>
            <td><span class="badge bg-gray-100">{{ a.action }}</span></td><td class="text-xs">{{ a.auditable_type }} {{ a.auditable_id }}</td>
            <td class="text-xs max-w-md break-words"><span v-if="a.old_values" class="text-bad">{{ a.old_values }}</span> <span class="text-ok">{{ a.new_values }}</span></td>
            <td class="text-xs">{{ a.ip }}</td>
          </tr>
        </tbody>
      </table>
    </div>
    <Pager :page="meta.current_page" :last="meta.last_page" @go="go" />
  </div>
</template>

<script setup lang="ts">
import Pager from '@/components/Pager.vue'
import { usePaged } from '@/lib/usePaged'

const { filters, rows, meta, go } = usePaged<any>('/admin/audit', { action: '' })
</script>
