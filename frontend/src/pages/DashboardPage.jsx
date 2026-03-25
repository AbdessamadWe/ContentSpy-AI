import React from 'react'
import { useQuery } from '@tanstack/react-query'
import { useAuthStore } from '../stores/authStore'
import { creditsApi, suggestionsApi, articlesApi } from '../lib/api'

function StatCard({ label, value, color = 'indigo' }) {
  const colors = {
    indigo: 'bg-indigo-50 text-indigo-700',
    green:  'bg-green-50 text-green-700',
    yellow: 'bg-yellow-50 text-yellow-700',
    red:    'bg-red-50 text-red-700',
  }
  return (
    <div className="bg-white rounded-xl border border-gray-200 p-6">
      <div className="text-sm text-gray-500 mb-1">{label}</div>
      <div className={`text-2xl font-bold ${colors[color]?.split(' ')[1] || 'text-gray-900'}`}>
        {value ?? '—'}
      </div>
    </div>
  )
}

export default function DashboardPage() {
  const workspace = useAuthStore(s => s.workspace)
  const wid = workspace?.id

  const { data: credits } = useQuery({
    queryKey: ['credits', wid],
    queryFn: () => creditsApi(wid).balance().then(r => r.data),
    enabled: !!wid,
  })

  const { data: suggestions } = useQuery({
    queryKey: ['suggestions', wid, 'pending'],
    queryFn: () => suggestionsApi(wid).list({ status: 'pending', per_page: 5 }).then(r => r.data),
    enabled: !!wid,
  })

  const { data: articles } = useQuery({
    queryKey: ['articles', wid, 'recent'],
    queryFn: () => articlesApi(wid).list({ per_page: 5 }).then(r => r.data),
    enabled: !!wid,
  })

  return (
    <div>
      <h1 className="text-2xl font-bold text-gray-900 mb-6">Dashboard</h1>

      {/* Stats */}
      <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
        <StatCard label="Available Credits" value={credits?.available?.toLocaleString()} color="green" />
        <StatCard label="Pending Suggestions" value={suggestions?.total} color="yellow" />
        <StatCard label="Articles (Total)" value={articles?.total} color="indigo" />
        <StatCard label="Plan" value={workspace?.plan?.toUpperCase()} color="indigo" />
      </div>

      {/* Recent Suggestions */}
      <div className="bg-white rounded-xl border border-gray-200 p-6 mb-6">
        <h2 className="text-lg font-semibold text-gray-900 mb-4">Recent Suggestions</h2>
        {suggestions?.data?.length ? (
          <ul className="space-y-3">
            {suggestions.data.map(s => (
              <li key={s.id} className="flex items-start justify-between gap-4">
                <div>
                  <div className="text-sm font-medium text-gray-900">{s.suggested_title}</div>
                  <div className="text-xs text-gray-400 mt-0.5">Score: {s.opportunity_score} · {s.status}</div>
                </div>
                <span className="text-xs bg-yellow-100 text-yellow-700 px-2 py-0.5 rounded-full whitespace-nowrap">
                  {s.status}
                </span>
              </li>
            ))}
          </ul>
        ) : (
          <p className="text-sm text-gray-400">No pending suggestions.</p>
        )}
      </div>

      {/* Recent Articles */}
      <div className="bg-white rounded-xl border border-gray-200 p-6">
        <h2 className="text-lg font-semibold text-gray-900 mb-4">Recent Articles</h2>
        {articles?.data?.length ? (
          <ul className="space-y-3">
            {articles.data.map(a => (
              <li key={a.id} className="flex items-center justify-between gap-4">
                <div className="text-sm font-medium text-gray-900 truncate">{a.title}</div>
                <span className={`text-xs px-2 py-0.5 rounded-full whitespace-nowrap ${
                  a.generation_status === 'ready' ? 'bg-green-100 text-green-700' :
                  a.generation_status === 'failed' ? 'bg-red-100 text-red-700' :
                  'bg-blue-100 text-blue-700'
                }`}>
                  {a.generation_status}
                </span>
              </li>
            ))}
          </ul>
        ) : (
          <p className="text-sm text-gray-400">No articles yet.</p>
        )}
      </div>
    </div>
  )
}
