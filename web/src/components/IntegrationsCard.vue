<template>
  <form class="card p-4 space-y-4" autocomplete="off" @submit.prevent="save">
    <div>
      <h3 class="font-semibold">Online payments, Google & Facebook sign-in</h3>
      <p class="text-xs text-muted mt-0.5">Paste the keys from your own Google, Facebook and Flutterwave accounts. Each feature switches on as soon as its keys are saved.</p>
    </div>

    <!-- Google sign-in -->
    <section class="space-y-2">
      <div class="flex items-center justify-between gap-2"><b class="text-sm">Google sign-in</b><Status :on="st.google_signin" /></div>
      <div><label class="label">OAuth Client ID</label>
        <input v-model="f.google_client_id" name="google_client_id" class="input" autocomplete="off" data-lpignore="true" data-1p-ignore spellcheck="false" placeholder="1234567890-abc.apps.googleusercontent.com" :disabled="st.from_env?.google_client_id" /></div>
      <details class="howto"><summary>How to get it</summary>
        <ol>
          <li>Open <a href="https://console.cloud.google.com/apis/credentials" target="_blank" rel="noopener">Google Cloud Console → Credentials</a> and sign in with your Google account.</li>
          <li>Create a project if asked, then <b>Create credentials → OAuth client ID</b> (configure the consent screen first if prompted: External, your shop name and email).</li>
          <li>Application type <b>Web application</b>. Under <b>Authorised JavaScript origins</b> add your site address, e.g. <code>https://yourshop.com</code>. For testing on this computer add <code>http://localhost:8000</code> and open the shop at <b>http://localhost:8000</b> (Google does not accept 127.0.0.1).</li>
          <li>Copy the <b>Client ID</b> here and save.</li>
        </ol>
      </details>
    </section>

    <!-- Facebook sign-in -->
    <section class="space-y-2">
      <div class="flex items-center justify-between gap-2"><b class="text-sm">Facebook sign-in</b><Status :on="st.facebook_signin" /></div>
      <div><label class="label">App ID</label>
        <input v-model="f.facebook_app_id" name="facebook_app_id" class="input" autocomplete="off" data-lpignore="true" data-1p-ignore spellcheck="false" inputmode="numeric" placeholder="1234567890123456" :disabled="st.from_env?.facebook_app_id" /></div>
      <div><label class="label">App Secret {{ st.secrets_set?.facebook_app_secret ? '(saved, leave blank to keep)' : '' }}</label>
        <div class="relative"><input v-model="f.facebook_app_secret" name="facebook_app_secret" class="input pr-11" :type="show.facebook_app_secret ? 'text' : 'password'" autocomplete="new-password" data-lpignore="true" data-1p-ignore placeholder="32 letters and numbers" :disabled="st.from_env?.facebook_app_secret" /><button type="button" class="absolute right-1 top-1/2 -translate-y-1/2 h-9 w-9 grid place-items-center rounded text-muted hover:text-ink hover:bg-gray-100" :aria-label="show.facebook_app_secret ? 'Hide' : 'Show'" :title="show.facebook_app_secret ? 'Hide' : 'Show what you typed'" @click="show.facebook_app_secret = !show.facebook_app_secret"><Icon :name="show.facebook_app_secret ? 'eyeoff' : 'eye'" :size="18" /></button></div></div>
      <details class="howto"><summary>How to get them</summary>
        <ol>
          <li>Open <a href="https://developers.facebook.com/apps" target="_blank" rel="noopener">Meta for Developers → My Apps</a>, sign in with Facebook and choose <b>Create app</b>.</li>
          <li>Pick the use case <b>Authenticate and request data from users with Facebook Login</b>, give it your shop name, and finish.</li>
          <li>In <b>Facebook Login → Settings</b> add your site to <b>Allowed Domains for the JavaScript SDK</b>, e.g. <code>https://yourshop.com</code> (Facebook requires https on a live site; <code>http://localhost:8000</code> works for testing).</li>
          <li>In <b>App settings → Basic</b> copy the <b>App ID</b> and <b>App Secret</b> here and save. Fill in the privacy policy link there, then switch the app to <b>Live</b> so customers can use it.</li>
        </ol>
      </details>
    </section>

    <!-- Google Maps -->
    <section class="space-y-2 pt-3 border-t border-line">
      <div class="flex items-center justify-between gap-2"><b class="text-sm">Google Maps (location & pay-on-delivery area)</b><Status :on="st.google_maps_server" /></div>
      <div><label class="label">Browser key (Maps JavaScript API) for the map pin</label>
        <input v-model="f.google_maps_browser_key" name="google_maps_browser_key" class="input" autocomplete="off" data-lpignore="true" data-1p-ignore spellcheck="false" placeholder="AIza…" :disabled="st.from_env?.google_maps_browser_key" /></div>
      <div><label class="label">Server key (Geocoding API) to check Kampala / Wakiso {{ st.secrets_set?.google_maps_server_key ? '(saved, leave blank to keep)' : '' }}</label>
        <div class="relative"><input v-model="f.google_maps_server_key" name="google_maps_server_key" class="input pr-11" :type="show.google_maps_server_key ? 'text' : 'password'" autocomplete="new-password" data-lpignore="true" data-1p-ignore placeholder="AIza…" :disabled="st.from_env?.google_maps_server_key" /><button type="button" class="absolute right-1 top-1/2 -translate-y-1/2 h-9 w-9 grid place-items-center rounded text-muted hover:text-ink hover:bg-gray-100" :aria-label="show.google_maps_server_key ? 'Hide' : 'Show'" :title="show.google_maps_server_key ? 'Hide' : 'Show what you typed'" @click="show.google_maps_server_key = !show.google_maps_server_key"><Icon :name="show.google_maps_server_key ? 'eyeoff' : 'eye'" :size="18" /></button></div></div>
      <p v-if="!st.google_maps_server" class="text-xs text-warn">Without the server key the system cannot confirm a customer's district, so pay on delivery is not offered (online payment only).</p>
      <details class="howto"><summary>How to get them</summary>
        <ol>
          <li>In the same Google Cloud project open <b>APIs & Services → Library</b> and enable <b>Maps JavaScript API</b> and <b>Geocoding API</b> (a billing account is required; Google gives a monthly free allowance).</li>
          <li><b>Credentials → Create credentials → API key</b> twice. Restrict the first to <i>HTTP referrers</i> (your site) and Maps JavaScript API; the second to Geocoding API.</li>
        </ol>
      </details>
    </section>

    <!-- Flutterwave -->
    <section class="space-y-2 pt-3 border-t border-line">
      <div class="flex items-center justify-between gap-2">
        <b class="text-sm">Flutterwave (MTN / Airtel Mobile Money, cards)</b>
        <span class="flex items-center gap-1.5">
          <span v-if="st.flutterwave_mode" class="badge" :class="st.flutterwave_mode === 'live' ? 'bg-green-50 text-ok' : 'bg-amber-50 text-warn'">{{ st.flutterwave_mode === 'live' ? 'Live' : 'Test mode' }}</span>
          <Status :on="st.flutterwave" />
        </span>
      </div>
      <div><label class="label">Public key</label>
        <input v-model="f.flutterwave_public_key" name="flutterwave_public_key" class="input" autocomplete="off" data-lpignore="true" data-1p-ignore spellcheck="false" placeholder="FLWPUBK_TEST-…" :disabled="st.from_env?.flutterwave_public_key" /></div>
      <div><label class="label">Secret key {{ st.secrets_set?.flutterwave_secret_key ? '(saved, leave blank to keep)' : '' }}</label>
        <div class="relative"><input v-model="f.flutterwave_secret_key" name="flutterwave_secret_key" class="input pr-11" :type="show.flutterwave_secret_key ? 'text' : 'password'" autocomplete="new-password" data-lpignore="true" data-1p-ignore placeholder="FLWSECK_TEST-…" :disabled="st.from_env?.flutterwave_secret_key" /><button type="button" class="absolute right-1 top-1/2 -translate-y-1/2 h-9 w-9 grid place-items-center rounded text-muted hover:text-ink hover:bg-gray-100" :aria-label="show.flutterwave_secret_key ? 'Hide' : 'Show'" :title="show.flutterwave_secret_key ? 'Hide' : 'Show what you typed'" @click="show.flutterwave_secret_key = !show.flutterwave_secret_key"><Icon :name="show.flutterwave_secret_key ? 'eyeoff' : 'eye'" :size="18" /></button></div></div>
      <div><label class="label">Webhook secret hash {{ st.secrets_set?.flutterwave_webhook_hash ? '(saved, leave blank to keep)' : '(any long secret word you choose)' }}</label>
        <div class="relative"><input v-model="f.flutterwave_webhook_hash" name="flutterwave_webhook_hash" class="input pr-11" :type="show.flutterwave_webhook_hash ? 'text' : 'password'" autocomplete="new-password" data-lpignore="true" data-1p-ignore :disabled="st.from_env?.flutterwave_webhook_hash" /><button type="button" class="absolute right-1 top-1/2 -translate-y-1/2 h-9 w-9 grid place-items-center rounded text-muted hover:text-ink hover:bg-gray-100" :aria-label="show.flutterwave_webhook_hash ? 'Hide' : 'Show'" :title="show.flutterwave_webhook_hash ? 'Hide' : 'Show what you typed'" @click="show.flutterwave_webhook_hash = !show.flutterwave_webhook_hash"><Icon :name="show.flutterwave_webhook_hash ? 'eyeoff' : 'eye'" :size="18" /></button></div></div>
      <div class="flex flex-wrap items-center gap-2">
        <button type="button" class="btn-outline btn-sm" :disabled="testing || !st.flutterwave" @click="testConnection">{{ testing ? 'Checking with Flutterwave…' : 'Test connection' }}</button>
        <span v-if="!st.flutterwave" class="text-xs text-muted">Save both keys, then test.</span>
        <span v-if="testResult" class="text-sm font-semibold" :class="testResult.ok ? 'text-ok' : 'text-bad'">{{ testResult.message }}</span>
      </div>
      <div class="text-xs bg-gray-50 rounded p-2">Webhook URL to paste in Flutterwave: <code class="break-all select-all">{{ st.webhook_url }}</code></div>
      <details class="howto"><summary>How to get them</summary>
        <ol>
          <li>Create a free account at <a href="https://dashboard.flutterwave.com/signup" target="_blank" rel="noopener">dashboard.flutterwave.com</a> (Uganda).</li>
          <li>Switch the dashboard to <b>Test mode</b> first. Go to <b>Settings → API Keys</b> and copy the <b>Public key</b> and <b>Secret key</b> here.</li>
          <li>In <b>Settings → Webhooks</b> paste the webhook URL above and type the same <b>Secret hash</b> you enter here.</li>
          <li>Place a test order and pay with Flutterwave's test mobile money / card numbers. When it works, replace the keys with your <b>Live</b> keys (after Flutterwave approves your business).</li>
        </ol>
      </details>
    </section>

    <p v-if="Object.values(st.from_env ?? {}).some(Boolean)" class="text-xs text-muted">Greyed-out fields are set in the server's .env file and can only be changed there.</p>
    <p v-if="error" class="text-sm text-bad">{{ error }}</p>
    <div class="flex flex-wrap gap-2">
      <button class="btn-primary" :disabled="busy">{{ busy ? 'Saving…' : 'Save keys' }}</button>
      <button v-if="Object.values(st.secrets_set ?? {}).some(Boolean)" type="button" class="btn-outline text-bad" :disabled="busy" @click="clearAll">Remove saved secret keys</button>
    </div>
  </form>
</template>

<script setup lang="ts">
import { defineComponent, h, reactive, ref, watch } from 'vue'
import Icon from './Icon.vue'
import { errorText, post, put } from '@/lib/api'
import { toast } from '@/lib/toast'

const props = defineProps<{ settings: any }>()
const emit = defineEmits<{ saved: [s: any] }>()
const st = ref<any>(props.settings.integrations ?? {})
const f = reactive({
  google_client_id: props.settings.google_client_id ?? '', google_maps_browser_key: props.settings.google_maps_browser_key ?? '',
  google_maps_server_key: '', flutterwave_public_key: props.settings.flutterwave_public_key ?? '', flutterwave_secret_key: '', flutterwave_webhook_hash: '',
  facebook_app_id: props.settings.facebook_app_id ?? '', facebook_app_secret: '',
})
const busy = ref(false)
const error = ref('')
// Eye buttons: reveal only what was typed here (saved secrets are never sent back to the browser).
const show = reactive({ google_maps_server_key: false, facebook_app_secret: false, flutterwave_secret_key: false, flutterwave_webhook_hash: false })
watch(() => props.settings, (s) => (st.value = s.integrations ?? {}))

async function send(body: any, msg: string) {
  busy.value = true
  error.value = ''
  try {
    const s = await put('/admin/settings', body)
    st.value = s.integrations
    // Keep the saved public values in the boxes; secret boxes clear (they are stored, not shown).
    Object.assign(f, {
      google_client_id: s.google_client_id ?? '', google_maps_browser_key: s.google_maps_browser_key ?? '', flutterwave_public_key: s.flutterwave_public_key ?? '',
      google_maps_server_key: '', flutterwave_secret_key: '', flutterwave_webhook_hash: '',
      facebook_app_id: s.facebook_app_id ?? '', facebook_app_secret: '',
    })
    Object.assign(show, { google_maps_server_key: false, facebook_app_secret: false, flutterwave_secret_key: false, flutterwave_webhook_hash: false })
    toast(msg)
    emit('saved', s)
  } catch (e) {
    error.value = errorText(e)
  } finally {
    busy.value = false
  }
}
const save = async () => {
  // Fields set in .env are read-only here; don't send them.
  const body = Object.fromEntries(Object.entries(f).filter(([k]) => !st.value.from_env?.[k]).map(([k, v]) => [k, String(v ?? '').trim()]))
  const typedSecret = ['google_maps_server_key', 'facebook_app_secret', 'flutterwave_secret_key', 'flutterwave_webhook_hash'].some((k) => body[k])
  const changedPlain = ['google_client_id', 'google_maps_browser_key', 'facebook_app_id', 'flutterwave_public_key'].some((k) => (body[k] ?? '') !== (props.settings[k] ?? ''))
  if (!typedSecret && !changedPlain) {
    error.value = 'Nothing changed. Paste a key into one of the boxes first.'
    return
  }
  await send(body, 'Saved')
  // Say plainly what the save achieved.
  const s = st.value
  if (body.facebook_app_id && !s.facebook_signin) toast('Facebook App ID saved. Paste the App Secret too to switch Facebook sign-in on.', 'info', 6000)
  if (s.flutterwave) toast(`Flutterwave connected (${s.flutterwave_mode === 'live' ? 'live' : 'test'} mode)`)
  else if (body.flutterwave_public_key && !s.secrets_set?.flutterwave_secret_key) toast('Public key saved. Paste the Secret key to connect Flutterwave.', 'info', 6000)
  else if (s.secrets_set?.flutterwave_secret_key && !body.flutterwave_public_key) toast('Secret key saved. Paste the Public key to connect Flutterwave.', 'info', 6000)
}

const testing = ref(false)
const testResult = ref<{ ok: boolean; message: string } | null>(null)
async function testConnection() {
  testing.value = true
  testResult.value = null
  try {
    testResult.value = await post('/admin/settings/test-flutterwave')
  } catch (e) {
    testResult.value = { ok: false, message: errorText(e) }
  } finally {
    testing.value = false
  }
}
const clearAll = () => confirm('Remove the saved secret keys? Online payment, Facebook sign-in and the district check will stop until new keys are saved.')
  && send({ clear_secrets: ['google_maps_server_key', 'facebook_app_secret', 'flutterwave_secret_key', 'flutterwave_webhook_hash'] }, 'Secret keys removed')

const Status = defineComponent({
  props: { on: Boolean },
  setup: (p) => () => h('span', { class: ['badge', p.on ? 'bg-green-50 text-ok' : 'bg-gray-100 text-muted'] }, p.on ? 'Connected' : 'Not connected'),
})
</script>

<style scoped>
.howto { font-size: 0.8125rem; background: #fafafa; border-radius: 0.375rem; padding: 0.5rem 0.75rem; }
.howto summary { cursor: pointer; font-weight: 600; color: var(--color-brand-700); }
.howto ol { list-style: decimal; padding-left: 1.1rem; margin-top: 0.5rem; }
.howto li { padding: 0.15rem 0; }
.howto a { color: var(--color-brand-700); text-decoration: underline; }
code { background: #f3f4f6; padding: 0 0.25rem; border-radius: 0.25rem; }
</style>
