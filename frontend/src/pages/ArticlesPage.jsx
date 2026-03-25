import React from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { useNavigate } from 'react-router-dom'
import { useAuthStore } from '../stores/authStore'
import { articlesApi } from '../lib/api'

const STATUS_COLORS = {
  pending:  'bg-gray-100 text-gray-600',
  outline:  'bg-blue-100 text-blue-600',
  writing:  'bg-blue-100 text-blue-700',
  seo:      'bg-purple-100 text-purple-700',
  images:   'bg-purple-100 text-purple-600',
  review:   'bg-yellow-100 text-yellow-700',
  ready:    'bg-green-100 text-green-700',
  failed:   'bg-red-100 text-red-700',
}

export default function ArticlesPage() {
  const workspace = useAuthStore(s => s.workspace)
  const wid = workspace?.id
  const navigate = useNavigate()
  const qc = useQueryClient()

  const { data, isLoading } = useQuery({
    queryKey: ['articles', wid],
    queryFn: () => articlesApi(wid).list().then(r => r.data),
    enabled: !!wid,
  })

  const generateMutation = useMutation({
    mutationFn: (id) => articlesApi(wid).generate(id),
    onSuccess: () => qc.invalidateQueries(['articles', wid]),
  })

  return (
    <div>
      <h1 className="text-2xl font-bold text-gray-900 mb-6">Articles</h1>

      {isLoading ? (
        <div className="text-gray-400 text-sm">Loading articles…</div>
      ) : data?.data?.length ? (
        <div className="bg-white rounded-xl border border-gray-200 overflow-hidden">
          <table className="w-full text-sm">
            <thead className="bg-gray-50 border-b border-gray-200">
              <tr>
                <th className="text-left px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">Title</th>
                <th className="text-left px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                <th className="text-left px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">Words</th>
                <th className="text-left px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">Cost</th>
                <th className="px-6 py-3"></th>
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-100">
              {data.data.map(a => (
                <tr key={a.id} className="hover:bg-gray-50 transition-colors">
                  <td className="px-6 py-4">
                    <div className="font-medium text-gray-900 truncate max-w-xs">{a.title}</div>
                  </td>
                  <td className="px-6 py-4">
                    <span className={`text-xs px-2 py-0.5 rounded-full font-medium ${STATUS_COLORS[a.generation_status] || 'bg-gray-100 text-gray-600'}`}>
                      {a.generation_status}
                    </span>
                  </td>
                  <td className="px-6 py-4 text-gray-500">{a.word_count?.toLocaleString() || '—'}</td>
                  <td className="px-6 py-4 text-gray-500">${a.total_cost_usd || '0.00'}</td>
                  <td className="px-6 py-4 text-right space-x-2">
                    {(a.generation_status === 'pending' || a.generation_status === 'failed') && (
                      <button
                        onClick={() => generateMutation.mutate(a.id)}
                        className="text-xs bg-indigo-600 hover:bg-indigo-700 text-white px-3 py-1 rounded-lg transition-colors"
                      >
                        Generate
                      </button>
                    )}
                    <button
                      onClick={() => navigate(`/articles/${a.id}`)}
                      className="text-xs bg-gray-100 hover:bg-gray-200 text-gray-600 px-3 py-1 rounded-lg transition-colors"
                    >
                      View
                    </button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      ) : (
        <div className="text-center py-12 text-gray-400">
          <p>No articles yet. Accept a suggestion to get started.</p>
        </div>
      )}
    </div>
  )
}
