<template>
  <div v-if="s" class="grid lg:grid-cols-2 gap-4 max-w-6xl 2xl:max-w-[100rem]">
    <form class="card p-4 space-y-3" @submit.prevent="save">
      <h3 class="font-semibold">Shop & receipts</h3>
      <div><label class="label">Shop name</label><input v-model="s.shop_name" class="input" required /></div>
      <div><label class="label">Address</label><input v-model="s.shop_address" class="input" /></div>
      <div class="grid grid-cols-2 gap-3">
        <div><label class="label">Phone</label><input v-model="s.shop_phone" class="input" /></div>
        <div><label class="label">Email</label><input v-model="s.shop_email" type="email" class="input" /></div>
      </div>
      <div><label class="label">WhatsApp number (international, digits only, e.g. 256772123456)</label><input v-model="s.shop_whatsapp" class="input" inputmode="numeric" pattern="\d{9,15}" /></div>
      <div class="grid sm:grid-cols-2 gap-3">
        <div><label class="label">Android app link (Google Play)</label><input v-model="s.play_store_url" class="input" placeholder="https://play.google.com/store/apps/details?id=…" /></div>
        <div><label class="label">iPhone app link (App Store)</label><input v-model="s.app_store_url" class="input" placeholder="https://apps.apple.com/…" /></div>
      </div>
      <p class="text-xs text-muted -mt-1">The Google Play / App Store buttons appear in the shop footer once these links are filled in. Until then customers see "Install the app".</p>
      <div class="flex items-center gap-3">
        <img v-if="s.shop_logo" :src="img(s.shop_logo)" alt="" class="h-10" />
        <label class="btn-outline btn-sm cursor-pointer"><input type="file" accept="image/*" class="sr-only" @change="logo" />Upload logo</label>
      </div>
      <div><label class="label">Receipt footer</label><input v-model="s.receipt_footer" class="input" /></div>
      <div><label class="label">Return policy (printed on receipts, shown online)</label><textarea v-model="s.return_policy" class="input h-16 py-2"></textarea></div>
      <h3 class="font-semibold pt-2">Money & tax</h3>
      <div class="grid grid-cols-3 gap-3">
        <div><label class="label">Currency</label><input v-model="s.currency" class="input" /></div>
        <div><label class="label">Decimals shown</label><select v-model="s.currency_decimals" class="input"><option value="0">0</option><option value="2">2</option></select></div>
        <div><label class="label">Tax label</label><input v-model="s.tax_label" class="input" /></div>
      </div>
      <div class="grid grid-cols-2 gap-3">
        <div><label class="label">Tax rate %</label><input v-model="s.tax_rate" class="input" inputmode="decimal" /></div>
        <div><label class="label">Prices</label><select v-model="s.tax_inclusive" class="input"><option value="1">include tax</option><option value="0">tax added on top</option></select></div>
      </div>
      <h3 class="font-semibold pt-2">Till rules</h3>
      <div class="grid grid-cols-2 gap-3">
        <div><label class="label">Max cashier discount %</label><input v-model="s.max_cashier_discount_pct" class="input" inputmode="decimal" /></div>
        <div><label class="label">Require open shift to sell</label><select v-model="s.require_shift" class="input"><option value="1">Yes</option><option value="0">No</option></select></div>
        <div><label class="label">Offline: max per sale</label><input v-model="s.offline_max_sale_total" class="input" inputmode="decimal" /></div>
        <div><label class="label">Offline: max queued sales</label><input v-model="s.offline_max_queue" class="input" inputmode="numeric" /></div>
      </div>
      <h3 class="font-semibold pt-2">Online shop</h3>
      <div class="grid grid-cols-2 gap-3">
        <div><label class="label">Accept online orders</label><select v-model="s.online_orders_enabled" class="input"><option value="1">Yes</option><option value="0">Paused</option></select></div>
        <div><label class="label">Delivery fee</label><input v-model="s.delivery_fee" class="input" inputmode="decimal" /></div>
        <div><label class="label">Pay on delivery / at pickup</label><select v-model="s.cod_enabled" class="input"><option value="1">On (Kampala &amp; Wakiso deliveries)</option><option value="0">Off (online payment only)</option></select></div>
        <div><label class="label">Hold stock for online payment (minutes)</label><input v-model="s.online_payment_expiry_minutes" class="input" inputmode="numeric" /></div>
      </div>
      <p v-if="error" class="text-sm text-bad">{{ error }}</p>
      <button class="btn-primary" :disabled="busy">Save settings</button>
    </form>

    <div class="space-y-4">
      <IntegrationsCard :settings="s" @saved="(x) => { s = { ...s, ...x }; loadLookups(true) }" />
      <section class="card p-4 space-y-2">
        <h3 class="font-semibold">Payment methods</h3>
        <ul class="divide-y divide-line text-sm">
          <li v-for="m in methods" :key="m.id" class="flex items-center gap-2 py-2">
            <span class="flex-1">{{ m.name }} <span class="text-xs text-muted">{{ m.type.replace('_', ' ') }}{{ m.requires_reference ? ' · needs reference' : '' }}</span></span>
            <button class="text-xs" :class="m.is_active ? 'text-bad' : 'text-ok'" @click="toggleMethod(m)">{{ m.is_active ? 'Disable' : 'Enable' }}</button>
          </li>
        </ul>
        <form class="grid grid-cols-2 gap-2" @submit.prevent="saveMethod(nm)">
          <input v-model="nm.name" class="input" placeholder="New method name" required />
          <input v-model="nm.code" class="input" placeholder="code e.g. visa" required pattern="[A-Za-z0-9_-]+" />
          <select v-model="nm.type" class="input"><option v-for="t in ['cash', 'mobile_money', 'bank', 'card', 'other']" :key="t" :value="t">{{ t }}</option></select>
          <label class="text-sm flex items-center gap-2"><input v-model="nm.requires_reference" type="checkbox" class="accent-brand-600" /> needs reference</label>
          <button class="btn-outline col-span-2">Add method</button>
        </form>
      </section>
      <section class="card p-4 space-y-2">
        <h3 class="font-semibold">Locations</h3>
        <ul class="divide-y divide-line text-sm"><li v-for="l in locations" :key="l.id" class="py-2">{{ l.name }} <span class="text-xs text-muted">{{ l.code }} · {{ l.type }}{{ l.is_default ? ' · default' : '' }}</span></li></ul>
        <form class="grid grid-cols-3 gap-2" @submit.prevent="saveLocation">
          <input v-model="nl.name" class="input" placeholder="Name" required /><input v-model="nl.code" class="input" placeholder="Code" required />
          <select v-model="nl.type" class="input"><option value="shop">shop</option><option value="warehouse">warehouse</option></select>
          <button class="btn-outline col-span-3">Add location</button>
        </form>
      </section>
    </div>
  </div>
</template>

<script setup lang="ts">
import { reactive, ref } from 'vue'
import IntegrationsCard from '@/components/IntegrationsCard.vue'
import { api, errorText, get, img, post, put } from '@/lib/api'
import { toast } from '@/lib/toast'
import { loadLookups } from '@/stores/lookups'

const s = ref<any>(null)
const methods = ref<any[]>([])
const locations = ref<any[]>([])
const busy = ref(false)
const error = ref('')
const nm = reactive<any>({ name: '', code: '', type: 'mobile_money', requires_reference: true, is_active: true })
const nl = reactive<any>({ name: '', code: '', type: 'shop' })

async function load() {
  ;[s.value, methods.value, locations.value] = await Promise.all([get('/admin/settings'), get('/admin/payment-methods'), get('/admin/locations')])
}
load()

async function save() {
  busy.value = true
  error.value = ''
  try {
    const { integrations, google_client_id, google_maps_browser_key, google_maps_server_key, flutterwave_public_key, flutterwave_secret_key, flutterwave_webhook_hash, ...shopFields } = s.value
    s.value = await put('/admin/settings', shopFields)
    toast('Settings saved')
    loadLookups(true)
  } catch (e) {
    error.value = errorText(e)
  } finally {
    busy.value = false
  }
}
async function logo(e: Event) {
  const file = (e.target as HTMLInputElement).files?.[0]
  if (!file) return
  const fd = new FormData()
  fd.append('logo', file)
  try {
    const r = await api('/admin/settings/logo', { method: 'POST', body: fd })
    s.value.shop_logo = r.shop_logo
    toast('Logo updated')
  } catch (err) {
    toast(errorText(err), 'error')
  }
}
async function saveMethod(m: any) {
  try {
    m.id ? await put(`/admin/payment-methods/${m.id}`, m) : await post('/admin/payment-methods', m)
    if (!m.id) Object.assign(nm, { name: '', code: '' })
    methods.value = await get('/admin/payment-methods')
    loadLookups(true)
  } catch (e) {
    toast(errorText(e), 'error')
  }
}
function toggleMethod(m: any) {
  if (m.is_active) {
    const warn = m.type === 'cash'
      ? 'Disable Cash? Cashiers will not be able to take cash at the till, and pay on delivery cannot be settled in cash.'
      : `Disable ${m.name}? It will no longer be offered at the till.`
    if (!confirm(warn)) return
  }
  saveMethod({ ...m, is_active: !m.is_active }).then(() => toast(`${m.name} ${m.is_active ? 'disabled' : 'enabled'}`))
}

async function saveLocation() {
  try {
    await post('/admin/locations', { ...nl, is_active: true })
    Object.assign(nl, { name: '', code: '' })
    locations.value = await get('/admin/locations')
    loadLookups(true)
  } catch (e) {
    toast(errorText(e), 'error')
  }
}
</script>
