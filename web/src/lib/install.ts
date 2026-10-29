import { reactive } from 'vue'

/**
 * "Install the app" (the shop is a Progressive Web App). Chrome/Edge/Android fire
 * `beforeinstallprompt`, which we keep so a button can show the real install dialog.
 * iPhone Safari has no such event: there we explain Share → Add to Home Screen.
 */
export const install = reactive({
  canPrompt: false,
  installed: false,
  isIos: false,
})

let deferred: any = null

export function initInstall() {
  const standalone = matchMedia('(display-mode: standalone)').matches || (navigator as any).standalone === true
  install.installed = standalone
  const ua = navigator.userAgent
  install.isIos = /iphone|ipad|ipod/i.test(ua) || (/Macintosh/.test(ua) && 'ontouchend' in document)
  addEventListener('beforeinstallprompt', (e) => {
    e.preventDefault() // keep it for our own button instead of the browser's mini-bar
    deferred = e
    install.canPrompt = true
  })
  addEventListener('appinstalled', () => {
    install.installed = true
    install.canPrompt = false
    deferred = null
  })
}

/** Shows the browser's install dialog. Returns true when the user accepted. */
export async function promptInstall(): Promise<boolean> {
  if (!deferred) return false
  deferred.prompt()
  const { outcome } = await deferred.userChoice
  deferred = null
  install.canPrompt = false
  return outcome === 'accepted'
}
