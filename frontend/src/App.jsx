import React, { Suspense, lazy } from 'react'
import { BrowserRouter, Routes, Route, Navigate } from 'react-router-dom'
import { useAuthStore } from './stores/authStore'

const LoginPage        = lazy(() => import('./pages/auth/LoginPage'))
const RegisterPage     = lazy(() => import('./pages/auth/RegisterPage'))
const DashboardPage    = lazy(() => import('./pages/DashboardPage'))
const SitesPage        = lazy(() => import('./pages/SitesPage'))
const CompetitorsPage  = lazy(() => import('./pages/CompetitorsPage'))
const SuggestionsPage  = lazy(() => import('./pages/SuggestionsPage'))
const ArticlesPage     = lazy(() => import('./pages/ArticlesPage'))
const ArticleDetailPage = lazy(() => import('./pages/ArticleDetailPage'))
const CreditsPage      = lazy(() => import('./pages/CreditsPage'))
const BillingPage      = lazy(() => import('./pages/BillingPage'))
const AnalyticsPage    = lazy(() => import('./pages/AnalyticsPage'))
const NotificationsPage = lazy(() => import('./pages/NotificationsPage'))
const SettingsPage     = lazy(() => import('./pages/SettingsPage'))
const AppLayout        = lazy(() => import('./layouts/AppLayout'))

function PrivateRoute({ children }) {
  const token = useAuthStore(s => s.token)
  return token ? children : <Navigate to="/login" replace />
}

const Loader = () => (
  <div className="flex items-center justify-center h-screen bg-gray-900 text-gray-500">
    Loading…
  </div>
)

export default function App() {
  return (
    <BrowserRouter>
      <Suspense fallback={<Loader />}>
        <Routes>
          <Route path="/login"    element={<LoginPage />} />
          <Route path="/register" element={<RegisterPage />} />

          <Route path="/" element={<PrivateRoute><AppLayout /></PrivateRoute>}>
            <Route index                                     element={<DashboardPage />} />
            <Route path="sites"                              element={<SitesPage />} />
            <Route path="sites/:siteId/competitors"          element={<CompetitorsPage />} />
            <Route path="suggestions"                        element={<SuggestionsPage />} />
            <Route path="articles"                           element={<ArticlesPage />} />
            <Route path="articles/:articleId"                element={<ArticleDetailPage />} />
            <Route path="credits"                            element={<CreditsPage />} />
            <Route path="billing"                            element={<BillingPage />} />
            <Route path="analytics"                          element={<AnalyticsPage />} />
            <Route path="notifications"                      element={<NotificationsPage />} />
            <Route path="settings"                           element={<SettingsPage />} />
          </Route>

          {/* Stripe success/cancel redirects */}
          <Route path="/billing/success" element={
            <PrivateRoute>
              <div className="flex flex-col items-center justify-center h-screen bg-gray-900 text-white">
                <p className="text-4xl mb-4">🎉</p>
                <h1 className="text-2xl font-bold">Payment Successful!</h1>
                <p className="text-gray-400 mt-2">Your plan has been updated.</p>
                <a href="/" className="mt-6 px-6 py-2 bg-blue-600 rounded-lg hover:bg-blue-700 text-white">
                  Go to Dashboard
                </a>
              </div>
            </PrivateRoute>
          } />
          <Route path="/billing/cancel" element={
            <PrivateRoute>
              <Navigate to="/billing" replace />
            </PrivateRoute>
          } />
        </Routes>
      </Suspense>
    </BrowserRouter>
  )
}
