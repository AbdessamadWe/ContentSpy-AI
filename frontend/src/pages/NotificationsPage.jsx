import { useEffect } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { api } from '../lib/api'
import { useAuthStore } from '../stores/authStore'

const TYPE_ICONS = {
  article_generated: '✅',
  article_failed:    '❌',
  new_suggestions:   '💡',
  low_credits:       '⚠️',
  default:           '🔔',
}

function NotificationItem({ notification, onMarkRead }) {
  const data    = notification.data ?? {}
  const icon    = TYPE_ICONS[notification.type] ?? TYPE_ICONS.default
  const isRead  = !!notification.read_at

  return (
    <div
      className={`flex items-start gap-4 p-4 rounded-xl transition-colors ${
        isRead ? 'bg-gray-800/50' : 'bg-gray-800 border border-gray-700'
      }`}
    >
      <span className="text-2xl mt-0.5">{icon}</span>
      <div className="flex-1 min-w-0">
        <p className={`text-sm ${isRead ? 'text-gray-400' : 'text-white'}`}>
          {data.message ?? 'Notification'}
        </p>
        <p className="text-xs text-gray-500 mt-1">
          {new Date(notification.created_at).toLocaleString()}
        </p>
        {data.action_url && (
          <a
            href={data.action_url}
            className="text-xs text-blue-400 hover:text-blue-300 mt-1 inline-block"
          >
            View →
          </a>
        )}
      </div>
      {!isRead && (
        <button
          onClick={() => onMarkRead(notification.id)}
          className="text-xs text-gray-500 hover:text-gray-300 shrink-0"
        >
          Mark read
        </button>
      )}
    </div>
  )
}

export default function NotificationsPage() {
  const user      = useAuthStore(s => s.user)
  const qc        = useQueryClient()

  const { data, isLoading } = useQuery({
    queryKey: ['notifications'],
    queryFn: () => api.notifications.list(),
    enabled: !!user,
  })

  const markRead = useMutation({
    mutationFn: (id) => api.notifications.markRead(id),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['notifications'] }),
  })

  const markAllRead = useMutation({
    mutationFn: () => api.notifications.markAllRead(),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['notifications'] }),
  })

  const notifications = data?.data ?? []
  const unread = notifications.filter(n => !n.read_at).length

  return (
    <div className="space-y-6 max-w-2xl">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold text-white">Notifications</h1>
          {unread > 0 && (
            <p className="text-gray-400 text-sm mt-1">{unread} unread</p>
          )}
        </div>
        {unread > 0 && (
          <button
            onClick={() => markAllRead.mutate()}
            className="text-sm text-blue-400 hover:text-blue-300"
          >
            Mark all read
          </button>
        )}
      </div>

      {isLoading ? (
        <div className="text-gray-400 text-sm">Loading…</div>
      ) : notifications.length === 0 ? (
        <div className="text-center py-16">
          <p className="text-4xl mb-4">🔔</p>
          <p className="text-gray-400">No notifications yet</p>
        </div>
      ) : (
        <div className="space-y-3">
          {notifications.map(n => (
            <NotificationItem
              key={n.id}
              notification={n}
              onMarkRead={(id) => markRead.mutate(id)}
            />
          ))}
        </div>
      )}
    </div>
  )
}
