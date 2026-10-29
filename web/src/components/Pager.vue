<template>
  <nav v-if="last > 1" class="flex items-center justify-center gap-1 py-4" aria-label="Pagination">
    <button class="btn-outline btn-sm" :disabled="page <= 1" @click="$emit('go', page - 1)">Prev</button>
    <template v-for="n in pages" :key="n">
      <span v-if="n === 0" class="px-1 text-muted">…</span>
      <button v-else class="btn-sm btn" :class="n === page ? 'bg-brand-600 text-white' : 'btn-outline'" @click="$emit('go', n)">{{ n }}</button>
    </template>
    <button class="btn-outline btn-sm" :disabled="page >= last" @click="$emit('go', page + 1)">Next</button>
  </nav>
</template>

<script setup lang="ts">
import { computed } from 'vue'

const props = defineProps<{ page: number; last: number }>()
defineEmits<{ go: [n: number] }>()

// 1 … 4 5 [6] 7 8 … 20   (0 = gap)
const pages = computed(() => {
  const { page, last } = props
  const set = new Set([1, last, page - 1, page, page + 1, page - 2, page + 2].filter((n) => n >= 1 && n <= last))
  const sorted = [...set].sort((a, b) => a - b)
  const out: number[] = []
  sorted.forEach((n, i) => {
    if (i && n - sorted[i - 1] > 1) out.push(0)
    out.push(n)
  })
  return out
})
</script>
