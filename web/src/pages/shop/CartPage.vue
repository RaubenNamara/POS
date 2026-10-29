<template>
  <div class="max-w-5xl 2xl:max-w-[max(80rem,70vw)] mx-auto px-3 sm:px-4 py-4">
    <h1 class="text-xl font-bold mb-3">Cart ({{ cartCount }})</h1>
    <div v-if="!cart.lines.length" class="card p-10 text-center">
      <p class="text-muted">Your cart is empty.</p>
      <RouterLink to="/" class="btn-primary mt-3">Start shopping</RouterLink>
    </div>
    <div v-else class="grid md:grid-cols-[1fr_19rem] gap-4">
      <ul class="card divide-y divide-line">
        <li v-for="l in cart.lines" :key="l.product_id" class="flex gap-3 p-3">
          <RouterLink :to="`/p/${l.slug}`" class="w-20 shrink-0"><ProductImage :src="l.image" :alt="l.name" /></RouterLink>
          <div class="flex-1 min-w-0">
            <RouterLink :to="`/p/${l.slug}`" class="text-sm line-clamp-2 hover:text-brand-700">{{ l.name }}</RouterLink>
            <div class="font-bold mt-1">{{ money(l.price) }}</div>
            <div class="flex items-center justify-between mt-2">
              <button class="text-bad text-xs font-semibold flex items-center gap-1" @click="removeFromCart(l.product_id)"><Icon name="trash" :size="14" /> Remove</button>
              <div class="flex items-center border border-line rounded-md">
                <button class="h-8 w-8 grid place-items-center" aria-label="Fewer" @click="setQty(l.product_id, l.quantity - 1)"><Icon name="minus" :size="14" /></button>
                <span class="w-8 text-center text-sm font-semibold">{{ l.quantity }}</span>
                <button class="h-8 w-8 grid place-items-center" aria-label="More" :disabled="l.quantity >= l.available" @click="setQty(l.product_id, l.quantity + 1)"><Icon name="plus" :size="14" /></button>
              </div>
            </div>
          </div>
        </li>
      </ul>
      <aside class="card p-4 h-max space-y-3">
        <h2 class="font-semibold text-sm uppercase text-muted">Summary</h2>
        <div class="flex justify-between"><span>Subtotal</span><b>{{ money(cartSubtotal) }}</b></div>
        <p class="text-xs text-muted">Delivery fee and final prices are confirmed at checkout.</p>
        <RouterLink to="/checkout" class="btn-primary w-full h-11">Checkout ({{ money(cartSubtotal) }})</RouterLink>
      </aside>
    </div>
  </div>
</template>

<script setup lang="ts">
import Icon from '@/components/Icon.vue'
import ProductImage from '@/components/ProductImage.vue'
import { money } from '@/lib/money'
import { cart, cartCount, cartSubtotal, removeFromCart, setQty } from '@/stores/cart'
</script>
