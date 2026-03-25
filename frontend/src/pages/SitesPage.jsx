import React from 'react'
import { useQuery } from '@tanstack/react-query'
import { useNavigate } from 'react-router-dom'
import { useAuthStore } from '../stores/authStore'
import { sitesApi } from '../lib/api'

export default function SitesPage() {
  const workspace = useAuthStore(s => s.workspace)
  const wid = workspace?.id
  const navigate = useNavigate()

  const { data, isLoading } = useQuery({
    queryKey: ['sites', wid],
    queryFn: () => sitesApi(wid).list().then(r => r.data),
    enabled: !!wid,
  })

  return (
    <div>
      <h1 className="text-2xl font-bold text-gray-900 mb-6">Sites</h1>

      {isLoading ? (
        <div className="text-gray-400 text-sm">Loading sites…</div>
      ) : data?.data?.length ? (
        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
          {data.data.map(site => (
            <div
              key={site.id}
              className="bg-white rounded-xl border border-gray-200 p-6 cursor-pointer hover:shadow-md transition-shadow"
              onClick={() => navigate(`/sites/${site.id}/competitors`)}
            >
              <div className="font-semibold text-gray-900 truncate">{site.name}</div>
              <div className="text-sm text-gray-500 mt-1 truncate">{site.domain}</div>
              <div className="mt-3 flex gap-2">
                <span className={`text-xs px-2 py-0.5 rounded-full ${
                  site.wp_status === 'connected' ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-500'
                }`}>
                  {site.wp_status || 'not connected'}
                </span>
              </div>
            </div>
          ))}
        </div>
      ) : (
        <div className="text-center py-12 text-gray-400">
          <p>No sites configured yet.</p>
        </div>
      )}
    </div>
  )
}
