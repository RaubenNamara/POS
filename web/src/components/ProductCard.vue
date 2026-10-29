<template>
  <article class="group relative bg-white rounded-lg overflow-hidden flex flex-col transition-shadow hover:shadow-lg">
    <div class="relative">
    <RouterLink :to="`/p/${p.slug}`" class="block relative" :aria-label="p.name">
      <ProductImage :src="p.image" :alt="p.name" :eager="eager" />
      <span v-if="p.type === 'bundle'" class="absolute top-2 left-2 badge bg-ink text-white">Package</span>
      <!-- Real stock only: shown when few are left. -->
      <span v-if="p.in_stock && p.available <= 10" class="absolute inset-x-0 bottom-0 bg-amber-100/95 text-warn text-[11px] font-semibold text-center py-0.5">
        {{ p.available }} {{ p.available === 1 ? 'item' : 'items' }} left
      </span>
      <span v-else-if="!p.in_stock" class="absolute inset-x-0 bottom-0 bg-gray-800/80 text-white text-[11px] font-semibold text-center py-0.5">Out of stock</span>
    </RouterLink>
    </div>
    <RouterLink :to="`/p/${p.slug}`" class="p-2.5 flex flex-col flex-1">
      <span class="text-sm leading-snug line-clamp-2 min-h-[2.5rem] group-hover:text-brand-700">{{ p.name }}</span>
      <span class="mt-1.5 font-bold text-base">{{ money(p.price) }}</span>
      <span class="mt-0.5 flex items-center gap-1.5 min-h-5">
        <template v-if="off">
          <span class="text-xs text-muted line-through">{{ money(p.compare_at_price) }}</span>
          <span class="rounded bg-green-700 text-white text-[11px] font-bold px-1.5 py-px">-{{ off }}%</span>
        </template>
      </span>
    </RouterLink>
    <!-- Quick add: bottom-right on the price line, shown on hover / keyboard focus on computers (touch devices use the product page). -->
    <button
      v-if="p.in_stock"
      class="quick-add absolute right-2.5 bottom-2.5 h-8 px-2.5 rounded-full bg-brand-600 hover:bg-brand-700 text-white items-center justify-center gap-0.5 shadow-lg"
      :aria-label="`Add ${p.name} to cart`"
      title="Add to cart"
      @click="add"
    >
      <Icon name="plus" :size="13" /><Icon name="cart" :size="15" />
    </button>
  </article>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import Icon from './Icon.vue'
import ProductImage from './ProductImage.vue'
import { toast } from '@/lib/toast'
import { addToCart } from '@/stores/cart'
import { money, pctOff } from '@/lib/money'
import type { ProductCard } from '@/stores/shop'

const props = defineProps<{ p: ProductCard; eager?: boolean }>()
const off = computed(() => pctOff(props.p.price, props.p.compare_at_price))

function add() {
  addToCart(props.p)
  toast(`Added to cart: ${props.p.name}`)
}
</script>

<style scoped>
/* Sits over the bottom of the photo; hidden until the card is hovered or focused. */
.quick-add {
  display: none;
  opacity: 0;
  transform: translateY(0.375rem);
  transition: opacity 0.15s ease, transform 0.15s ease;
  pointer-events: none;
}
@media (hover: hover) {
  .quick-add { display: flex; }
  article:hover .quick-add,
  article:focus-within .quick-add { opacity: 1; transform: none; pointer-events: auto; }
}
@media (prefers-reduced-motion: reduce) {
  .quick-add { transition: none; }
}
</style>
