<template>
  <div>
    <!-- Banner (solid, no gradient) -->
    <section class="bg-brand-100">
      <div class="shell pt-5 pb-16 sm:pt-7 sm:pb-20">
        <p class="text-sm font-semibold text-ink/70">Help Center</p>
        <h1 class="text-xl sm:text-2xl font-extrabold text-ink">Hi{{ auth.user ? `, ${auth.user.name.split(' ')[0]}` : '' }}, how can we help you?</h1>
      </div>
    </section>

    <div class="shell -mt-12 sm:-mt-14 pb-6">
      <!-- Quick actions -->
      <div class="grid grid-flow-col auto-cols-[70%] min-[480px]:auto-cols-[45%] md:grid-flow-row md:grid-cols-5 gap-3 overflow-x-auto pb-1 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">
        <RouterLink v-for="q in quick" :key="q.slug" :to="`/help/${q.slug}`" class="quick" :class="{ on: topic === q.slug }">
          <span class="font-semibold text-[15px] leading-snug">{{ q.label }}</span>
          <span class="quick-icon"><Icon :name="q.icon" :size="26" /></span>
        </RouterLink>
      </div>

      <!-- Topic search -->
      <label class="relative block mt-4 md:max-w-md">
        <span class="sr-only">Search help topics</span>
        <Icon name="search" :size="18" class="absolute left-3 top-1/2 -translate-y-1/2 text-muted pointer-events-none" />
        <input v-model="q" type="search" class="input h-11 pl-10" placeholder='Type keywords like "return"' />
      </label>

      <div class="mt-4 grid md:grid-cols-[17rem_1fr] gap-4 items-start">
        <!-- Topics (on phones: shown until a topic is opened) -->
        <aside class="bg-white rounded-lg border border-line overflow-hidden md:sticky md:top-36" :class="{ 'hidden md:block': topic && !q }">
          <template v-if="q">
            <p class="px-4 py-3 text-xs font-semibold text-muted border-b border-line">{{ results.length }} result(s) for “{{ q }}”</p>
            <RouterLink v-for="a in results" :key="a.slug" :to="`/help/${a.slug}`" class="topic" @click="q = ''">
              <Icon :name="groupOf(a).icon" :size="20" class="text-muted shrink-0" /> <span class="flex-1">{{ a.title }}</span> <Icon name="chevron" :size="16" class="text-brand-600" />
            </RouterLink>
            <p v-if="!results.length" class="px-4 py-6 text-sm text-muted">No topic matches. Try “pay”, “delivery” or “return”, or contact us below.</p>
          </template>
          <template v-else>
            <div v-for="g in groups" :key="g.key" class="border-b border-line last:border-0">
              <button class="topic w-full" :aria-expanded="openGroup === g.key" @click="openGroup = openGroup === g.key ? '' : g.key">
                <Icon :name="g.icon" :size="20" class="text-ink shrink-0" />
                <span class="flex-1 text-left font-semibold">{{ g.title }}</span>
                <Icon name="chevron" :size="16" class="text-brand-600 transition-transform" :class="{ 'rotate-90': openGroup === g.key }" />
              </button>
              <div v-if="openGroup === g.key" class="pb-2">
                <RouterLink v-for="a in articles.filter((x) => x.group === g.key)" :key="a.slug" :to="`/help/${a.slug}`" class="block pl-12 pr-4 py-2 text-sm hover:text-brand-700" :class="{ 'text-brand-700 font-semibold': topic === a.slug }">{{ a.title }}</RouterLink>
              </div>
            </div>
          </template>
        </aside>

        <!-- Article -->
        <article v-if="current" class="bg-white rounded-lg border border-line p-4 sm:p-6 text-[15px] leading-relaxed" :class="{ 'hidden md:block': !topic }">
          <RouterLink to="/help" class="md:hidden inline-flex items-center gap-1 text-sm text-brand-700 font-semibold mb-3"><Icon name="left" :size="16" /> All topics</RouterLink>
          <h2 class="text-xl sm:text-2xl font-extrabold mb-4">{{ current.title }}</h2>

          <!-- Place an order -->
          <template v-if="current.slug === 'place-order'">
            <ol class="steps">
              <li><b>Find your product.</b> Use the search bar or the categories at the top.</li>
              <li><b>Add it to your cart.</b> Open the product and press <b>Add to cart</b>, or on a computer hover over a product and click the <b>+ cart</b> button.</li>
              <li><b>Open the cart and press Checkout.</b> Sign in or create an account with a password or with Google.</li>
              <li><b>Choose delivery or pick-up.</b> For delivery, set your location on the map and add directions for the rider.</li>
              <li><b>Choose how to pay and place the order.</b> You get an order number straight away; follow it under <RouterLink :to="ordersLink" class="a">Account → Orders</RouterLink>.</li>
            </ol>
          </template>

          <!-- Pay -->
          <template v-else-if="current.slug === 'pay'">
            <p class="text-center text-muted mb-3">Your different means of payment</p>
            <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-5">
              <div v-for="m in payMethods" :key="m.name" class="rounded-lg border border-line p-3 text-center" :class="{ 'opacity-50': !m.on }">
                <div class="text-xs font-semibold mb-2">{{ m.name }}</div>
                <div class="h-12 w-12 mx-auto rounded-full grid place-items-center" :style="{ background: m.bg, color: m.fg }"><Icon :name="m.icon" :size="22" /></div>
                <div class="text-xs mt-2 text-muted">{{ m.on ? m.note : 'Not available yet' }}</div>
              </div>
            </div>
            <h3 class="h3">Option 1: Pay on Delivery</h3>
            <p v-if="settings.cod_enabled !== '0'">
              Pay with cash or mobile money when the rider brings your order. No payment details needed beforehand.
              Available for deliveries in <b>{{ districts }}</b>. The system checks your delivery location, so this option only appears when your pin is inside that area.
              You can also choose <b>Pay when you collect</b> if you pick up at the shop.
            </p>
            <p v-else>Pay on delivery is currently switched off by the shop.</p>
            <h3 class="h3">Option 2: Pay Now Online</h3>
            <p v-if="settings.online_payments === '1'">
              Pay securely when you order with <b>MTN Mobile Money, Airtel Money, Visa or Mastercard</b> through Flutterwave. Available everywhere, including outside {{ districts }}.
              Your items are reserved while you pay; the order is confirmed only after Flutterwave confirms the payment.
            </p>
            <p v-else>Online payment is being set up and is not available yet.</p>
            <h3 class="h3">How to pay, step by step</h3>
            <ol class="steps">
              <li>Add items to your cart and press <b>Checkout</b>.</li>
              <li>Set your delivery location (or choose pick-up). The payment options you can use appear under <b>Payment</b>.</li>
              <li>Pick <b>Pay on delivery</b> or <b>Pay now</b>. For Pay now you are taken to Flutterwave to approve the payment on your phone or card, then brought back to your order.</li>
            </ol>
            <p class="note">An order shows as <b>Paid</b> only after the shop or the payment provider has confirmed the money.</p>
          </template>

          <!-- Track -->
          <template v-else-if="current.slug === 'track'">
            <p>Go to <RouterLink :to="ordersLink" class="a">Account → Orders</RouterLink> and open your order. The tracker shows where it is:</p>
            <ul class="status-list">
              <li><b>Awaiting payment</b>: waiting for your online payment (items are held for a limited time).</li>
              <li><b>Placed</b>: we have your order.</li>
              <li><b>Confirmed</b>: we called you and confirmed it.</li>
              <li><b>Processing / Ready</b>: being packed, or ready for pick-up.</li>
              <li><b>Out for delivery</b>: the rider is on the way.</li>
              <li><b>Delivered</b>: done.</li>
            </ul>
            <p class="note">The order page refreshes itself while it is open.</p>
          </template>

          <!-- Cancel -->
          <template v-else-if="current.slug === 'cancel'">
            <ol class="steps">
              <li>Open the order under <RouterLink :to="ordersLink" class="a">Account → Orders</RouterLink>.</li>
              <li>Press <b>Cancel order</b>. The button is there while the order is still <b>Placed</b> or <b>Awaiting payment</b> and nothing has been paid.</li>
            </ol>
            <p class="note">Once the shop has confirmed your order, or if you have already paid, please contact us to cancel. Unpaid online-payment orders are cancelled automatically when the payment time runs out.</p>
          </template>

          <!-- Returns -->
          <template v-else-if="current.slug === 'returns'">
            <p>{{ settings.return_policy }}</p>
            <h3 class="h3">How to return an item</h3>
            <ol class="steps">
              <li>Bring the item, with its packaging, to the shop{{ settings.shop_address ? ` (${settings.shop_address})` : '' }}.</li>
              <li>Show your receipt or order number.</li>
              <li>We check the item and process the return.</li>
            </ol>
            <h3 class="h3">Refunds</h3>
            <p>Refunds are paid in cash or by mobile money. If you still owed money on the order, that balance is reduced first and only the rest is paid back.</p>
          </template>

          <!-- Delivery -->
          <template v-else-if="current.slug === 'delivery'">
            <p>Delivery fee: <b>{{ fee ? money(settings.delivery_fee) : 'free' }}</b> per order.</p>
            <p class="mt-2">After you order we call the phone number you gave at checkout to agree the delivery time. Make sure your map pin and directions are correct, you can update them at checkout or under <RouterLink :to="locationLink" class="a">My Account</RouterLink>.</p>
            <p class="mt-2">Prefer to collect? Choose <b>Pick up</b> at checkout and collect from the shop{{ settings.shop_address ? ` at ${settings.shop_address}` : '' }}.</p>
          </template>

          <!-- Products -->
          <template v-else-if="current.slug === 'products'">
            <p>Every product page shows the live price and stock. “Only N left” means exactly that: stock is updated the moment anything is sold in the shop or online.</p>
            <p class="mt-2"><b>Packages</b> bundle several products at a lower price than buying them one by one; a package is available only while all its items are in stock.</p>
            <p class="mt-2">Tap the heart on a product to save it to your <RouterLink to="/account/wishlist" class="a">Wishlist</RouterLink>.</p>
          </template>

          <!-- Account -->
          <template v-else-if="current.slug === 'account'">
            <ul class="status-list">
              <li><b>Create an account</b> with your email and a password, or with <b>Continue with Google</b>.</li>
              <li><b>Your location</b> is asked once after signing up; it decides delivery and payment options. Change it under <RouterLink :to="locationLink" class="a">My Account</RouterLink>.</li>
              <li><b>Your orders</b> are under <RouterLink :to="ordersLink" class="a">Account → Orders</RouterLink>.</li>
            </ul>
          </template>

          <!-- Warranty -->
          <template v-else-if="current.slug === 'warranty'">
            <p>The warranty for each product is listed in its <b>Specifications</b> on the product page.</p>
            <p class="mt-2">For a warranty claim, bring the product and your receipt or order number to the shop. It is your proof of purchase.</p>
          </template>

          <!-- Contact (every article) -->
          <div class="mt-6 pt-4 border-t border-line">
            <p class="font-semibold mb-2">Still need help?</p>
            <div class="flex flex-wrap gap-2">
              <a v-if="settings.shop_phone" :href="`tel:${settings.shop_phone.replace(/\s+/g, '')}`" class="btn-primary"><Icon name="phone" :size="18" /> Call {{ settings.shop_phone }}</a>
              <a v-if="settings.shop_whatsapp" :href="`https://wa.me/${settings.shop_whatsapp}`" target="_blank" rel="noopener" class="btn border-2 border-[#25d366] text-[#128c4b] hover:bg-green-50"><Icon name="whatsapp" :size="18" /> WhatsApp</a>
              <a v-if="settings.shop_email" :href="`mailto:${settings.shop_email}`" class="btn-outline">{{ settings.shop_email }}</a>
              <span v-if="!settings.shop_phone && !settings.shop_whatsapp && !settings.shop_email" class="text-sm text-muted">Visit us at the shop{{ settings.shop_address ? `: ${settings.shop_address}` : '' }}.</span>
            </div>
          </div>
        </article>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import Icon from '@/components/Icon.vue'
import { money } from '@/lib/money'
import { auth } from '@/stores/auth'
import { loadHome, shop } from '@/stores/shop'

const route = useRoute()
loadHome().catch(() => {})
const settings = computed(() => shop.settings)
const districts = computed(() => shop.settings.cod_districts || 'Kampala, Wakiso')
const fee = computed(() => Number(shop.settings.delivery_fee || 0) > 0)
const ordersLink = computed(() => (auth.user ? '/account/orders' : '/login?next=/account/orders'))
const locationLink = computed(() => (auth.user ? '/account/location' : '/login?next=/account/location'))

const groups = [
  { key: 'payments', title: 'Payments', icon: 'wallet' },
  { key: 'delivery', title: 'Delivery', icon: 'truck' },
  { key: 'orders', title: 'Orders', icon: 'receipt' },
  { key: 'returns', title: 'Returns & Refunds', icon: 'refresh' },
  { key: 'products', title: 'Products', icon: 'box' },
  { key: 'account', title: 'Account', icon: 'user' },
  { key: 'warranty', title: 'Warranty', icon: 'shield' },
]
const articles = [
  { slug: 'pay', group: 'payments', title: 'How to pay for your order', keys: 'pay payment mobile money momo airtel card visa mastercard cash delivery flutterwave online' },
  { slug: 'delivery', group: 'delivery', title: 'Delivery fees, areas and pick-up', keys: 'delivery fee rider location map pickup collect kampala wakiso time' },
  { slug: 'place-order', group: 'orders', title: 'How to place an order', keys: 'order buy checkout cart place' },
  { slug: 'track', group: 'orders', title: 'Track your order', keys: 'track status where order delivered' },
  { slug: 'cancel', group: 'orders', title: 'Cancel an order', keys: 'cancel order stop' },
  { slug: 'returns', group: 'returns', title: 'Returns and refunds', keys: 'return refund exchange money back damaged' },
  { slug: 'products', group: 'products', title: 'Stock, packages and wishlist', keys: 'product stock package bundle wishlist available' },
  { slug: 'account', group: 'account', title: 'Your account', keys: 'account sign in google password location register' },
  { slug: 'warranty', group: 'warranty', title: 'Warranty', keys: 'warranty guarantee repair broken claim' },
]
const quick = [
  { slug: 'place-order', label: 'Place an Order', icon: 'cart' },
  { slug: 'pay', label: 'Pay for Your Order', icon: 'wallet' },
  { slug: 'track', label: 'Track Your Order', icon: 'truck' },
  { slug: 'cancel', label: 'Cancel an Order', icon: 'x' },
  { slug: 'returns', label: 'Return an Item', icon: 'refresh' },
]
const groupOf = (a: { group: string }) => groups.find((g) => g.key === a.group)!

const q = ref('')
const results = computed(() => {
  const words = q.value.toLowerCase().split(/\s+/).filter(Boolean)
  return articles.filter((a) => words.every((w) => `${a.title} ${a.keys}`.toLowerCase().includes(w)))
})

const topic = computed(() => (route.params.topic ? String(route.params.topic) : ''))
// Desktop shows an article even on /help; phones show the topic list first.
const current = computed(() => articles.find((a) => a.slug === (topic.value || 'place-order')))
const openGroup = ref('orders')
watch(current, (a) => a && (openGroup.value = a.group), { immediate: true })

const payMethods = computed(() => {
  const online = shop.settings.online_payments === '1'
  const cod = shop.settings.cod_enabled !== '0'
  return [
    { name: 'MTN MoMo', icon: 'phone', bg: '#ffcb05', fg: '#1f2937', on: online, note: 'Approve on your phone' },
    { name: 'Airtel Money', icon: 'phone', bg: '#fee2e2', fg: '#b91c1c', on: online, note: 'Approve on your phone' },
    { name: 'Mastercard & Visa', icon: 'wallet', bg: '#efe4db', fg: '#5a3e2b', on: online, note: 'Safe card payment' },
    { name: 'Pay on Delivery', icon: 'truck', bg: '#efe4db', fg: '#5a3e2b', on: cod, note: `In ${districts.value}` },
  ]
})
</script>

<style scoped>
.quick {
  position: relative; display: flex; align-items: center; justify-content: space-between; gap: 0.5rem;
  min-height: 5.5rem; padding: 1rem; background: #fff; border-radius: 0.5rem;
  border-top: 3px solid transparent; box-shadow: 0 1px 3px rgb(0 0 0 / 0.08);
}
.quick:hover, .quick.on { border-top-color: var(--color-brand-500); }
.quick-icon { height: 3.25rem; width: 3.25rem; flex-shrink: 0; border-radius: 9999px; display: grid; place-items: center; background: var(--color-brand-50); color: var(--color-brand-600); }
.topic { display: flex; align-items: center; gap: 0.75rem; padding: 0.875rem 1rem; font-size: 0.9375rem; }
.topic:hover { background: #fafafa; }
.steps { list-style: decimal; padding-left: 1.25rem; }
.steps li { padding: 0.3rem 0; }
.status-list { list-style: disc; padding-left: 1.25rem; }
.status-list li { padding: 0.25rem 0; }
.h3 { font-weight: 800; margin: 1.25rem 0 0.25rem; }
.note { margin-top: 1rem; padding: 0.75rem; border-radius: 0.375rem; background: var(--color-brand-50); font-size: 0.875rem; }
.a { color: var(--color-brand-700); font-weight: 600; }
</style>
