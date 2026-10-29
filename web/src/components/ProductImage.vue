<template>
  <!-- Fixed aspect box reserves space before the image loads, so nothing shifts (CLS). -->
  <div class="relative w-full overflow-hidden bg-gray-100" :style="{ aspectRatio: ratio }">
    <img
      v-if="src && !failed"
      :src="img(src)"
      :alt="alt"
      :loading="eager ? 'eager' : 'lazy'"
      :fetchpriority="eager ? 'high' : 'auto'"
      decoding="async"
      class="absolute inset-0 h-full w-full object-contain"
      @error="failed = true"
    />
    <div v-else class="absolute inset-0 flex items-center justify-center text-gray-300">
      <svg width="40%" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
        <path d="M21 8 12 3 3 8v8l9 5 9-5zM3 8l9 5 9-5M12 13v8" />
      </svg>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref } from 'vue'
import { img } from '@/lib/api'

withDefaults(defineProps<{ src: string | null | undefined; alt: string; eager?: boolean; ratio?: string }>(), { ratio: '1 / 1' })
const failed = ref(false)
</script>
