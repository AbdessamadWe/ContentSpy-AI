import React from 'react'
import { Outlet, NavLink, useNavigate } from 'react-router-dom'
import { useAuthStore } from '../stores/authStore'
import { authApi } from '../lib/api'

const nav = [
  { to: '/',             label: 'Dashboard',      icon: '📊' },
  { to: '/sites',        label: 'Sites',           icon: '🌐' },
  { to: '/suggestions',  label: 'Suggestions',     icon: '💡' },
  { to: '/articles',     label: 'Articles',        icon: '✍️' },
  { to: '/analytics',    label: 'Analytics',       icon: '📈' },
  { to: '/credits',      label: 'Credits',         icon: '💎' },
  { to: '/billing',      label: 'Billing',         icon: '💳' },
  { to: '/notifications', label: 'Notifications',  icon: '🔔' },
  { to: '/settings',     label: 'Settings',        icon: '⚙️' },
]

export default function AppLayout() {
  const { user, workspace, logout } = useAuthStore()
  const navigate = useNavigate()

  const handleLogout = async () => {
    await authApi.logout().catch(() => {})
    logout()
    navigate('/login')
  }

  return (
    <div className="flex h-screen bg-gray-50">
      {/* Sidebar */}
      <aside className="w-64 bg-gray-900 text-white flex flex-col">
        <div className="px-6 py-5 border-b border-gray-700">
          <div className="text-lg font-bold text-white">ContentSpy AI</div>
          {workspace && (
            <div className="text-xs text-gray-400 mt-1 truncate">{workspace.name}</div>
          )}
        </div>

        <nav className="flex-1 px-3 py-4 space-y-1">
          {nav.map(({ to, label, icon }) => (
            <NavLink
              key={to}
              to={to}
              end={to === '/'}
              className={({ isActive }) =>
                `flex items-center gap-3 px-3 py-2 rounded-md text-sm transition-colors ${
                  isActive
                    ? 'bg-indigo-600 text-white'
                    : 'text-gray-300 hover:bg-gray-700 hover:text-white'
                }`
              }
            >
              <span>{icon}</span>
              {label}
            </NavLink>
          ))}
        </nav>

        {/* Credit balance */}
        {workspace && (
          <div className="px-6 py-3 border-t border-gray-700">
            <div className="text-xs text-gray-400">Credits</div>
            <div className="text-sm font-semibold text-green-400">
              {workspace.credits_balance?.toLocaleString() ?? '—'}
            </div>
          </div>
        )}

        {/* User / Logout */}
        <div className="px-6 py-4 border-t border-gray-700">
          <div className="text-sm text-gray-300 truncate">{user?.name}</div>
          <button
            onClick={handleLogout}
            className="mt-2 text-xs text-gray-500 hover:text-red-400 transition-colors"
          >
            Log out
          </button>
        </div>
      </aside>

      {/* Main */}
      <main className="flex-1 overflow-auto">
        <div className="max-w-7xl mx-auto px-6 py-8">
          <Outlet />
        </div>
      </main>
    </div>
  )
}
