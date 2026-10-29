<template>
  <div class="relative">
    <input
      v-model="q"
      class="input"
      :placeholder="placeholder ?? 'Search or scan product to add'"
      autocomplete="off"
      @input="find"
      @keydown.enter.prevent="enter"
      @keydown.esc="list = []"
    />
    <ul v-if="list.length" class="absolute z-30 mt-1 w-full bg-white border border-line rounded-md shadow-lg max-h-72 overflow-y-auto">
      <li v-for="p in list" :key="p.id">
        <button type="button" class="w-full text-left px-3 py-2 hover:bg-brand-50 text-sm flex justify-between gap-2" @click="choose(p)">
          <span>{{ p.name }} <span class="text-muted text-xs">{{ p.sku }}</span></span>
          <span class="text-xs text-muted whitespace-nowrap">stock {{ p.total_stock }}</span>
        </button>
      </li>
    </ul>
  </div>
</template>

<script setup lang="ts">
import { ref } from 'vue'
import { get } from '@/lib/api'

const props = defineProps<{ placeholder?: string; type?: 'standard' | 'bundle' }>()
const emit = defineEmits<{ pick: [p: any] }>()
const q = ref('')
const list = ref<any[]>([])
let t: number | undefined

function find() {
  clearTimeout(t)
  if (!q.value.trim()) return (list.value = [])
  t = window.setTimeout(async () => {
    const r = await get('/admin/products', { q: q.value, per_page: 12, type: props.type })
    list.value = r.data
  }, 200)
}

async function enter() {
  // Scanner: exact barcode/SKU goes straight in.
  try {
    const p = await get('/admin/products/lookup', { code: q.value })
    if (!props.type || p.type === props.type) return choose(p)
  } catch { /* not an exact code */ }
  if (list.value.length === 1) choose(list.value[0])
}

function choose(p: any) {
  emit('pick', p)
  q.value = ''
  list.value = []
}
</script>
