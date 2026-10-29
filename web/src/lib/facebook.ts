import { loadScript } from './google'

/**
 * Facebook Login (JavaScript SDK). The SDK is loaded ahead of time so that `facebookLogin()`
 * can open Facebook's window straight from the tap (browsers block pop-ups opened later).
 */
declare global {
  interface Window {
    FB?: any
  }
}

let ready: Promise<void> | null = null

export function prepareFacebook(appId: string): Promise<void> {
  ready ??= loadScript('https://connect.facebook.net/en_US/sdk.js')
    .then(() => window.FB.init({ appId, version: 'v21.0', cookie: false, xfbml: false }))
    .catch((e) => {
      ready = null
      throw new Error('Could not load Facebook. Check your connection.', { cause: e })
    })
  return ready
}

/** Opens Facebook's log-in window; resolves with the access token, or null if cancelled. */
export function facebookLogin(): Promise<string | null> {
  return new Promise((resolve, reject) => {
    if (!window.FB) return reject(new Error('Facebook is still loading. Try again in a moment.'))
    window.FB.login((r: any) => resolve(r?.authResponse?.accessToken ?? null), { scope: 'public_profile,email' })
  })
}
