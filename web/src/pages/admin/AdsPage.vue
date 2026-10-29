<template>
  <div class="space-y-4 max-w-6xl 2xl:max-w-[max(80rem,70vw)]">
    <div class="card p-4 flex flex-wrap items-center gap-3">
      <div class="flex-1 min-w-60">
        <h2 class="font-semibold">Adverts on the home page</h2>
        <p class="text-sm text-muted"><b>Banner slider</b> at the top (changes every 3 seconds) and <b>wide banners</b> between product sections. Adverts with an end date switch themselves off.</p>
      </div>
      <a href="/" target="_blank" rel="noopener" class="btn-outline">View home page</a>
      <button class="btn-primary" @click="openNew"><Icon name="plus" :size="16" /> New advert</button>
    </div>

    <div v-if="loading" class="grid sm:grid-cols-2 lg:grid-cols-3 gap-4"><div v-for="i in 3" :key="i" class="skeleton h-56"></div></div>
    <template v-else>
      <section v-for="g in groups" :key="g.key" class="space-y-2">
        <h3 class="text-sm font-semibold text-muted uppercase tracking-wide">{{ g.title }} <span class="normal-case font-normal">({{ g.size }})</span></h3>
        <div v-if="!g.items.length" class="card p-6 text-center text-sm text-muted">No adverts here yet.</div>
        <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-4">
          <article v-for="a in g.items" :key="a.id" class="card overflow-hidden flex flex-col">
            <div class="relative bg-gray-100" :class="a.placement === 'hero' ? 'aspect-[3/1]' : 'aspect-[5/1]'">
              <img :src="img(a.image_path)" :alt="a.title" class="absolute inset-0 h-full w-full object-cover" loading="lazy" />
              <span class="absolute top-2 left-2 badge" :class="statusClass(a.status)">{{ statusLabel(a.status) }}</span>
            </div>
            <div class="p-3 flex-1 space-y-1 text-sm">
              <div class="font-semibold">{{ a.title }}</div>
              <div class="text-xs text-muted truncate">{{ a.link_url ? `Opens ${a.link_url}` : 'No link' }}</div>
              <div class="text-xs text-muted">{{ dates(a) }}</div>
              <div class="text-xs"><b>{{ a.clicks }}</b> click{{ a.clicks === 1 ? '' : 's' }} · order {{ a.sort_order }}</div>
            </div>
            <div class="px-3 pb-3 flex gap-2">
              <button class="btn-outline btn-sm" @click="edit(a)">Edit</button>
              <button class="btn-outline btn-sm" @click="toggle(a)">{{ a.is_active ? 'Turn off' : 'Turn on' }}</button>
              <button class="btn-ghost btn-sm text-bad ml-auto" @click="remove(a)">Delete</button>
            </div>
          </article>
        </div>
      </section>
    </template>

    <Modal :open="formOpen" :title="f.id ? 'Edit advert' : 'New advert'" wide @close="formOpen = false">
      <form class="space-y-4" @submit.prevent="save">
        <div class="grid sm:grid-cols-2 gap-3">
          <label v-for="p in placements" :key="p.v" class="border rounded-md p-3 cursor-pointer text-sm" :class="f.placement === p.v ? 'border-brand-600 bg-brand-50' : 'border-line'">
            <input v-model="f.placement" type="radio" :value="p.v" class="sr-only" />
            <b>{{ p.t }}</b><div class="text-xs text-muted">{{ p.d }}</div>
          </label>
        </div>

        <div class="grid sm:grid-cols-2 gap-4">
          <div>
            <label class="label">Picture for computers *</label>
            <div class="relative rounded-md border-2 border-dashed border-line bg-gray-50 overflow-hidden" :class="f.placement === 'hero' ? 'aspect-[3/1]' : 'aspect-[5/1]'">
              <img v-if="preview.main" :src="preview.main" alt="" class="absolute inset-0 h-full w-full object-cover" />
              <label class="absolute inset-0 grid place-items-center cursor-pointer text-sm text-muted hover:bg-black/5">
                <input type="file" accept="image/*" class="sr-only" @change="pick($event, 'main')" />
                <span v-if="!preview.main">+ Choose picture</span>
              </label>
            </div>
            <p class="text-xs text-muted mt-1">Best size: {{ f.placement === 'hero' ? '1500 × 500' : '1500 × 300' }} pixels. Keep important words away from the edges.</p>
          </div>
          <div>
            <label class="label">Picture for phones (optional)</label>
            <div class="relative rounded-md border-2 border-dashed border-line bg-gray-50 overflow-hidden" :class="f.placement === 'hero' ? 'aspect-[4/3]' : 'aspect-[2/1]'" style="max-height: 9rem">
              <img v-if="preview.mobile" :src="preview.mobile" alt="" class="absolute inset-0 h-full w-full object-cover" />
              <label class="absolute inset-0 grid place-items-center cursor-pointer text-sm text-muted hover:bg-black/5">
                <input type="file" accept="image/*" class="sr-only" @change="pick($event, 'mobile')" />
                <span v-if="!preview.mobile">+ Choose picture</span>
              </label>
            </div>
            <p class="text-xs text-muted mt-1">Best size: {{ f.placement === 'hero' ? '800 × 600' : '800 × 400' }}. Without it, phones show the computer picture cropped.
              <button v-if="preview.mobile" type="button" class="text-bad underline" @click="clearMobile">Remove</button></p>
          </div>
        </div>

        <div class="grid sm:grid-cols-2 gap-3">
          <div><label class="label">Title * (also read out to blind users)</label><input v-model="f.title" class="input" required maxlength="120" /></div>
          <div><label class="label">Subtitle</label><input v-model="f.subtitle" class="input" maxlength="200" /></div>
        </div>
        <label class="flex items-center gap-2 text-sm"><input v-model="f.show_text" type="checkbox" class="accent-brand-600" /> Write the title and subtitle on the picture (leave off if the picture already has words)</label>

        <div>
          <label class="label">When clicked, open</label>
          <input v-model="f.link_url" class="input" placeholder="/c/computing   or   /p/hp-250-g9-laptop   or   https://…" />
          <p class="text-xs text-muted mt-1">A page in this shop starts with “/”, e.g. a category <code>/c/computing</code>, a product <code>/p/…</code> (copy from the address bar), or deals <code>/search?on_sale=1</code>. Leave empty for no link.</p>
        </div>

        <div class="grid sm:grid-cols-4 gap-3">
          <div><label class="label">Show from</label><input v-model="f.starts_at" type="datetime-local" class="input" /></div>
          <div><label class="label">Until</label><input v-model="f.ends_at" type="datetime-local" class="input" /></div>
          <div><label class="label">Order (1 shows first)</label><input v-model.number="f.sort_order" type="number" min="0" class="input" /></div>
          <label class="flex items-center gap-2 text-sm self-end h-10"><input v-model="f.is_active" type="checkbox" class="accent-brand-600" /> Switched on</label>
        </div>

        <p v-if="error" class="text-sm text-bad">{{ error }}</p>
        <div class="flex justify-end gap-2">
          <button type="button" class="btn-outline" @click="formOpen = false">Cancel</button>
          <button class="btn-primary" :disabled="busy">{{ busy ? 'Saving…' : 'Save advert' }}</button>
        </div>
      </form>
    </Modal>
  </div>
</template>

<script setup lang="ts">
import { computed, reactive, ref } from 'vue'
import Icon from '@/components/Icon.vue'
import Modal from '@/components/Modal.vue'
import { api, del, errorText, get, img } from '@/lib/api'
import { toWebp } from '@/lib/image'
import { toast } from '@/lib/toast'

const ads = ref<any[]>([])
const loading = ref(true)
const formOpen = ref(false)
const busy = ref(false)
const error = ref('')
const placements = [
  { v: 'hero', t: 'Banner slider (top of home page)', d: 'Large picture; several adverts take turns every 3 seconds.' },
  { v: 'strip', t: 'Wide banner (between sections)', d: 'Thin picture shown between product rows.' },
]
const blank = () => ({ id: 0, title: '', subtitle: '', placement: 'hero', link_url: '', show_text: false, sort_order: 0, starts_at: '', ends_at: '', is_active: true })
const f = reactive<any>(blank())
const files = reactive<{ main: Blob | null; mobile: Blob | null; mainSize: [number, number] | null; removeMobile: boolean }>({ main: null, mobile: null, mainSize: null, removeMobile: false })
const preview = reactive<{ main: string; mobile: string }>({ main: '', mobile: '' })

const groups = computed(() => [
  { key: 'hero', title: 'Banner slider', size: 'top of the home page', items: ads.value.filter((a) => a.placement === 'hero') },
  { key: 'strip', title: 'Wide banners', size: 'between sections', items: ads.value.filter((a) => a.placement === 'strip') },
])
const statusLabel = (s: string) => ({ live: 'Showing now', off: 'Off', scheduled: 'Scheduled', ended: 'Ended' } as Record<string, string>)[s]
const statusClass = (s: string) => (s === 'live' ? 'bg-green-50 text-ok' : s === 'scheduled' ? 'bg-amber-50 text-warn' : 'bg-gray-100 text-muted')
const fmt = (d: string) => new Date(d).toLocaleString([], { dateStyle: 'medium', timeStyle: 'short' })
const dates = (a: any) => (a.starts_at || a.ends_at ? `${a.starts_at ? fmt(a.starts_at) : 'Now'} → ${a.ends_at ? fmt(a.ends_at) : 'no end'}` : 'No end date')

async function load() {
  // Grey placeholders only on the first visit; later refreshes update the list in place.
  if (!ads.value.length) loading.value = true
  try {
    ads.value = await get('/admin/ads')
  } finally {
    loading.value = false
  }
}
load()

const toLocal = (d: string | null) => (d ? new Date(d).toISOString().slice(0, 16) : '')
function resetFiles() {
  Object.assign(files, { main: null, mobile: null, mainSize: null, removeMobile: false })
}
function openNew() {
  Object.assign(f, blank())
  resetFiles()
  Object.assign(preview, { main: '', mobile: '' })
  error.value = ''
  formOpen.value = true
}
function edit(a: any) {
  Object.assign(f, { ...blank(), ...a, subtitle: a.subtitle ?? '', link_url: a.link_url ?? '', starts_at: toLocal(a.starts_at), ends_at: toLocal(a.ends_at) })
  resetFiles()
  Object.assign(preview, { main: img(a.image_path), mobile: a.image_mobile_path ? img(a.image_mobile_path) : '' })
  error.value = ''
  formOpen.value = true
}

async function pick(e: Event, which: 'main' | 'mobile') {
  const file = (e.target as HTMLInputElement).files?.[0]
  if (!file) return
  try {
    // Shrink on this device before upload (fast on slow connections).
    const r = await toWebp(file, which === 'main' ? 1600 : 900, 0.82)
    files[which] = r.blob
    if (which === 'main') files.mainSize = [r.width, r.height]
    else files.removeMobile = false
    preview[which] = URL.createObjectURL(r.blob)
  } catch {
    error.value = 'That picture could not be read. Try a JPG or PNG.'
  }
}
function clearMobile() {
  files.mobile = null
  files.removeMobile = true
  preview.mobile = ''
}

async function save() {
  if (!f.id && !files.main) {
    error.value = 'Choose the picture for computers.'
    return
  }
  busy.value = true
  error.value = ''
  const fd = new FormData()
  for (const k of ['title', 'subtitle', 'placement', 'link_url', 'sort_order']) fd.append(k, String(f[k] ?? ''))
  fd.append('show_text', f.show_text ? '1' : '0')
  fd.append('is_active', f.is_active ? '1' : '0')
  if (f.starts_at) fd.append('starts_at', new Date(f.starts_at).toISOString())
  if (f.ends_at) fd.append('ends_at', new Date(f.ends_at).toISOString())
  const ext = (b: Blob) => (b.type === 'image/webp' ? 'webp' : 'jpg')
  if (files.main) {
    fd.append('image', files.main, `ad.${ext(files.main)}`)
    if (files.mainSize) {
      fd.append('width', String(files.mainSize[0]))
      fd.append('height', String(files.mainSize[1]))
    }
  }
  if (files.mobile) fd.append('image_mobile', files.mobile, `ad-mobile.${ext(files.mobile)}`)
  if (files.removeMobile) fd.append('remove_mobile_image', '1')
  try {
    await api(f.id ? `/admin/ads/${f.id}` : '/admin/ads', { method: 'POST', body: fd, timeout: 60000 })
    toast(f.id ? 'Advert saved' : 'Advert added')
    formOpen.value = false
    load()
  } catch (e) {
    error.value = errorText(e)
  } finally {
    busy.value = false
  }
}

async function toggle(a: any) {
  const fd = new FormData()
  for (const k of ['title', 'subtitle', 'placement', 'link_url', 'sort_order']) fd.append(k, String(a[k] ?? ''))
  fd.append('show_text', a.show_text ? '1' : '0')
  fd.append('is_active', a.is_active ? '0' : '1')
  if (a.starts_at) fd.append('starts_at', a.starts_at)
  if (a.ends_at) fd.append('ends_at', a.ends_at)
  try {
    await api(`/admin/ads/${a.id}`, { method: 'POST', body: fd })
    toast(a.is_active ? 'Advert turned off' : 'Advert turned on')
    load()
  } catch (e) {
    toast(errorText(e), 'error')
  }
}
async function remove(a: any) {
  if (!confirm(`Delete the advert "${a.title}"? Its pictures are deleted too.`)) return
  await del(`/admin/ads/${a.id}`)
  toast('Advert deleted')
  load()
}
</script>
