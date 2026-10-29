/**
 * Google scripts are loaded only on the pages that need them (sign-in, location), never on the
 * storefront's first paint.
 */
const loaded = new Map<string, Promise<void>>()

export function loadScript(src: string): Promise<void> {
  let p = loaded.get(src)
  if (!p) {
    p = new Promise<void>((resolve, reject) => {
      const s = document.createElement('script')
      s.src = src
      s.async = true
      s.onload = () => resolve()
      s.onerror = () => {
        loaded.delete(src)
        reject(new Error('Could not load Google services. Check your connection.'))
      }
      document.head.appendChild(s)
    })
    loaded.set(src, p)
  }
  return p
}

declare global {
  interface Window {
    google?: any
    __mapsReady?: () => void
  }
}

/** Sign in with Google (Identity Services): renders Google's own button into `el`. */
export async function renderGoogleButton(el: HTMLElement, clientId: string, onCredential: (credential: string) => void, icon = false) {
  await loadScript('https://accounts.google.com/gsi/client')
  window.google.accounts.id.initialize({ client_id: clientId, callback: (r: { credential: string }) => onCredential(r.credential), ux_mode: 'popup' })
  // `icon`: just the round "G", as under "Or log in with" on the sign-in page.
  window.google.accounts.id.renderButton(el, icon
    ? { type: 'icon', theme: 'outline', size: 'large', shape: 'circle' }
    : { theme: 'outline', size: 'large', text: 'continue_with', shape: 'rectangular', width: Math.min(el.clientWidth || 320, 400) })
}

/** Google Maps JavaScript API (browser key, restricted to this site's domain in Google Cloud). */
export function loadMaps(key: string): Promise<any> {
  if (window.google?.maps?.Map) return Promise.resolve(window.google.maps)
  return new Promise((resolve, reject) => {
    window.__mapsReady = () => resolve(window.google.maps)
    loadScript(`https://maps.googleapis.com/maps/api/js?key=${encodeURIComponent(key)}&callback=__mapsReady&loading=async&v=weekly`).catch(reject)
  })
}

/** The phone's / browser's GPS position. */
export function currentPosition(): Promise<{ lat: number; lng: number; accuracy: number }> {
  return new Promise((resolve, reject) => {
    if (!navigator.geolocation) return reject(new Error('This browser cannot share its location. Place the pin on the map instead.'))
    navigator.geolocation.getCurrentPosition(
      (p) => resolve({ lat: p.coords.latitude, lng: p.coords.longitude, accuracy: Math.round(p.coords.accuracy) }),
      (e) => reject(new Error(e.code === e.PERMISSION_DENIED ? 'Location permission was refused. Allow it in your browser, or place the pin on the map.' : 'Could not get your location. Try again or place the pin on the map.')),
      { enableHighAccuracy: true, timeout: 15000, maximumAge: 60000 },
    )
  })
}
