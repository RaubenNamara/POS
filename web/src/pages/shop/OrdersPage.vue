<template>
  <div class="max-w-4xl mx-auto px-3 sm:px-4 py-4">
    <div class="flex items-center justify-between mb-3">
      <h1 class="text-xl font-bold">My orders</h1>
      <button class="btn-ghost btn-sm" @click="signOut"><Icon name="logout" :size="16" /> Sign out</button>
    </div>
    <div v-if="!data" class="card p-4 space-y-2"><div v-for="i in 3" :key="i" class="skeleton h-12"></div></div>
    <div v-else-if="!data.data.length" class="card px-6 py-12 text-center">
      <div class="mx-auto h-14 w-14 rounded-full bg-brand-50 text-brand-600 grid place-items-center"><Icon name="box" :size="26" /></div>
      <h2 class="font-bold mt-4">No orders yet</h2>
      <p class="text-sm text-muted mt-1">When you place an order it shows up here, so you can follow it to your door.</p>
      <RouterLink to="/" class="btn-primary mt-5">Start shopping</RouterLink>
    </div>
    <ul v-else class="card divide-y divide-line">
      <li v-for="o in data.data" :key="o.number">
        <RouterLink :to="`/account/orders/${o.number}`" class="flex items-center justify-between gap-3 p-3 hover:bg-gray-50">
          <div>
            <div class="font-semibold">{{ o.number }}</div>
            <div class="text-xs text-muted">{{ new Date(o.created_at).toLocaleString() }} · {{ o.channel === 'pos' ? 'In store' : 'Online' }}</div>
          </div>
          <div class="text-right">
            <div class="font-bold">{{ money(o.total) }}</div>
            <span class="badge" :class="statusClass(o)">{{ label(o) }}</span>
          </div>
        </RouterLink>
      </li>
    </ul>
    <Pager v-if="data" :page="data.current_page" :last="data.last_page" @go="load" />
  </div>
</template>

<script setup lang="ts">
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import Icon from '@/components/Icon.vue'
import Pager from '@/components/Pager.vue'
import { get } from '@/lib/api'
import { money } from '@/lib/money'
import { logout } from '@/stores/auth'

const router = useRouter()
const data = ref<any>(null)
const load = (page = 1) => get('/my/orders', { page }).then((r) => (data.value = r))
load()

const label = (o: any) => (o.status === 'cancelled' ? 'Cancelled' : o.status === 'completed' ? 'Completed' : (o.fulfillment_status ?? '').replace(/_/g, ' '))
const statusClass = (o: any) => (o.status === 'cancelled' ? 'bg-red-50 text-bad' : o.status === 'completed' ? 'bg-green-50 text-ok' : 'bg-brand-50 text-brand-700')

async function signOut() {
  await logout()
  router.replace('/')
}
</script>
