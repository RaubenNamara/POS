/**
 * Minimal promise wrapper over IndexedDB (persistent across restarts; no library).
 * Stores: kv (product snapshot, settings, last sync) and queue (offline sales by client_uuid).
 */
const DB_NAME = 'duka-pos'
const VERSION = 1
let dbp: Promise<IDBDatabase> | null = null

function open(): Promise<IDBDatabase> {
  dbp ??= new Promise((resolve, reject) => {
    const req = indexedDB.open(DB_NAME, VERSION)
    req.onupgradeneeded = () => {
      const db = req.result
      if (!db.objectStoreNames.contains('kv')) db.createObjectStore('kv')
      if (!db.objectStoreNames.contains('queue')) db.createObjectStore('queue', { keyPath: 'client_uuid' })
    }
    req.onsuccess = () => resolve(req.result)
    req.onerror = () => reject(req.error)
  })
  return dbp
}

function tx<T>(store: string, mode: IDBTransactionMode, fn: (s: IDBObjectStore) => IDBRequest<T>): Promise<T> {
  return open().then(
    (db) =>
      new Promise<T>((resolve, reject) => {
        const t = db.transaction(store, mode)
        const r = fn(t.objectStore(store))
        t.oncomplete = () => resolve(r.result)
        t.onerror = () => reject(t.error)
        t.onabort = () => reject(t.error)
      }),
  )
}

export const kvGet = <T>(key: string) => tx<T | undefined>('kv', 'readonly', (s) => s.get(key) as IDBRequest<T | undefined>)
export const kvSet = (key: string, value: unknown) => tx('kv', 'readwrite', (s) => s.put(value, key))
export const queueAll = <T>() => tx<T[]>('queue', 'readonly', (s) => s.getAll() as IDBRequest<T[]>)
export const queuePut = (item: unknown) => tx('queue', 'readwrite', (s) => s.put(item))
export const queueDelete = (key: string) => tx('queue', 'readwrite', (s) => s.delete(key))

/** Ask the browser not to evict our data under storage pressure (best effort). */
export function requestPersistence() {
  navigator.storage?.persist?.().catch(() => {})
}
