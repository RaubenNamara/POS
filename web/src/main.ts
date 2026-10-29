import { createApp } from 'vue'
import App from './App.vue'
import { router } from './router'
import './style.css'

import { initInstall } from './lib/install'

initInstall() // catch the browser's install offer as early as possible
createApp(App).use(router).mount('#app')

// Register the service worker after the page is interactive so it never competes with first paint.
if ('serviceWorker' in navigator && import.meta.env.PROD) {
  addEventListener('load', () => {
    import('virtual:pwa-register').then(({ registerSW }) =>
      registerSW({
        immediate: true,
        // Pick up new releases promptly: when the tab comes back into view and every 30 minutes.
        // With autoUpdate the new version takes over and the page reloads itself.
        onRegisteredSW(_url, reg) {
          if (!reg) return
          const check = () => navigator.onLine && reg.update().catch(() => {})
          document.addEventListener('visibilitychange', () => document.visibilityState === 'visible' && check())
          setInterval(check, 30 * 60 * 1000)
        },
      }),
    )
  })
}
