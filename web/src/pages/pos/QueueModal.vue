<template>
  <Modal :open="true" title="Sync status" @close="$emit('close')">
    <div class="text-sm space-y-3">
      <p>
        <b :class="pos.online ? 'text-ok' : 'text-bad'">{{ pos.online ? 'Connected' : 'Offline' }}</b>
        · products updated {{ pos.lastSync ? new Date(pos.lastSync.replace(' ', 'T')).toLocaleString() : 'never' }}
      </p>
      <p class="text-muted">
        Offline sales are provisional until the server accepts them. They are saved on this device (they survive a restart)
        and upload automatically. Limits: cash only, up to {{ money(pos.settings.offline_max_sale_total) }} per sale,
        {{ pos.settings.offline_max_queue }} sales queued.
      </p>
      <ul v-if="pos.queue.length" class="divide-y divide-line border border-line rounded-md">
        <li v-for="q in pos.queue" :key="q.client_uuid" class="p-2 flex items-center gap-2">
          <div class="flex-1">
            <div class="font-medium">{{ q.receipt?.number }} · {{ money(q.receipt?.total) }}</div>
            <div class="text-xs text-muted">{{ new Date(q.created_at).toLocaleString() }}</div>
            <div v-if="q.message" class="text-xs" :class="q.status === 'conflict' ? 'text-warn' : 'text-bad'">{{ q.message }}</div>
          </div>
          <span class="badge" :class="q.status === 'pending' ? 'bg-gray-100' : q.status === 'conflict' ? 'bg-amber-100 text-warn' : 'bg-red-100 text-bad'">{{ q.status }}</span>
          <button v-if="q.status !== 'pending'" class="btn-ghost btn-sm" title="Remove from this device" @click="dismiss(q.client_uuid, q.status)">Dismiss</button>
        </li>
      </ul>
      <p v-else class="text-ok">Nothing waiting to sync.</p>
      <p v-if="pos.queue.some((q) => q.status === 'conflict')" class="text-warn">Conflicts are held on the server for a manager to review (Back office → Offline sales review).</p>
      <button class="btn-primary w-full" :disabled="pos.syncing" @click="syncNow">{{ pos.syncing ? 'Syncing…' : 'Sync now' }}</button>
    </div>
  </Modal>
</template>

<script setup lang="ts">
import Modal from '@/components/Modal.vue'
import { money } from '@/lib/money'
import { dismissQueued, pos, refreshCatalog, syncQueue } from './posStore'

defineEmits<{ close: [] }>()
async function syncNow() {
  await syncQueue()
  await refreshCatalog().catch(() => {})
}
function dismiss(id: string, status: string) {
  if (status === 'rejected' && !confirm('This sale was rejected and is not recorded on the server. Remove it from this device?')) return
  dismissQueued(id)
}
</script>
