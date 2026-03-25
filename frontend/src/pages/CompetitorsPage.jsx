import React from 'react'
import { useQuery } from '@tanstack/react-query'
import { useParams } from 'react-router-dom'
import { useAuthStore } from '../stores/authStore'
import { competitorsApi } from '../lib/api'

export default function CompetitorsPage() {
  const workspace = useAuthStore(s => s.workspace)
  const wid = workspace?.id
  const { siteId } = useParams()

  const { data, isLoading } = useQuery({
    queryKey: ['competitors', wid, siteId],
    queryFn: () => competitorsApi(wid, siteId).list().then(r => r.data),
    enabled: !!wid && !!siteId,
  })

  return (
    <div>
      <h1 className="text-2xl font-bold text-gray-900 mb-6">Competitors</h1>

      {isLoading ? (
        <div className="text-gray-400 text-sm">Loading competitors…</div>
      ) : data?.data?.length ? (
        <div className="space-y-3">
          {data.data.map(c => (
            <div key={c.id} className="bg-white rounded-xl border border-gray-200 p-6">
              <div className="font-semibold text-gray-900">{c.name}</div>
              <div className="text-sm text-gray-500 mt-1">{c.domain}</div>
              <div className="mt-2 text-xs text-gray-400">
                Last scanned: {c.last_scanned_at ? new Date(c.last_scanned_at).toLocaleDateString() : 'Never'}
              </div>
            </div>
          ))}
        </div>
      ) : (
        <div className="text-center py-12 text-gray-400">
          <p>No competitors added yet.</p>
        </div>
      )}
    </div>
  )
}
