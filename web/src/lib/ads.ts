export interface Ad {
  id: number
  title: string
  subtitle: string | null
  placement: 'hero' | 'strip'
  image_path: string
  image_mobile_path: string | null
  width: number | null
  height: number | null
  link_url: string | null
  show_text: boolean
}

/** Count a click without slowing the visit (keepalive survives leaving the page). */
export function countAdClick(id: number) {
  try {
    fetch(`/api/shop/ads/${id}/click`, { method: 'POST', keepalive: true, credentials: 'same-origin', headers: { Accept: 'application/json' } }).catch(() => {})
  } catch { /* never block the click */ }
}

export const isExternal = (url: string | null) => !!url && /^https?:\/\//i.test(url)
