<template>
  <!-- A wide advert banner between home-page sections. -->
  <component :is="tag" v-bind="attrs" class="block relative overflow-hidden rounded-xl bg-gray-100 aspect-[2/1] sm:aspect-[5/1]" :aria-label="ad.title" @click="ad.link_url && countAdClick(ad.id)">
    <picture>
      <source v-if="ad.image_mobile_path" :srcset="img(ad.image_mobile_path)" media="(max-width: 639px)" />
      <img :src="img(ad.image_path)" :alt="ad.title" class="absolute inset-0 h-full w-full object-cover" loading="lazy" decoding="async" />
    </picture>
    <div v-if="ad.show_text" class="absolute inset-0 flex flex-col justify-center p-5 sm:p-8 max-w-lg text-white" style="text-shadow: 0 1px 6px rgb(0 0 0 / 0.55)">
      <p class="text-xl sm:text-3xl font-black leading-tight">{{ ad.title }}</p>
      <p v-if="ad.subtitle" class="mt-1 text-sm sm:text-base">{{ ad.subtitle }}</p>
    </div>
  </component>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { RouterLink } from 'vue-router'
import { img } from '@/lib/api'
import { countAdClick, isExternal, type Ad } from '@/lib/ads'

const props = defineProps<{ ad: Ad }>()
const tag = computed(() => (!props.ad.link_url ? 'div' : isExternal(props.ad.link_url) ? 'a' : RouterLink))
const attrs = computed(() => (!props.ad.link_url ? {} : isExternal(props.ad.link_url) ? { href: props.ad.link_url, target: '_blank', rel: 'noopener sponsored' } : { to: props.ad.link_url }))
</script>
