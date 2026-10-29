<template>
  <Teleport to="body">
    <div v-if="open" class="fixed inset-0 z-50 flex items-end sm:items-center justify-center bg-gray-900/45 backdrop-blur-[2px] p-0 sm:p-4" @mousedown.self="$emit('close')">
      <div
        class="bg-white w-full rounded-t-2xl sm:rounded-2xl shadow-2xl ring-1 ring-black/5 max-h-[92vh] overflow-auto"
        :class="wide ? 'sm:max-w-3xl' : 'sm:max-w-lg'"
        role="dialog"
        aria-modal="true"
        @keydown.esc="$emit('close')"
      >
        <header class="flex items-center justify-between gap-3 px-5 py-4 border-b border-line sticky top-0 bg-white/95 backdrop-blur z-10">
          <h2 class="text-base font-bold">{{ title }}</h2>
          <button class="h-8 w-8 -mr-1 rounded-full grid place-items-center text-muted hover:bg-gray-100 hover:text-ink" aria-label="Close" @click="$emit('close')"><Icon name="x" :size="18" /></button>
        </header>
        <div class="p-5"><slot /></div>
        <footer v-if="$slots.footer" class="px-5 py-3.5 border-t border-line flex justify-end gap-2 sticky bottom-0 bg-gray-50/95 backdrop-blur"><slot name="footer" /></footer>
      </div>
    </div>
  </Teleport>
</template>

<script setup lang="ts">
import Icon from './Icon.vue'

defineProps<{ open: boolean; title: string; wide?: boolean }>()
defineEmits<{ close: [] }>()
</script>
