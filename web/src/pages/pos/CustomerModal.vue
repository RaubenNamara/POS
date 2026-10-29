<template>
  <Modal :open="true" title="Customer" @close="$emit('close')">
    <div v-if="!creating">
      <input ref="qEl" v-model="q" class="input mb-2" placeholder="Search name or phone" @input="find" />
      <button v-if="pos.customer" class="btn-outline btn-sm mb-2 w-full" @click="pick(null)">Remove customer (walk-in)</button>
      <ul class="divide-y divide-line border border-line rounded-md max-h-72 overflow-y-auto">
        <li v-for="c in list" :key="c.id">
          <button class="w-full text-left px-3 py-2 hover:bg-brand-50 flex justify-between" @click="pick(c)">
            <span>{{ c.name }} <span class="text-muted text-xs">{{ c.phone }}</span></span>
            <span v-if="Number(c.credit_limit) > 0" class="text-xs text-ok">credit {{ money(c.credit_limit, false) }}</span>
          </button>
        </li>
        <li v-if="!list.length" class="px-3 py-4 text-center text-sm text-muted">{{ pos.online ? 'No match' : 'Customer search needs a connection' }}</li>
      </ul>
      <button v-if="can('customers.manage')" class="btn-outline w-full mt-3" @click="creating = true">+ New customer</button>
    </div>
    <form v-else class="space-y-3" @submit.prevent="create">
      <div><label class="label">Name</label><input v-model="nc.name" class="input" required /></div>
      <div><label class="label">Phone</label><input v-model="nc.phone" class="input" inputmode="tel" /></div>
      <p class="text-xs text-muted">Credit limits are set by a manager in Customers.</p>
      <p v-if="error" class="text-sm text-bad">{{ error }}</p>
      <div class="flex gap-2"><button type="button" class="btn-outline flex-1" @click="creating = false">Back</button><button class="btn-primary flex-1">Save</button></div>
    </form>
  </Modal>
</template>

<script setup lang="ts">
import { nextTick, reactive, ref } from 'vue'
import Modal from '@/components/Modal.vue'
import { errorText, get, post } from '@/lib/api'
import { money } from '@/lib/money'
import { can } from '@/stores/auth'
import { pos } from './posStore'

const emit = defineEmits<{ close: [] }>()
const q = ref('')
const list = ref<any[]>([])
const creating = ref(false)
const nc = reactive({ name: '', phone: '' })
const error = ref('')
const qEl = ref<HTMLInputElement>()

let t: number | undefined
function find() {
  clearTimeout(t)
  t = window.setTimeout(() => get('/admin/pos/customers', { q: q.value }).then((r) => (list.value = r)).catch(() => (list.value = [])), 200)
}
find()
nextTick(() => qEl.value?.focus())

function pick(c: any) {
  pos.customer = c
  emit('close')
}

async function create() {
  try {
    pick(await post('/admin/customers', nc))
  } catch (e) {
    error.value = errorText(e)
  }
}
</script>
