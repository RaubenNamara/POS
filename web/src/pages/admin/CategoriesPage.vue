<template>
  <div class="grid lg:grid-cols-2 gap-4">
    <section class="card p-4">
      <h2 class="font-semibold mb-3">Categories</h2>
      <form class="flex flex-wrap gap-2 mb-3" @submit.prevent="saveCat">
        <input v-model="cat.name" class="input flex-1 min-w-40" placeholder="Category name" required />
        <select v-model="cat.parent_id" class="input w-44"><option :value="null">Top level</option><option v-for="c in tops" :key="c.id" :value="c.id">under {{ c.name }}</option></select>
        <button class="btn-primary">{{ cat.id ? 'Update' : 'Add' }}</button>
        <button v-if="cat.id" type="button" class="btn-ghost" @click="resetCat">Cancel</button>
      </form>
      <ul class="divide-y divide-line text-sm">
        <template v-for="c in tops" :key="c.id">
          <li class="flex items-center gap-2 py-2">
            <b class="flex-1">{{ c.name }}</b><span class="text-xs text-muted">{{ c.products_count }} products</span>
            <span v-if="!c.is_active" class="badge bg-gray-100">hidden</span>
            <button class="text-xs text-brand-700" @click="edit(c)">Edit</button>
            <button class="text-xs text-muted" @click="toggle(c)">{{ c.is_active ? 'Hide' : 'Show' }}</button>
            <button class="text-xs text-bad" @click="remove(c)">Delete</button>
          </li>
          <li v-for="s in children(c.id)" :key="s.id" class="flex items-center gap-2 py-1.5 pl-6">
            <span class="flex-1">{{ s.name }}</span><span class="text-xs text-muted">{{ s.products_count }}</span>
            <span v-if="!s.is_active" class="badge bg-gray-100">hidden</span>
            <button class="text-xs text-brand-700" @click="edit(s)">Edit</button>
            <button class="text-xs text-muted" @click="toggle(s)">{{ s.is_active ? 'Hide' : 'Show' }}</button>
            <button class="text-xs text-bad" @click="remove(s)">Delete</button>
          </li>
        </template>
      </ul>
    </section>
    <section class="card p-4">
      <h2 class="font-semibold mb-3">Brands</h2>
      <form class="flex gap-2 mb-3" @submit.prevent="saveBrand">
        <input v-model="brand.name" class="input" placeholder="Brand name" required />
        <button class="btn-primary">{{ brand.id ? 'Update' : 'Add' }}</button>
      </form>
      <ul class="divide-y divide-line text-sm">
        <li v-for="b in brands" :key="b.id" class="flex gap-2 py-2"><span class="flex-1">{{ b.name }}</span><span class="text-xs text-muted">{{ b.products_count }} products</span><button class="text-xs text-brand-700" @click="Object.assign(brand, { id: b.id, name: b.name })">Edit</button></li>
      </ul>
    </section>
  </div>
</template>

<script setup lang="ts">
import { computed, reactive, ref } from 'vue'
import { del, errorText, get, post, put } from '@/lib/api'
import { toast } from '@/lib/toast'
import { loadLookups } from '@/stores/lookups'

const cats = ref<any[]>([])
const brands = ref<any[]>([])
const cat = reactive<any>({ id: null, name: '', parent_id: null })
const brand = reactive<any>({ id: null, name: '' })
const tops = computed(() => cats.value.filter((c) => !c.parent_id))
const children = (id: number) => cats.value.filter((c) => c.parent_id === id)

async function load() {
  ;[cats.value, brands.value] = await Promise.all([get('/admin/categories'), get('/admin/brands')])
  loadLookups(true)
}
load()

const resetCat = () => Object.assign(cat, { id: null, name: '', parent_id: null })
const edit = (c: any) => Object.assign(cat, { id: c.id, name: c.name, parent_id: c.parent_id })

async function run(fn: () => Promise<any>, ok: string) {
  try {
    await fn()
    toast(ok)
    await load()
  } catch (e) {
    toast(errorText(e), 'error')
  }
}
const saveCat = () => run(() => (cat.id ? put(`/admin/categories/${cat.id}`, cat) : post('/admin/categories', cat)).then(resetCat), 'Category saved')
const toggle = (c: any) => run(() => put(`/admin/categories/${c.id}`, { name: c.name, parent_id: c.parent_id, is_active: !c.is_active }), 'Updated')
const remove = (c: any) => confirm(`Delete ${c.name}?`) && run(() => del(`/admin/categories/${c.id}`), 'Deleted')
const saveBrand = () => run(() => (brand.id ? put(`/admin/brands/${brand.id}`, brand) : post('/admin/brands', brand)).then(() => Object.assign(brand, { id: null, name: '' })), 'Brand saved')
</script>
