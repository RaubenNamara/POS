<template>
  <!-- One page for log in and sign up: enter email or phone first, then we ask for what is needed. -->
  <div class="px-4 py-8 sm:py-12">
    <div class="max-w-md mx-auto bg-white rounded-xl px-5 sm:px-8 py-8 text-center">
      <div class="mx-auto h-16 w-16 rounded-full bg-brand-600 text-white grid place-items-center">
        <Icon :name="step === 'register' ? 'user' : 'cart'" :size="30" />
      </div>

      <template v-if="step === 'start'">
        <h1 class="text-xl font-bold mt-4">Welcome to {{ shopName }}</h1>
        <p class="text-sm text-muted mt-1">Use your email or phone to log in or sign up.</p>
        <form class="mt-6 space-y-5 text-left" novalidate @submit.prevent="next">
          <div class="field">
            <input id="l" v-model.trim="f.login" class="input h-12" required autocomplete="username" autofocus placeholder=" " />
            <label for="l">Email or Mobile Number*</label>
          </div>
          <p v-if="error" class="text-sm text-bad" role="alert">{{ error }}</p>
          <button class="btn-primary w-full h-12 text-base" :disabled="busy || !f.login">{{ busy ? 'Please wait…' : 'Continue' }}</button>
        </form>
        <div class="flex items-center gap-3 mt-8 mb-4 text-sm text-muted"><span class="flex-1 h-px bg-line"></span>Or log in with<span class="flex-1 h-px bg-line"></span></div>
        <div class="flex justify-center items-center gap-4 min-h-[44px]">
          <button type="button" class="social" aria-label="Log in with Facebook" title="Facebook" :disabled="busy" @click="onFacebook"><FacebookMark /></button>
          <!-- With a Client ID, Google draws its own round "G" here; until then a look-alike explains. -->
          <div v-if="googleId" ref="googleIcon" class="h-10 min-w-10" aria-label="Log in with Google"></div>
          <button v-else type="button" class="social" aria-label="Log in with Google" title="Google" @click="notReady('Google')"><GoogleMark /></button>
        </div>
      </template>

      <template v-else>
        <h1 class="text-xl font-bold mt-4">{{ titles[step][0] }}</h1>
        <p class="text-sm text-muted mt-1">{{ titles[step][1] }}</p>
        <!-- The email/phone typed in step one, with a way back to change it. -->
        <div class="mt-5 flex items-center justify-between gap-3 rounded-lg border border-line px-3 h-12 text-left">
          <span class="truncate font-medium">{{ f.login }}</span>
          <button type="button" class="text-sm font-semibold text-brand-700 hover:underline shrink-0" @click="restart">Edit</button>
        </div>

        <div v-if="step === 'google'" class="mt-6">
          <div ref="googleWide" class="flex justify-center min-h-[44px]"></div>
          <p v-if="!googleId" class="text-sm text-muted">Google sign-in is not available right now. Please try again later.</p>
        </div>
        <div v-else-if="step === 'facebook'" class="mt-6 space-y-3">
          <button type="button" class="w-full h-12 rounded-lg bg-[#1877f2] text-white font-semibold inline-flex items-center justify-center gap-2 hover:bg-[#166fe5] disabled:opacity-60" :disabled="busy" @click="onFacebook">
            <FacebookMark white /> Continue with Facebook
          </button>
          <p v-if="error" class="text-sm text-bad" role="alert">{{ error }}</p>
        </div>

        <form v-else class="mt-5 space-y-5 text-left" novalidate @submit.prevent="submit">
          <template v-if="step === 'register'">
            <div class="field">
              <input id="n" v-model="f.name" class="input h-12" required autocomplete="name" placeholder=" " />
              <label for="n">Full name*</label>
            </div>
            <div v-if="loginType === 'phone'" class="field">
              <input id="e" v-model.trim="f.email" type="email" class="input h-12" required autocomplete="email" placeholder=" " />
              <label for="e">Email*</label>
            </div>
            <div v-else class="field">
              <input id="ph" v-model.trim="f.phone" class="input h-12" inputmode="tel" autocomplete="tel" placeholder=" " />
              <label for="ph">Mobile number (optional)</label>
            </div>
          </template>
          <div class="field">
            <input id="p" v-model="f.password" :type="show ? 'text' : 'password'" class="input h-12 pr-11" required
              :autocomplete="step === 'register' ? 'new-password' : 'current-password'" placeholder=" " :autofocus="step === 'password'" />
            <label for="p">Password*</label>
            <button type="button" class="eye" :aria-label="show ? 'Hide password' : 'Show password'" @click="show = !show"><Icon :name="show ? 'eyeoff' : 'eye'" :size="18" /></button>
          </div>
          <div v-if="step === 'register'" class="field">
            <input id="pc" v-model="f.password_confirmation" :type="show ? 'text' : 'password'" class="input h-12" required autocomplete="new-password" placeholder=" " />
            <label for="pc">Confirm password*</label>
          </div>
          <p v-if="step === 'register'" class="text-xs text-muted -mt-2">At least 8 characters.</p>
          <p v-if="error" class="text-sm text-bad" role="alert">{{ error }}</p>
          <button class="btn-primary w-full h-12 text-base" :disabled="busy">{{ busy ? 'Please wait…' : step === 'register' ? 'Create account' : 'Sign in' }}</button>
        </form>
      </template>

      <p class="text-sm text-muted mt-10">
        Need help? Visit our <RouterLink to="/help" class="text-brand-700 hover:underline">Help Center</RouterLink><template v-if="shop.settings.shop_phone"> or call us on <a :href="`tel:${shop.settings.shop_phone}`" class="text-brand-700 hover:underline whitespace-nowrap">{{ shop.settings.shop_phone }}</a></template>.
      </p>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed, defineComponent, h, nextTick, reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import Icon from '@/components/Icon.vue'
import { errorText } from '@/lib/api'
import { identify, login, loginWithFacebook, loginWithGoogle, register, type User } from '@/stores/auth'
import { facebookLogin, prepareFacebook } from '@/lib/facebook'
import { renderGoogleButton } from '@/lib/google'
import { loadHome, shop } from '@/stores/shop'

type Step = 'start' | 'password' | 'register' | 'google' | 'facebook'
const titles: Record<Exclude<Step, 'start'>, [string, string]> = {
  password: ['Welcome back!', 'Enter your password to log in.'],
  register: ['Create your account', "Let's get you started. It only takes a minute."],
  google: ['Continue with Google', 'This account was created with Google. Use Google to log in.'],
  facebook: ['Continue with Facebook', 'This account was created with Facebook. Use Facebook to log in.'],
}

const route = useRoute()
const router = useRouter()
const f = reactive({ login: '', name: '', email: '', phone: '', password: '', password_confirmation: '' })
const step = ref<Step>('start')
const loginType = ref<'email' | 'phone'>('email')
const error = ref('')
const busy = ref(false)
const show = ref(false)
const googleIcon = ref<HTMLElement>()
const googleWide = ref<HTMLElement>()
const shopName = computed(() => shop.settings.shop_name || 'our shop')
const googleId = computed(() => shop.settings.google_client_id || '')
const facebookId = computed(() => shop.settings.facebook_app_id || '')

// Load Facebook's script early so the tap can open its window straight away.
watch(facebookId, (id) => id && prepareFacebook(id).catch(() => {}), { immediate: true })

function notReady(name: string) {
  error.value = `${name} sign-in is not switched on yet. Please continue with your email or phone.`
}

async function onFacebook() {
  error.value = ''
  if (!facebookId.value) return notReady('Facebook')
  try {
    const token = await facebookLogin()
    if (!token) return // closed the Facebook window
    busy.value = true
    go(await loginWithFacebook(token))
  } catch (e) {
    error.value = errorText(e)
  } finally {
    busy.value = false
  }
}

loadHome().catch(() => {})

// Google's button (round icon on step one, full width for Google-only accounts) once the settings are known.
watch([googleId, step], async ([id]) => {
  if (!id) return
  // Google refuses pages opened by IP address (127.0.0.1). On this computer, reopen at localhost.
  if (location.hostname === '127.0.0.1') return location.replace(location.href.replace('//127.0.0.1', '//localhost'))
  await nextTick()
  const el = step.value === 'start' ? googleIcon.value : step.value === 'google' ? googleWide.value : undefined
  if (el) renderGoogleButton(el, id, onGoogle, step.value === 'start').catch((e) => (error.value = e.message))
}, { immediate: true })

async function next() {
  error.value = ''
  if (!f.login) return
  busy.value = true
  try {
    const r = await identify(f.login)
    loginType.value = r.type
    f.password = ''
    f.password_confirmation = ''
    if (r.exists) step.value = r.social ?? 'password'
    else {
      // Carry what they typed into the sign-up form.
      if (r.type === 'email') f.email = f.login
      else f.phone = f.login
      step.value = 'register'
    }
  } catch (e) {
    error.value = errorText(e)
  } finally {
    busy.value = false
  }
}

function restart() {
  step.value = 'start'
  error.value = ''
  f.password = ''
  f.password_confirmation = ''
}

async function onGoogle(credential: string) {
  busy.value = true
  error.value = ''
  try {
    go(await loginWithGoogle(credential))
  } catch (e) {
    error.value = errorText(e)
  } finally {
    busy.value = false
  }
}

function go(u: User) {
  if (u.is_staff) localStorage.setItem('pos.user', JSON.stringify({ id: u.id, name: u.name }))
  const next = typeof route.query.next === 'string' && route.query.next.startsWith('/') ? route.query.next : null
  if (!u.is_staff && u.needs_location) {
    // New customers set their delivery location once; it decides their payment options.
    router.replace({ name: 'location', query: { first: '1', next: next ?? '/' } })
    return
  }
  router.replace(next ?? (u.is_staff ? (u.abilities.includes('reports.operational') ? '/admin' : u.abilities.includes('pos.sell') ? '/pos' : '/admin/products') : '/'))
}

async function submit() {
  busy.value = true
  error.value = ''
  try {
    const u = step.value === 'register'
      ? await register({ name: f.name, email: f.email, phone: f.phone, password: f.password, password_confirmation: f.password_confirmation })
      : await login(f.login, f.password)
    go(u)
  } catch (e) {
    error.value = errorText(e)
  } finally {
    busy.value = false
  }
}

// Brand marks drawn inline (no extra downloads).
const FacebookMark = defineComponent({
  props: { white: Boolean },
  setup: (p) => () => h('svg', { viewBox: '0 0 40 40', width: p.white ? 22 : 40, height: p.white ? 22 : 40, 'aria-hidden': 'true' }, [
    h('circle', { cx: 20, cy: 20, r: 20, fill: p.white ? '#fff' : '#1877f2' }),
    h('path', { fill: p.white ? '#1877f2' : '#fff', d: 'M27.3 25.8 28.2 20h-5.6v-3.8c0-1.6.8-3.1 3.3-3.1h2.5V8.2s-2.3-.4-4.5-.4c-4.6 0-7.6 2.8-7.6 7.8V20h-5.1v5.8h5.1V40a20 20 0 0 0 6.3 0V25.8z' }),
  ]),
})
const GoogleMark = () => h('svg', { viewBox: '0 0 48 48', width: 24, height: 24, 'aria-hidden': 'true' }, [
  h('path', { fill: '#FFC107', d: 'M43.6 20.5H42V20H24v8h11.3C33.7 32.7 29.2 36 24 36c-6.6 0-12-5.4-12-12s5.4-12 12-12c3.1 0 5.8 1.2 7.9 3.1l5.7-5.7C34 6.1 29.3 4 24 4 12.9 4 4 12.9 4 24s8.9 20 20 20 20-8.9 20-20c0-1.3-.1-2.4-.4-3.5z' }),
  h('path', { fill: '#FF3D00', d: 'm6.3 14.7 6.6 4.8C14.7 15.1 19 12 24 12c3.1 0 5.8 1.2 7.9 3.1l5.7-5.7C34 6.1 29.3 4 24 4 16.3 4 9.7 8.3 6.3 14.7z' }),
  h('path', { fill: '#4CAF50', d: 'M24 44c5.2 0 9.9-2 13.4-5.2l-6.2-5.2C29.2 35.1 26.7 36 24 36c-5.2 0-9.6-3.3-11.3-8l-6.5 5C9.5 39.6 16.2 44 24 44z' }),
  h('path', { fill: '#1976D2', d: 'M43.6 20.5H42V20H24v8h11.3c-.8 2.2-2.2 4.2-4.1 5.6l6.2 5.2C37 39.2 44 34 44 24c0-1.3-.1-2.4-.4-3.5z' }),
])
</script>

<style scoped>
/* Label sits on the border, like the field in the picture. */
.field { position: relative; }
.field label {
  position: absolute; left: 0.625rem; top: -0.55rem; padding: 0 0.25rem; background: #fff;
  font-size: 0.75rem; line-height: 1rem; color: var(--color-muted); pointer-events: none;
}
.field:focus-within label { color: var(--color-brand-700); }
.field input:focus { border-color: var(--color-brand-600); }
.eye {
  position: absolute; right: 0.375rem; top: 0.375rem; height: 2.25rem; width: 2.25rem;
  display: grid; place-items: center; border-radius: 9999px; color: var(--color-muted);
}
.eye:hover { background: #f3f4f6; }
.social {
  height: 2.5rem; width: 2.5rem; border-radius: 9999px; display: grid; place-items: center;
  background: #fff; border: 1px solid var(--color-line); overflow: hidden;
}
.social:hover { box-shadow: 0 1px 4px rgb(0 0 0 / 0.2); }
</style>
