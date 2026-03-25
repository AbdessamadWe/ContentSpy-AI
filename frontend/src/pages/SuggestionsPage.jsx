import React, { useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { useAuthStore } from '../stores/authStore'
import { suggestionsApi } from '../lib/api'

export default function SuggestionsPage() {
  const workspace = useAuthStore(s => s.workspace)
  const wid = workspace?.id
  const qc = useQueryClient()
  const [filter, setFilter] = useState('pending')

  const { data, isLoading } = useQuery({
    queryKey: ['suggestions', wid, filter],
    queryFn: () => suggestionsApi(wid).list({ status: filter }).then(r => r.data),
    enabled: !!wid,
  })

  const acceptMutation = useMutation({
    mutationFn: (id) => suggestionsApi(wid).accept(id),
    onSuccess: () => qc.invalidateQueries(['suggestions', wid]),
  })

  const rejectMutation = useMutation({
    mutationFn: ({ id, reason }) => suggestionsApi(wid).reject(id, reason),
    onSuccess: () => qc.invalidateQueries(['suggestions', wid]),
  })

  return (
    <div>
      <div className="flex items-center justify-between mb-6">
        <h1 className="text-2xl font-bold text-gray-900">Content Suggestions</h1>
        <select
          value={filter}
          onChange={e => setFilter(e.target.value)}
          className="text-sm border border-gray-300 rounded-lg px-3 py-1.5 focus:outline-none focus:ring-2 focus:ring-indigo-500"
        >
          {['pending', 'accepted', 'rejected', 'expired'].map(s => (
            <option key={s} value={s}>{s.charAt(0).toUpperCase() + s.slice(1)}</option>
          ))}
        </select>
      </div>

      {isLoading ? (
        <div className="text-gray-400 text-sm">Loading suggestions…</div>
      ) : data?.data?.length ? (
        <div className="space-y-4">
          {data.data.map(s => (
            <div key={s.id} className="bg-white rounded-xl border border-gray-200 p-6">
              <div className="flex items-start justify-between gap-4">
                <div className="flex-1">
                  <h3 className="font-semibold text-gray-900">{s.suggested_title}</h3>
                  {s.content_angle && <p className="text-sm text-gray-500 mt-1">{s.content_angle}</p>}
                  <div className="flex flex-wrap gap-2 mt-3">
                    {(s.target_keywords || []).slice(0, 5).map(kw => (
                      <span key={kw} className="text-xs bg-indigo-50 text-indigo-600 px-2 py-0.5 rounded-full">{kw}</span>
                    ))}
                  </div>
                  <div className="flex gap-4 mt-3 text-xs text-gray-400">
                    <span>Score: <strong>{s.opportunity_score}</strong></span>
                    <span>~{s.recommended_word_count} words</span>
                    <span>{s.tone}</span>
                  </div>
                </div>
                {s.status === 'pending' && (
                  <div className="flex gap-2">
                    <button
                      onClick={() => acceptMutation.mutate(s.id)}
                      className="text-sm bg-green-600 hover:bg-green-700 text-white px-4 py-1.5 rounded-lg transition-colors"
                    >
                      Accept
                    </button>
                    <button
                      onClick={() => rejectMutation.mutate({ id: s.id, reason: 'Not relevant' })}
                      className="text-sm bg-gray-100 hover:bg-gray-200 text-gray-600 px-4 py-1.5 rounded-lg transition-colors"
                    >
                      Reject
                    </button>
                  </div>
                )}
              </div>
            </div>
          ))}
        </div>
      ) : (
        <div className="text-center py-12 text-gray-400">
          <p>No {filter} suggestions found.</p>
        </div>
      )}
    </div>
  )
}
