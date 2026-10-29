import { reactive } from 'vue'
import { get, onUnauthorized, post } from '@/lib/api'

export interface User {
  id: number
  name: string
  email: string
  phone: string | null
  role: 'owner' | 'manager' | 'cashier' | 'stock_manager' | 'customer'
  is_staff: boolean
  abilities: string[]
  avatar_url?: string | null
  uses_google?: boolean
  uses_facebook?: boolean
  needs_location?: boolean
  location?: { lat: number; lng: number; label: string | null; district: string | null; zone: 'metro' | 'outside' | 'unknown'; address: string | null } | null
}

export const auth = reactive({
  user: null as User | null,
  loaded: false,
})

let loading: Promise<User | null> | null = null

export function loadUser(force = false): Promise<User | null> {
  if (auth.loaded && !force) return Promise.resolve(auth.user)
  if (!loading || force) {
    loading = get<{ user: User }>('/auth/me')
      .then((r) => (auth.user = r.user))
      .catch(() => (auth.user = null))
      .finally(() => {
        auth.loaded = true
        loading = null
      }) as Promise<User | null>
  }
  return loading
}

/** Step one of sign-in: does this email or phone already have an account? */
export function identify(loginId: string) {
  return post<{ exists: boolean; type: 'email' | 'phone'; google_only: boolean; social: 'google' | 'facebook' | null }>('/auth/identify', { login: loginId })
}

export async function login(loginId: string, password: string) {
  const r = await post<{ user: User }>('/auth/login', { login: loginId, password })
  auth.user = r.user
  auth.loaded = true
  return r.user
}

export async function loginWithGoogle(credential: string) {
  const r = await post<{ user: User }>('/auth/google', { credential })
  auth.user = r.user
  auth.loaded = true
  return r.user
}

export async function loginWithFacebook(accessToken: string) {
  const r = await post<{ user: User }>('/auth/facebook', { access_token: accessToken })
  auth.user = r.user
  auth.loaded = true
  return r.user
}

export async function register(data: Record<string, string>) {
  const r = await post<{ user: User }>('/auth/register', data)
  auth.user = r.user
  auth.loaded = true
  return r.user
}

export async function logout() {
  try {
    await post('/auth/logout')
  } finally {
    auth.user = null
  }
}

export const can = (ability: string) => !!auth.user?.abilities.includes(ability)

onUnauthorized.handler = () => {
  auth.user = null
}
