<template>
  <div class="space-y-2">
    <p class="text-sm font-semibold text-white">Get our app</p>
    <div class="flex flex-wrap gap-2">
      <!-- Always shown. With a store link saved in Settings they open the store;
           until then a tap installs the shop as an app from the browser. -->
      <component :is="appUrl ? 'a' : 'button'" v-bind="appUrl ? { href: appUrl, target: '_blank', rel: 'noopener' } : { type: 'button' }"
        class="badge-store" aria-label="Download on the App Store" @click="!appUrl && onInstall('ios')">
        <svg viewBox="0 0 24 24" width="24" height="24" fill="currentColor" aria-hidden="true">
          <path d="M16.4 12.6c0-2.6 2.1-3.8 2.2-3.9-1.2-1.8-3.1-2-3.7-2-1.6-.2-3.1.9-3.9.9-.8 0-2-.9-3.4-.9-1.7 0-3.3 1-4.2 2.6-1.8 3.1-.5 7.7 1.3 10.2.9 1.2 1.9 2.6 3.2 2.6 1.3-.1 1.8-.8 3.3-.8 1.6 0 2 .8 3.4.8 1.4 0 2.3-1.3 3.1-2.5 1-1.4 1.4-2.8 1.4-2.9 0 0-2.7-1-2.7-4.1zM13.9 4.9c.7-.9 1.2-2 1-3.2-1 0-2.3.7-3 1.6-.7.8-1.2 2-1.1 3.1 1.2.1 2.3-.6 3.1-1.5z" />
        </svg>
        <span class="leading-none text-left"><span class="block text-[10px]">Download on the</span><span class="block text-[17px] font-semibold mt-0.5">App Store</span></span>
      </component>
      <component :is="playUrl ? 'a' : 'button'" v-bind="playUrl ? { href: playUrl, target: '_blank', rel: 'noopener' } : { type: 'button' }"
        class="badge-store" aria-label="Get it on Google Play" @click="!playUrl && onInstall('android')">
        <svg viewBox="0 0 24 24" width="24" height="24" aria-hidden="true">
          <path d="M3.6 1.8 13.4 12 3.6 22.2c-.4-.2-.6-.6-.6-1.1V2.9c0-.5.2-.9.6-1.1z" fill="#00d7fe" />
          <path d="m16.8 8.6-3.4 3.4-9.8-10.2c.2-.1.5-.1.8 0z" fill="#00f076" />
          <path d="m16.8 15.4-12.4 6.8c-.3.1-.6.1-.8 0l9.8-10.2z" fill="#f73448" />
          <path d="m20.6 10.7-3.8-2.1-3.4 3.4 3.4 3.4 3.8-2.1c.9-.5.9-1.9 0-2.6z" fill="#ffc900" />
        </svg>
        <span class="leading-none text-left"><span class="block text-[10px] uppercase">Get it on</span><span class="block text-[17px] font-semibold mt-0.5">Google Play</span></span>
      </component>
    </div>

    <Modal :open="help !== ''" :title="help === 'ios' ? 'Install on iPhone or iPad' : 'Install the app'" @close="help = ''">
      <template v-if="help === 'ios'">
        <p class="text-sm mb-3">Our App Store app is coming soon. You can install the shop on your iPhone now:</p>
        <ol class="list-decimal pl-5 space-y-2 text-sm">
          <li>Open this shop in <b>Safari</b>.</li>
          <li>Tap the <b>Share</b> button (the square with an arrow pointing up).</li>
          <li>Scroll down and tap <b>Add to Home Screen</b>, then <b>Add</b>.</li>
        </ol>
      </template>
      <template v-else>
        <p class="text-sm mb-3">Our Google Play app is coming soon. You can install the shop on this device now:</p>
        <ol class="list-decimal pl-5 space-y-2 text-sm">
          <li>Open this shop in <b>Chrome</b>.</li>
          <li>Tap the menu (three dots) at the top right.</li>
          <li>Tap <b>Install app</b> or <b>Add to Home screen</b>.</li>
        </ol>
      </template>
      <p class="text-sm text-muted mt-3">The shop then opens from its own icon, full screen, like an app.</p>
    </Modal>
  </div>
</template>

<script setup lang="ts">
import { computed, ref } from 'vue'
import Modal from './Modal.vue'
import { install, promptInstall } from '@/lib/install'
import { toast } from '@/lib/toast'
import { shop } from '@/stores/shop'

const playUrl = computed(() => shop.settings.play_store_url || '')
const appUrl = computed(() => shop.settings.app_store_url || '')
const help = ref<'' | 'ios' | 'android'>('')

async function onInstall(store: 'ios' | 'android') {
  if (install.installed) {
    toast('The app is already installed on this device.')
    return
  }
  // Chrome/Edge can show the real install dialog; otherwise explain the steps.
  if (install.canPrompt) {
    if (await promptInstall()) toast('App installed. Open it from your home screen or desktop.')
    return
  }
  help.value = install.isIos ? 'ios' : store
}
</script>

<style scoped>
.badge-store {
  display: inline-flex; align-items: center; gap: 0.5rem; height: 2.75rem; padding: 0 0.875rem 0 0.75rem;
  background: #000; color: #fff; border: 1px solid #a6a6a6; border-radius: 0.5rem; cursor: pointer;
}
.badge-store:hover { border-color: #fff; }
</style>
