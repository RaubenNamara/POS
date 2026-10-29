import { createRouter, createWebHistory, type RouteRecordRaw } from 'vue-router'
import { auth, loadUser } from '@/stores/auth'

// Every page is a lazy chunk: shoppers never download admin or POS code and vice versa.
const shop: RouteRecordRaw = {
  path: '/',
  component: () => import('@/layouts/ShopLayout.vue'),
  children: [
    { path: '', name: 'home', component: () => import('@/pages/shop/HomePage.vue') },
    { path: 'search', name: 'search', component: () => import('@/pages/shop/CatalogPage.vue') },
    { path: 'c/:slug', name: 'category', component: () => import('@/pages/shop/CatalogPage.vue') },
    { path: 'p/:slug', name: 'product', component: () => import('@/pages/shop/ProductPage.vue') },
    { path: 'cart', name: 'cart', component: () => import('@/pages/shop/CartPage.vue') },
    { path: 'checkout', name: 'checkout', component: () => import('@/pages/shop/CheckoutPage.vue'), meta: { auth: true } },
    { path: 'login', name: 'login', component: () => import('@/pages/shop/LoginPage.vue'), meta: { focus: true } },
    { path: 'register', name: 'register', component: () => import('@/pages/shop/LoginPage.vue'), meta: { focus: true } },
    { path: 'help/:topic?', name: 'help', component: () => import('@/pages/shop/HelpPage.vue') },
    { path: 'account/wishlist', name: 'wishlist', component: () => import('@/pages/shop/WishlistPage.vue') },
    { path: 'account/location', name: 'location', component: () => import('@/pages/shop/LocationPage.vue'), meta: { auth: true, focus: true } },
    { path: 'payment/return', name: 'payment-return', component: () => import('@/pages/shop/PaymentReturnPage.vue'), meta: { auth: true } },
    { path: 'account/orders', name: 'orders', component: () => import('@/pages/shop/OrdersPage.vue'), meta: { auth: true } },
    { path: 'account/orders/:number', name: 'order', component: () => import('@/pages/shop/OrderPage.vue'), meta: { auth: true } },
  ],
}

const a = (file: string) => () => import(`./pages/admin/${file}.vue`)
const admin: RouteRecordRaw = {
  path: '/admin',
  component: () => import('@/layouts/AdminLayout.vue'),
  meta: { staff: true },
  children: [
    { path: '', name: 'admin', component: a('DashboardPage'), meta: { ability: 'reports.operational', title: 'Dashboard' } },
    { path: 'products', component: a('ProductsPage'), meta: { ability: 'products.view', title: 'Products' } },
    { path: 'products/new', component: a('ProductEditPage'), meta: { ability: 'products.manage', title: 'New product' } },
    { path: 'products/:id', component: a('ProductEditPage'), meta: { ability: 'products.view', title: 'Product' } },
    { path: 'categories', component: a('CategoriesPage'), meta: { ability: 'products.manage', title: 'Categories & brands' } },
    { path: 'insights', component: a('StockInsightsPage'), meta: { ability: 'reports.operational', title: 'Stock insights' } },
    { path: 'inventory', component: a('InventoryPage'), meta: { ability: 'inventory.view', title: 'Stock movements' } },
    { path: 'inventory/adjust', component: a('StockAdjustPage'), meta: { ability: 'inventory.adjust', title: 'Adjust / count stock' } },
    { path: 'suppliers', component: a('SuppliersPage'), meta: { ability: 'purchases.receive', title: 'Suppliers' } },
    { path: 'suppliers/:id', component: a('SupplierPage'), meta: { ability: 'suppliers.manage', title: 'Supplier' } },
    { path: 'purchase-orders', component: a('PurchaseOrdersPage'), meta: { ability: 'purchases.receive', title: 'Purchase orders' } },
    { path: 'purchase-orders/new', component: a('PurchaseOrderEditPage'), meta: { ability: 'purchases.manage', title: 'New purchase order' } },
    { path: 'receive', component: a('ReceiveGoodsPage'), meta: { ability: 'purchases.receive', title: 'Receive goods' } },
    { path: 'goods-receipts', component: a('GoodsReceiptsPage'), meta: { ability: 'purchases.receive', title: 'Goods received' } },
    { path: 'sales', component: a('SalesPage'), meta: { ability: 'pos.sell', title: 'Sales' } },
    { path: 'sales/:id', component: a('SalePage'), meta: { ability: 'pos.sell', title: 'Sale' } },
    { path: 'orders', component: a('SalesPage'), meta: { ability: 'orders.manage', title: 'Online orders' }, props: { online: true } },
    { path: 'ads', component: a('AdsPage'), meta: { ability: 'marketing.manage', title: 'Adverts' } },
    { path: 'customers', component: a('CustomersPage'), meta: { ability: 'customers.view', title: 'Customers' } },
    { path: 'customers/:id', component: a('CustomerPage'), meta: { ability: 'customers.view', title: 'Customer' } },
    { path: 'expenses', component: a('ExpensesPage'), meta: { ability: 'expenses.manage', title: 'Expenses' } },
    { path: 'reports', component: a('ReportsPage'), meta: { ability: 'reports.operational', title: 'Reports' } },
    { path: 'shifts', component: a('ShiftsPage'), meta: { ability: 'pos.sell', title: 'Shifts' } },
    { path: 'users', component: a('UsersPage'), meta: { ability: 'users.manage', title: 'Staff' } },
    { path: 'settings', component: a('SettingsPage'), meta: { ability: 'settings.manage', title: 'Settings' } },
    { path: 'offline', component: a('ConflictsPage'), meta: { ability: 'offline.resolve', title: 'Offline sales review' } },
    { path: 'audit', component: a('AuditPage'), meta: { ability: 'users.manage', title: 'Audit log' } },
  ],
}

const routes: RouteRecordRaw[] = [
  shop,
  admin,
  { path: '/pos', name: 'pos', component: () => import('@/pages/pos/PosPage.vue'), meta: { staff: true, ability: 'pos.sell' } },
  { path: '/print/sale/:id', name: 'print', component: () => import('@/pages/pos/PrintReceiptPage.vue'), meta: { staff: true } },
  { path: '/:pathMatch(.*)*', component: () => import('@/pages/NotFoundPage.vue') },
]

export const router = createRouter({
  history: createWebHistory(),
  routes,
  scrollBehavior: (to, _from, saved) => saved ?? (to.hash ? { el: to.hash, top: 140, behavior: 'smooth' } : { top: 0 }),
})

router.beforeEach(async (to) => {
  const needsUser = to.matched.some((r) => r.meta.auth || r.meta.staff)
  if (!needsUser) {
    if (!auth.loaded) loadUser() // fire and forget: the header shows the account when it arrives
    return true
  }
  // The POS must open offline: trust the last known user and let the API decide later.
  const user = await loadUser()
  if (!user) {
    if (to.name === 'pos' && !navigator.onLine && localStorage.getItem('pos.user')) return true
    return { name: 'login', query: { next: to.fullPath } }
  }
  if (to.matched.some((r) => r.meta.staff) && !user.is_staff) return { name: 'home' }
  const ability = [...to.matched].reverse().find((r) => r.meta.ability)?.meta.ability as string | undefined
  if (ability && !user.abilities.includes(ability)) {
    // Send people to the first area they can actually use.
    if (user.abilities.includes('pos.sell')) return to.name === 'pos' ? { name: 'home' } : { name: 'pos' }
    if (user.abilities.includes('products.view')) return '/admin/products'
    return { name: 'home' }
  }
  return true
})
