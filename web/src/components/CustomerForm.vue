<template>
  <form class="space-y-3" @submit.prevent="save">
    <div><label class="label">Name *</label><input v-model="f.name" class="input" required /></div>
    <div class="grid grid-cols-2 gap-3">
      <div><label class="label">Phone</label><input v-model="f.phone" class="input" inputmode="tel" /></div>
      <div><label class="label">Email</label><input v-model="f.email" type="email" class="input" /></div>
    </div>
    <div><label class="label">Address</label><input v-model="f.address" class="input" /></div>
    <div v-if="can('sales.refund')"><label class="label">Credit limit (0 = no credit)</label><input v-model="f.credit_limit" class="input" inputmode="decimal" /></div>
    <div><label class="label">Notes</label><textarea v-model="f.notes" class="input h-16 py-2"></textarea></div>
    <p v-if="error" class="text-sm text-bad">{{ error }}</p>
    <button class="btn-primary w-full" :disabled="busy">Save</button>
  </form>
</template>

<script setup lang="ts">
import { reactive, ref } from 'vue'
import { errorText, post, put } from '@/lib/api'
import { toast } from '@/lib/toast'
import { can } from '@/stores/auth'

const props = defineProps<{ customer?: any }>()
const emit = defineEmits<{ saved: [c: any] }>()
const c = props.customer ?? {}
const f = reactive({ name: c.name ?? '', phone: c.phone ?? '', email: c.email ?? '', address: c.address ?? '', credit_limit: c.credit_limit ?? '0', notes: c.notes ?? '' })
const busy = ref(false)
const error = ref('')
async function save() {
  busy.value = true
  try {
    const r = c.id ? await put(`/admin/customers/${c.id}`, f) : await post('/admin/customers', f)
    toast('Customer saved')
    emit('saved', r)
  } catch (e) {
    error.value = errorText(e)
  } finally {
    busy.value = false
  }
}
</script>
