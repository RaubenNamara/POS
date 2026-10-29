<template>
  <div class="grid lg:grid-cols-[1fr_340px] gap-4">
    <div class="card overflow-x-auto">
      <table class="tbl">
        <thead><tr><th>Name</th><th>Email / phone</th><th>Role</th><th>Last sign-in</th><th>Status</th><th></th></tr></thead>
        <tbody>
          <tr v-for="u in users" :key="u.id">
            <td class="font-medium">{{ u.name }}</td><td class="text-sm">{{ u.email }}<br /><span class="text-muted">{{ u.phone }}</span></td>
            <td class="capitalize">{{ u.role.replace('_', ' ') }}</td><td class="text-xs">{{ u.last_login_at ? new Date(u.last_login_at).toLocaleString() : 'never' }}</td>
            <td><span class="badge" :class="u.is_active ? 'bg-green-50 text-ok' : 'bg-gray-100'">{{ u.is_active ? 'active' : 'disabled' }}</span></td>
            <td><button class="text-xs text-brand-700" @click="edit(u)">Edit</button></td>
          </tr>
        </tbody>
      </table>
    </div>
    <form class="card p-4 space-y-3 h-max" @submit.prevent="save">
      <h3 class="font-semibold">{{ f.id ? 'Edit staff member' : 'Add staff member' }}</h3>
      <div><label class="label">Name</label><input v-model="f.name" class="input" required /></div>
      <div><label class="label">Email (sign-in)</label><input v-model="f.email" type="email" class="input" required /></div>
      <div><label class="label">Phone (can also sign in)</label><input v-model="f.phone" class="input" /></div>
      <div><label class="label">Role</label>
        <select v-model="f.role" class="input"><option value="cashier">Cashier: POS, payments, receipts</option><option value="stock_manager">Stock manager: receiving, counts</option><option value="manager">Manager: products, stock, buying, refunds</option><option value="owner">Owner: everything incl. profits</option></select></div>
      <div><label class="label">{{ f.id ? 'New password (leave empty to keep)' : 'Password' }}</label><input v-model="f.password" type="password" class="input" :required="!f.id" minlength="8" autocomplete="new-password" /></div>
      <label class="flex items-center gap-2 text-sm"><input v-model="f.is_active" type="checkbox" class="accent-brand-600" /> Active</label>
      <p v-if="error" class="text-sm text-bad">{{ error }}</p>
      <div class="flex gap-2"><button v-if="f.id" type="button" class="btn-outline flex-1" @click="reset">Cancel</button><button class="btn-primary flex-1" :disabled="busy">Save</button></div>
    </form>
  </div>
</template>

<script setup lang="ts">
import { reactive, ref } from 'vue'
import { errorText, get, post, put } from '@/lib/api'
import { toast } from '@/lib/toast'

const users = ref<any[]>([])
const blank = { id: null, name: '', email: '', phone: '', role: 'cashier', password: '', is_active: true }
const f = reactive<any>({ ...blank })
const busy = ref(false)
const error = ref('')
const load = async () => (users.value = await get('/admin/users'))
load()
const reset = () => Object.assign(f, blank)
const edit = (u: any) => Object.assign(f, { ...u, phone: u.phone ?? '', password: '' })

async function save() {
  busy.value = true
  error.value = ''
  try {
    const body = { ...f, password: f.password || undefined }
    f.id ? await put(`/admin/users/${f.id}`, body) : await post('/admin/users', body)
    toast('Staff saved')
    reset()
    load()
  } catch (e) {
    error.value = errorText(e)
  } finally {
    busy.value = false
  }
}
</script>
