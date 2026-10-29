<template>
  <Modal :open="true" :title="receipt.provisional ? 'Provisional receipt (offline)' : 'Sale complete'" @close="$emit('close')">
    <div class="flex gap-2 mb-3">
      <button class="btn-outline btn-sm" :class="{ 'border-brand-600 text-brand-700': format === 'thermal' }" @click="format = 'thermal'">Thermal 80mm</button>
      <button class="btn-outline btn-sm" :class="{ 'border-brand-600 text-brand-700': format === 'a4' }" @click="format = 'a4'">A4 invoice</button>
    </div>
    <div class="border border-line rounded-md overflow-auto max-h-[55vh] bg-white">
      <div id="print-area"><Receipt :r="receipt" :format="format" /></div>
    </div>
    <template #footer>
      <a v-if="!receipt.provisional" :href="`/api/admin/sales/${receipt.id}/invoice.pdf`" class="btn-outline" target="_blank" rel="noopener"><Icon name="download" :size="16" /> PDF</a>
      <button class="btn-outline" @click="print"><Icon name="printer" :size="16" /> Print</button>
      <button ref="nextBtn" class="btn-primary" @click="$emit('close')">New sale (Enter)</button>
    </template>
  </Modal>
</template>

<script setup lang="ts">
import { onMounted, ref } from 'vue'
import Icon from '@/components/Icon.vue'
import Modal from '@/components/Modal.vue'
import Receipt from '@/components/Receipt.vue'
import { printElement } from '@/lib/print'

defineProps<{ receipt: any }>()
defineEmits<{ close: [] }>()
const format = ref<'thermal' | 'a4'>('thermal')
const nextBtn = ref<HTMLButtonElement>()

const print = () => printElement('print-area', format.value)
onMounted(() => nextBtn.value?.focus())
</script>
