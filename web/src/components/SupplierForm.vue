<template>
  <form class="space-y-3" @submit.prevent="save">
    <div><label class="label">Name *</label><input v-model="f.name" class="input" required /></div>
    <div class="grid grid-cols-2 gap-3">
      <div><label class="label">Contact person</label><input v-model="f.contact_person" class="input" /></div>
      <div><label class="label">Phone</label><input v-model="f.phone" class="input" /></div>
    </div>
    <div class="grid grid-cols-2 gap-3">
      <div><label class="label">Email</label><input v-model="f.email" type="email" class="input" /></div>
      <div><label class="label">Opening balance owed</label><input v-model="f.opening_balance" class="input" inputmode="decimal" /></div>
    </div>
    <div><label class="label">Address</label><input v-model="f.address" class="input" /></div>
    <div><label class="label">Notes</label><textarea v-model="f.notes" class="input h-16 py-2"></textarea></div>
    <p v-if="error" class="text-sm text-bad">{{ error }}</p>
    <button class="btn-primary w-full" :disabled="busy">Save supplier</button>
  </form>
</template>

<script setup lang="ts">
import { reactive, ref } from 'vue'
import { errorText, post, put } from '@/lib/api'
import { toast } from '@/lib/toast'
import { loadLookups } from '@/stores/lookups'

const props = defineProps<{ supplier?: any }>()
const emit = defineEmits<{ saved: [s: any] }>()
const s = props.supplier ?? {}
const f = reactive({ name: s.name ?? '', contact_person: s.contact_person ?? '', phone: s.phone ?? '', email: s.email ?? '', address: s.address ?? '', notes: s.notes ?? '', opening_balance: s.opening_balance ?? '0' })
const busy = ref(false)
const error = ref('')

async function save() {
  busy.value = true
  try {
    const r = s.id ? await put(`/admin/suppliers/${s.id}`, f) : await post('/admin/suppliers', f)
    toast('Supplier saved')
    loadLookups(true)
    emit('saved', r)
  } catch (e) {
    error.value = errorText(e)
  } finally {
    busy.value = false
  }
}
</script>
