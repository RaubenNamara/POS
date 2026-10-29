<template>
  <div class="min-h-screen flex">
    <!-- Sidebar -->
    <aside
      class="fixed lg:sticky top-0 z-40 h-screen bg-[#111827] text-gray-400 flex flex-col transition-[width,transform] duration-150"
      :class="[collapsed ? 'lg:w-16' : 'lg:w-60 2xl:w-64', mobileOpen ? 'translate-x-0 w-64 visible' : '-translate-x-full lg:translate-x-0 w-64 invisible lg:visible']"
    >
      <div class="h-16 flex items-center gap-2.5 px-4 shrink-0">
        <span class="h-9 w-9 rounded-lg bg-brand-600 text-white grid place-items-center font-black shrink-0">{{ (shopName || 'D').charAt(0) }}</span>
        <span v-if="!collapsed || mobileOpen" class="min-w-0 leading-tight">
          <span class="block font-bold text-white truncate">{{ shopName || 'Back office' }}</span>
          <span class="block text-[11px] text-gray-500">Back office</span>
        </span>
      </div>
      <nav class="flex-1 overflow-y-auto px-2 pb-3 text-sm [scrollbar-width:thin] [scrollbar-color:#374151_transparent]">
        <!-- One entry per area; the pages inside an area are tabs at the top of the page. -->
        <div class="pt-3 space-y-0.5">
          <template v-for="(sec, i) in visibleSections" :key="sec.key">
            <!-- thin divider between groups (overview, selling, stock, buying, admin) -->
            <div v-if="i > 0 && sec.group !== visibleSections[i - 1].group" class="mx-3 my-2.5 border-t border-white/10"></div>
            <RouterLink
              :to="sec.tabs[0].to"
              class="nav-item"
              :class="{ on: sec.key === current?.key }"
              :title="sec.label"
              @click="mobileOpen = false"
            >
              <Icon :name="sec.icon" :size="18" class="shrink-0" />
              <span v-if="!collapsed || mobileOpen" class="truncate">{{ sec.label }}</span>
            </RouterLink>
          </template>
        </div>
      </nav>
      <button class="hidden lg:flex items-center gap-3 px-5 h-11 border-t border-white/10 text-xs hover:text-white" @click="toggleCollapse">
        <Icon :name="collapsed ? 'chevron' : 'left'" :size="16" /> <span v-if="!collapsed">Collapse</span>
      </button>
    </aside>
    <div v-if="mobileOpen" class="fixed inset-0 z-30 bg-black/40 lg:hidden" @click="mobileOpen = false"></div>

    <!-- Main -->
    <div class="flex-1 min-w-0 flex flex-col">
      <header class="h-16 bg-white/95 backdrop-blur border-b border-line sticky top-0 z-20 flex items-center gap-3 px-3 sm:px-5 2xl:px-8">
        <button class="lg:hidden btn-ghost px-2" aria-label="Menu" @click="mobileOpen = true"><Icon name="menu" /></button>
        <h1 class="text-lg font-bold truncate">{{ route.meta.title }}</h1>
        <form v-if="can('products.view')" class="hidden md:flex flex-1 max-w-md ml-6 relative items-center" role="search" @submit.prevent="router.push({ path: '/admin/products', query: { q: q } })">
          <Icon name="search" :size="18" class="absolute left-3 text-muted pointer-events-none" />
          <input v-model="q" class="input h-10 !rounded-full !bg-gray-100 !border-transparent pl-10 focus:!bg-white focus:!border-brand-500" placeholder="Find product by name, SKU or barcode" />
        </form>
        <div class="flex-1"></div>
        <RouterLink v-if="can('pos.sell')" to="/pos" class="btn-primary h-9 px-3 sm:px-4 rounded-full"><Icon name="pos" :size="16" /> <span class="hidden sm:inline">Open POS</span></RouterLink>
        <div class="relative">
          <button class="h-10 w-10 rounded-full grid place-items-center text-ink hover:bg-gray-100 relative" aria-label="Notifications" @click="toggleBell"><Icon name="bell" />
            <span v-if="unread" class="absolute top-0.5 right-0.5 min-w-4 h-4 px-1 rounded-full bg-bad text-white text-[10px] font-bold grid place-items-center">{{ unread > 99 ? '99+' : unread }}</span>
          </button>
          <div v-if="bellOpen" class="absolute right-0 mt-2 w-80 max-w-[90vw] bg-white border border-line rounded-md shadow-lg z-50">
            <div class="flex justify-between items-center px-3 py-2 border-b border-line text-sm"><b>Notifications</b><button class="text-xs text-brand-700" @click="markRead">Mark all read</button></div>
            <ul class="max-h-80 overflow-y-auto divide-y divide-line text-sm">
              <li v-for="n in notes" :key="n.id" class="px-3 py-2" :class="{ 'bg-brand-50': !n.read_at }">
                <div class="font-medium">{{ n.title }}</div><div class="text-xs text-muted">{{ n.body }} · {{ new Date(n.created_at).toLocaleString() }}</div>
              </li>
              <li v-if="!notes.length" class="px-3 py-4 text-center text-muted">Nothing new.</li>
            </ul>
          </div>
        </div>
        <div class="relative">
          <button class="flex items-center gap-2 rounded-full pl-1 pr-2 sm:pr-3 h-10 hover:bg-gray-100" @click="userOpen = !userOpen">
            <span class="h-8 w-8 rounded-full bg-brand-100 text-brand-700 grid place-items-center text-xs font-bold">{{ initials }}</span>
            <span class="hidden sm:block text-left leading-tight"><span class="block text-sm font-semibold">{{ auth.user?.name }}</span><span class="block text-[11px] text-muted capitalize">{{ auth.user?.role.replace('_', ' ') }}</span></span>
            <Icon name="down" :size="16" class="hidden sm:block text-muted" />
          </button>
          <div v-if="userOpen" class="absolute right-0 mt-2 w-48 bg-white border border-line rounded-md shadow-lg z-50 py-1 text-sm" @click="userOpen = false">
            <div class="px-3 py-1.5 text-xs text-muted capitalize">{{ auth.user?.role.replace('_', ' ') }}</div>
            <RouterLink to="/" class="block px-3 py-2 hover:bg-gray-50">View shop</RouterLink>
            <button class="w-full text-left px-3 py-2 hover:bg-gray-50 text-bad" @click="signOut">Sign out</button>
          </div>
        </div>
      </header>
      <main class="flex-1 p-3 sm:p-5 2xl:p-8 min-w-0">
        <!-- Tabs for the other pages in this area (list pages only; detail pages keep their own back link). -->
        <nav v-if="showTabs" class="-mt-1 mb-4 sm:mb-5 border-b border-line flex gap-1 overflow-x-auto [scrollbar-width:none] [&::-webkit-scrollbar]:hidden" aria-label="Pages in this area">
          <RouterLink v-for="t in current!.tabs" :key="t.to" :to="t.to" class="tab" :class="{ on: t === currentTab }">{{ t.label }}</RouterLink>
        </nav>
        <RouterView v-slot="{ Component }"><component :is="Component" :key="route.path" /></RouterView>
      </main>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import Icon from '@/components/Icon.vue'
import { get, post } from '@/lib/api'
import { auth, can, logout } from '@/stores/auth'
import { pulse } from '@/stores/pulse'

const route = useRoute()
const router = useRouter()
const q = ref('')
const mobileOpen = ref(false)
const collapsed = ref(localStorage.getItem('admin.collapsed') === '1')
const bellOpen = ref(false)
const userOpen = ref(false)
const notes = ref<any[]>([])
const shopName = ref('')
const unread = computed(() => pulse.unread_notifications)
const initials = computed(() => (auth.user?.name ?? '?').split(/\s+/).slice(0, 2).map((w) => w.charAt(0).toUpperCase()).join(''))

type Tab = { to: string; label: string; ability: string }
type Section = { key: string; label: string; icon: string; group: number; tabs: Tab[] }
// Thirteen links in five groups; related pages sit as tabs at the top of the page.
const SECTIONS: Section[] = [
  { key: 'dash', group: 1, label: 'Dashboard', icon: 'home', tabs: [{ to: '/admin', label: 'Dashboard', ability: 'reports.operational' }] },
  { key: 'reports', group: 1, label: 'Reports', icon: 'chart', tabs: [{ to: '/admin/reports', label: 'Reports', ability: 'reports.operational' }] },
  { key: 'sales', group: 2, label: 'Sales & receipts', icon: 'receipt', tabs: [
    { to: '/admin/sales', label: 'Sales & receipts', ability: 'pos.sell' },
    { to: '/admin/shifts', label: 'Shifts', ability: 'pos.sell' },
    { to: '/admin/offline', label: 'Offline sales review', ability: 'offline.resolve' },
  ] },
  { key: 'orders', group: 2, label: 'Online orders', icon: 'truck', tabs: [{ to: '/admin/orders', label: 'Online orders', ability: 'orders.manage' }] },
  { key: 'customers', group: 2, label: 'Customers', icon: 'users', tabs: [{ to: '/admin/customers', label: 'Customers', ability: 'customers.view' }] },
  { key: 'products', group: 3, label: 'Products', icon: 'box', tabs: [
    { to: '/admin/products', label: 'Products', ability: 'products.view' },
    { to: '/admin/categories', label: 'Categories & brands', ability: 'products.manage' },
  ] },
  { key: 'stock', group: 3, label: 'Stock', icon: 'layers', tabs: [
    { to: '/admin/insights', label: 'Insights', ability: 'reports.operational' },
    { to: '/admin/inventory', label: 'Stock movements', ability: 'inventory.view' },
    { to: '/admin/inventory/adjust', label: 'Adjust / count', ability: 'inventory.adjust' },
  ] },
  { key: 'ads', group: 3, label: 'Adverts', icon: 'sparkle', tabs: [{ to: '/admin/ads', label: 'Adverts', ability: 'marketing.manage' }] },
  { key: 'buying', group: 4, label: 'Purchases', icon: 'download', tabs: [
    { to: '/admin/receive', label: 'Receive goods', ability: 'purchases.receive' },
    { to: '/admin/purchase-orders', label: 'Purchase orders', ability: 'purchases.receive' },
    { to: '/admin/goods-receipts', label: 'Goods received', ability: 'purchases.receive' },
  ] },
  { key: 'suppliers', group: 4, label: 'Suppliers', icon: 'store', tabs: [{ to: '/admin/suppliers', label: 'Suppliers', ability: 'purchases.receive' }] },
  { key: 'expenses', group: 4, label: 'Expenses', icon: 'wallet', tabs: [{ to: '/admin/expenses', label: 'Expenses', ability: 'expenses.manage' }] },
  { key: 'staff', group: 5, label: 'Staff', icon: 'user', tabs: [
    { to: '/admin/users', label: 'Staff', ability: 'users.manage' },
    { to: '/admin/audit', label: 'Audit log', ability: 'users.manage' },
  ] },
  { key: 'settings', group: 5, label: 'Settings', icon: 'settings', tabs: [{ to: '/admin/settings', label: 'Settings', ability: 'settings.manage' }] },
]
const visibleSections = computed(() =>
  SECTIONS.map((sec) => ({ ...sec, tabs: sec.tabs.filter((t) => can(t.ability)) })).filter((sec) => sec.tabs.length),
)
// The tab for this page: exact match first, then the longest path it lives under (e.g. /admin/sales/12).
const match = (path: string) => {
  let best: { sec: Section; tab: Tab; exact: boolean } | null = null
  for (const sec of visibleSections.value) {
    for (const tab of sec.tabs) {
      const exact = path === tab.to
      const under = tab.to !== '/admin' && path.startsWith(tab.to + '/')
      if ((exact || under) && (!best || exact > best.exact || (exact === best.exact && tab.to.length > best.tab.to.length))) best = { sec, tab, exact }
    }
  }
  return best
}
const found = computed(() => match(route.path))
const current = computed(() => found.value?.sec ?? null)
const currentTab = computed(() => found.value?.tab ?? null)
const showTabs = computed(() => !!current.value && current.value.tabs.length > 1 && !!found.value?.exact)

function toggleCollapse() {
  collapsed.value = !collapsed.value
  localStorage.setItem('admin.collapsed', collapsed.value ? '1' : '0')
}

async function toggleBell() {
  bellOpen.value = !bellOpen.value
  if (bellOpen.value && can('inventory.view')) notes.value = (await get('/admin/notifications')).data
}
async function markRead() {
  await post('/admin/notifications/read')
  pulse.unread_notifications = 0
  notes.value = notes.value.map((n) => ({ ...n, read_at: n.read_at ?? new Date().toISOString() }))
}
async function signOut() {
  await logout()
  router.replace('/login')
}

let timer: number | undefined
const tick = () => document.visibilityState === 'visible' && pulse.refresh()
onMounted(() => {
  pulse.refresh()
  timer = window.setInterval(tick, 30000) // cheap probe; pages re-load their data when it changes
  get('/shop/home').then((h) => (shopName.value = h.settings.shop_name)).catch(() => {})
})
onUnmounted(() => clearInterval(timer))
</script>

<style scoped>
.nav-item {
  position: relative; display: flex; align-items: center; gap: 0.75rem;
  padding: 0.5rem 0.75rem; margin: 1px 0; border-radius: 0.5rem; color: #9ca3af;
}
.nav-item:hover { background: rgb(255 255 255 / 0.05); color: #fff; }
.nav-item.on { background: rgb(201 167 140 / 0.16); color: #fff; font-weight: 600; }
.nav-item.on::before {
  content: ''; position: absolute; left: -0.5rem; top: 0.45rem; bottom: 0.45rem; width: 3px;
  border-radius: 0 3px 3px 0; background: #c9a78c;
}
.nav-item.on :deep(svg) { color: #c9a78c; }
.tab {
  white-space: nowrap; padding: 0.625rem 0.875rem; font-size: 0.875rem; font-weight: 500; color: var(--color-muted);
  border-bottom: 2px solid transparent; margin-bottom: -1px;
}
.tab:hover { color: var(--color-ink); }
.tab.on { color: var(--color-brand-700); border-bottom-color: var(--color-brand-600); font-weight: 600; }
</style>
