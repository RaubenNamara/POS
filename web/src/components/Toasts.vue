<template>
  <!-- Soft pop-up notices: top centre, light colours, close on their own. -->
  <div class="fixed top-3 sm:top-5 inset-x-0 z-[100] flex flex-col items-center gap-2 px-3 pointer-events-none" aria-live="polite">
    <TransitionGroup name="pop">
      <div
        v-for="t in toasts"
        :key="t.id"
        class="pointer-events-auto w-full max-w-sm flex items-start gap-3 rounded-xl border px-4 py-3 text-sm shadow-[0_10px_30px_rgb(0_0_0/0.10)]"
        :class="styles[t.kind].box"
        :role="t.kind === 'error' ? 'alert' : 'status'"
      >
        <span class="mt-0.5 h-6 w-6 shrink-0 rounded-full grid place-items-center" :class="styles[t.kind].icon">
          <Icon :name="t.kind === 'ok' ? 'check' : t.kind === 'error' ? 'alert' : 'help'" :size="14" />
        </span>
        <span class="flex-1 font-medium leading-snug pt-0.5">{{ t.text }}</span>
        <button class="shrink-0 -mr-1 h-6 w-6 grid place-items-center rounded opacity-60 hover:opacity-100" aria-label="Close" @click="close(t.id)">
          <Icon name="x" :size="14" />
        </button>
      </div>
    </TransitionGroup>
  </div>
</template>

<script setup lang="ts">
import Icon from './Icon.vue'
import { toasts } from '@/lib/toast'

const styles = {
  ok: { box: 'bg-green-50 border-green-200 text-green-900', icon: 'bg-green-100 text-green-700' },
  error: { box: 'bg-red-50 border-red-200 text-red-900', icon: 'bg-red-100 text-red-700' },
  info: { box: 'bg-white border-line text-ink', icon: 'bg-gray-100 text-gray-600' },
}

function close(id: number) {
  const i = toasts.findIndex((t) => t.id === id)
  if (i >= 0) toasts.splice(i, 1)
}
</script>

<style scoped>
.pop-enter-active { transition: opacity 0.18s ease, transform 0.18s cubic-bezier(0.2, 0.9, 0.3, 1.2); }
.pop-leave-active { transition: opacity 0.15s ease, transform 0.15s ease; }
.pop-enter-from { opacity: 0; transform: translateY(-8px) scale(0.96); }
.pop-leave-to { opacity: 0; transform: scale(0.96); }
@media (prefers-reduced-motion: reduce) {
  .pop-enter-active, .pop-leave-active { transition: none; }
}
</style>
