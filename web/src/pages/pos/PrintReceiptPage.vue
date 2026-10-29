<template>
  <div class="min-h-screen bg-white py-4">
    <div class="no-print flex gap-2 justify-center mb-4">
      <button class="btn-outline btn-sm" @click="format = 'thermal'">Thermal</button>
      <button class="btn-outline btn-sm" @click="format = 'a4'">A4</button>
      <button class="btn-primary btn-sm" @click="printElement('print-area', format)">Print</button>
      <a v-if="r" :href="`/api/admin/sales/${r.id}/invoice.pdf`" class="btn-outline btn-sm">PDF</a>
    </div>
    <p v-if="error" class="text-center text-bad">{{ error }}</p>
    <div id="print-area"><Receipt v-if="r" :r="r" :format="format" /></div>
  </div>
</template>

<script setup lang="ts">
import { ref } from 'vue'
import { useRoute } from 'vue-router'
import Receipt from '@/components/Receipt.vue'
import { errorText, get } from '@/lib/api'
import { configureMoney } from '@/lib/money'
import { printElement } from '@/lib/print'

const route = useRoute()
const r = ref<any>(null)
const error = ref('')
const format = ref<'thermal' | 'a4'>(route.query.format === 'a4' ? 'a4' : 'thermal')
get(`/admin/sales/${route.params.id}/receipt`)
  .then((x) => {
    configureMoney(x.shop.currency, x.shop.currency_decimals)
    r.value = x
    if (route.query.auto === '1') setTimeout(() => printElement('print-area', format.value), 100)
  })
  .catch((e) => (error.value = errorText(e)))
</script>
