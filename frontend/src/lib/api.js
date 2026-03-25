import axios from 'axios'
import { useAuthStore } from '../stores/authStore'

const http = axios.create({
  baseURL: import.meta.env.VITE_API_URL || 'http://localhost:8000/api',
  headers: { 'Accept': 'application/json', 'Content-Type': 'application/json' },
})

// Attach auth token to every request
http.interceptors.request.use((config) => {
  const token = useAuthStore.getState().token
  if (token) config.headers.Authorization = `Bearer ${token}`
  return config
})

// Handle 401 — auto logout
http.interceptors.response.use(
  (res) => res,
  (err) => {
    if (err.response?.status === 401) {
      useAuthStore.getState().logout()
      window.location.href = '/login'
    }
    return Promise.reject(err)
  }
)

export default http

// ── Auth ──────────────────────────────────────────────────────────────────────
export const authApi = {
  login:    (data) => http.post('/auth/login', data),
  register: (data) => http.post('/auth/register', data),
  logout:   ()     => http.delete('/auth/logout'),
  me:       ()     => http.get('/me'),
}

// ── Workspaces ────────────────────────────────────────────────────────────────
export const workspacesApi = {
  list:   ()          => http.get('/workspaces'),
  get:    (id)        => http.get(`/workspaces/${id}`),
  update: (id, data)  => http.put(`/workspaces/${id}`, data),
}

// ── Sites ─────────────────────────────────────────────────────────────────────
export const sitesApi = {
  list:             (wid)          => http.get(`/workspaces/${wid}/sites`),
  create:           (wid, data)    => http.post(`/workspaces/${wid}/sites`, data),
  update:           (wid, id, data)=> http.put(`/workspaces/${wid}/sites/${id}`, data),
  delete:           (wid, id)      => http.delete(`/workspaces/${wid}/sites/${id}`),
  verifyConnection: (wid, id)      => http.post(`/workspaces/${wid}/sites/${id}/verify-connection`),
  generatePluginKey:(wid, id)      => http.post(`/workspaces/${wid}/sites/${id}/plugin/generate-key`),
}

// ── Competitors ───────────────────────────────────────────────────────────────
export const competitorsApi = {
  list:   (wid, siteId)        => http.get(`/workspaces/${wid}/sites/${siteId}/competitors`),
  create: (wid, siteId, data)  => http.post(`/workspaces/${wid}/sites/${siteId}/competitors`, data),
  update: (wid, siteId, id, data) => http.put(`/workspaces/${wid}/sites/${siteId}/competitors/${id}`, data),
  delete: (wid, siteId, id)    => http.delete(`/workspaces/${wid}/sites/${siteId}/competitors/${id}`),
  scan:   (wid, siteId, id, method) => http.post(`/workspaces/${wid}/sites/${siteId}/competitors/${id}/scan`, { method }),
}

// ── Suggestions ───────────────────────────────────────────────────────────────
export const suggestionsApi = {
  list:   (wid, params) => http.get(`/workspaces/${wid}/suggestions`, { params }),
  accept: (wid, id)     => http.post(`/workspaces/${wid}/suggestions/${id}/accept`),
  reject: (wid, id, reason) => http.post(`/workspaces/${wid}/suggestions/${id}/reject`, { reason }),
}

// ── Articles ──────────────────────────────────────────────────────────────────
export const articlesApi = {
  list:    (wid, params)    => http.get(`/workspaces/${wid}/articles`, { params }),
  get:     (wid, id)        => http.get(`/workspaces/${wid}/articles/${id}`),
  create:  (wid, data)      => http.post(`/workspaces/${wid}/articles`, data),
  update:  (wid, id, data)  => http.put(`/workspaces/${wid}/articles/${id}`, data),
  delete:  (wid, id)        => http.delete(`/workspaces/${wid}/articles/${id}`),
  generate:(wid, id, opts)  => http.post(`/workspaces/${wid}/articles/${id}/generate`, opts),
  approve: (wid, id)        => http.post(`/workspaces/${wid}/articles/${id}/approve`),
  publish: (wid, id)        => http.post(`/workspaces/${wid}/articles/${id}/publish`),
  retry:   (wid, id)        => http.post(`/workspaces/${wid}/articles/${id}/retry`),
  publishSocial: (wid, id, opts) => http.post(`/workspaces/${wid}/articles/${id}/social/publish`, opts),
}

// ── Credits ───────────────────────────────────────────────────────────────────
export const creditsApi = {
  balance:      (wid)        => http.get(`/workspaces/${wid}/credits`),
  transactions: (wid, params)=> http.get(`/workspaces/${wid}/credits/transactions`, { params }),
}

// ── Billing ───────────────────────────────────────────────────────────────────
export const billingApi = {
  subscribe:  (wid, plan) => http.post(`/workspaces/${wid}/billing/subscribe`, { plan }),
  buyCredits: (wid, pack) => http.post(`/workspaces/${wid}/billing/buy-credits`, { pack }),
  cancel:     (wid)       => http.post(`/workspaces/${wid}/billing/cancel`),
}

// ── Analytics ─────────────────────────────────────────────────────────────────
export const analyticsApi = {
  overview:   (wid)               => http.get(`/workspaces/${wid}/analytics/overview`),
  tokenUsage: (wid, period = '30d') => http.get(`/workspaces/${wid}/analytics/token-usage`, { params: { period } }),
  articles:   (wid, siteId)       => http.get(`/workspaces/${wid}/analytics/articles`, { params: { site_id: siteId } }),
  spy:        (wid, period = '30d') => http.get(`/workspaces/${wid}/analytics/spy`, { params: { period } }),
}

// ── Social ────────────────────────────────────────────────────────────────────
export const socialApi = {
  accounts:   (wid, siteId) => http.get(`/workspaces/${wid}/social/accounts`, { params: { site_id: siteId } }),
  disconnect: (wid, accountId) => http.delete(`/workspaces/${wid}/social/accounts/${accountId}`),
}

// ── Notifications ─────────────────────────────────────────────────────────────
export const notificationsApi = {
  list:        () => http.get('/notifications'),
  markRead:    (id) => http.patch(`/notifications/${id}/read`),
  markAllRead: () => http.patch('/notifications/read-all'),
}

// ── Unified api namespace (for consistent import: import { api } from '../lib/api') ──
export const api = {
  auth:          authApi,
  workspaces:    workspacesApi,
  sites:         sitesApi,
  competitors:   competitorsApi,
  suggestions:   suggestionsApi,
  articles:      articlesApi,
  credits:       creditsApi,
  billing:       billingApi,
  analytics:     analyticsApi,
  social:        socialApi,
  notifications: notificationsApi,
}
