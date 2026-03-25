import React from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { useParams, useNavigate } from 'react-router-dom'
import { useAuthStore } from '../stores/authStore'
import { articlesApi } from '../lib/api'

export default function ArticleDetailPage() {
  const workspace = useAuthStore(s => s.workspace)
  const wid = workspace?.id
  const { articleId } = useParams()
  const navigate = useNavigate()
  const qc = useQueryClient()

  const { data: article, isLoading } = useQuery({
    queryKey: ['article', wid, articleId],
    queryFn: () => articlesApi(wid).get(articleId).then(r => r.data),
    enabled: !!wid && !!articleId,
  })

  const generateMutation = useMutation({
    mutationFn: () => articlesApi(wid).generate(articleId),
    onSuccess: () => qc.invalidateQueries(['article', wid, articleId]),
  })

  const approveMutation = useMutation({
    mutationFn: () => articlesApi(wid).approve(articleId),
    onSuccess: () => qc.invalidateQueries(['article', wid, articleId]),
  })

  const publishMutation = useMutation({
    mutationFn: () => articlesApi(wid).publish(articleId),
    onSuccess: () => qc.invalidateQueries(['article', wid, articleId]),
  })

  if (isLoading) return <div className="text-gray-400 text-sm">Loading article…</div>
  if (!article) return <div className="text-gray-400 text-sm">Article not found.</div>

  return (
    <div>
      <button
        onClick={() => navigate('/articles')}
        className="text-sm text-indigo-600 hover:underline mb-4 inline-block"
      >
        &larr; Back to articles
      </button>

      <div className="bg-white rounded-xl border border-gray-200 p-8">
        <div className="flex items-start justify-between gap-4 mb-6">
          <h1 className="text-2xl font-bold text-gray-900">{article.title}</h1>
          <div className="flex gap-2 shrink-0">
            {(article.generation_status === 'pending' || article.generation_status === 'failed') && (
              <button
                onClick={() => generateMutation.mutate()}
                disabled={generateMutation.isPending}
                className="text-sm bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded-lg transition-colors disabled:opacity-50"
              >
                Generate
              </button>
            )}
            {article.generation_status === 'review' && (
              <button
                onClick={() => approveMutation.mutate()}
                disabled={approveMutation.isPending}
                className="text-sm bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg transition-colors disabled:opacity-50"
              >
                Approve
              </button>
            )}
            {article.generation_status === 'ready' && (
              <button
                onClick={() => publishMutation.mutate()}
                disabled={publishMutation.isPending}
                className="text-sm bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg transition-colors disabled:opacity-50"
              >
                Publish
              </button>
            )}
          </div>
        </div>

        <div className="flex gap-4 text-xs text-gray-400 mb-6">
          <span>Status: <strong>{article.generation_status}</strong></span>
          {article.word_count && <span>Words: <strong>{article.word_count.toLocaleString()}</strong></span>}
          {article.seo_score && <span>SEO Score: <strong>{article.seo_score}</strong></span>}
          {article.total_cost_usd && <span>Cost: <strong>${article.total_cost_usd}</strong></span>}
        </div>

        {article.body_html ? (
          <div
            className="prose prose-sm max-w-none text-gray-700"
            dangerouslySetInnerHTML={{ __html: article.body_html }}
          />
        ) : (
          <p className="text-gray-400 text-sm">No content generated yet.</p>
        )}
      </div>
    </div>
  )
}
