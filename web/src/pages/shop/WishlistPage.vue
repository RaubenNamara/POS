<template>
  <div class="shell py-4">
    <h1 class="text-xl font-bold mb-3">Wishlist ({{ wishlist.items.length }})</h1>
    <div v-if="!wishlist.items.length" class="bg-white rounded-xl p-10 text-center">
      <p class="text-muted">Nothing saved yet. Tap the heart on a product to keep it here.</p>
      <RouterLink to="/" class="btn-primary mt-3">Start shopping</RouterLink>
    </div>
    <ul v-else class="bg-white rounded-xl divide-y divide-line">
      <li v-for="p in wishlist.items" :key="p.id" class="flex gap-3 p-3 items-center">
        <RouterLink :to="`/p/${p.slug}`" class="w-20 shrink-0"><ProductImage :src="p.image" :alt="p.name" /></RouterLink>
        <div class="flex-1 min-w-0">
          <RouterLink :to="`/p/${p.slug}`" class="text-sm line-clamp-2 hover:text-brand-700">{{ p.name }}</RouterLink>
          <div class="font-bold mt-1">{{ money(p.price) }}</div>
          <p class="text-xs text-muted">Price when saved. The product page shows today's price and stock.</p>
        </div>
        <div class="flex flex-col sm:flex-row gap-2">
          <RouterLink :to="`/p/${p.slug}`" class="btn-primary btn-sm">View</RouterLink>
          <button class="btn-outline btn-sm text-bad" @click="toggleSaved(p)">Remove</button>
        </div>
      </li>
    </ul>
    <p class="text-xs text-muted mt-2">Your wishlist is saved on this device.</p>
  </div>
</template>

<script setup lang="ts">
import ProductImage from '@/components/ProductImage.vue'
import { money } from '@/lib/money'
import { toggleSaved, wishlist } from '@/stores/wishlist'
</script>
