import { useQuery } from '@tanstack/react-query'
import { api } from '../lib/api'
import { useAuthStore } from '../stores/authStore'

function StatCard({ label, value, sub, color = 'blue' }) {
  const colors = {
    blue: 'from-blue-500 to-blue-700',
    green: 'from-green-500 to-green-700',
    purple: 'from-purple-500 to-purple-700',
    orange: 'from-orange-500 to-orange-700',
  }
  return (
    <div className={`rounded-xl bg-gradient-to-br ${colors[color]} p-5 text-white`}>
      <p className="text-sm font-medium opacity-80">{label}</p>
      <p className="mt-1 text-3xl font-bold">{value}</p>
      {sub && <p className="mt-1 text-xs opacity-70">{sub}</p>}
    </div>
  )
}

export default function AnalyticsPage() {
  const workspace = useAuthStore(s => s.workspace)

  const { data: overview, isLoading } = useQuery({
    queryKey: ['analytics', 'overview', workspace?.id],
    queryFn: () => api.analytics.overview(workspace.id),
    enabled: !!workspace?.id,
    select: (res) => res.data,
  })

  const { data: tokenUsage } = useQuery({
    queryKey: ['analytics', 'tokens', workspace?.id],
    queryFn: () => api.analytics.tokenUsage(workspace.id, '30d'),
    enabled: !!workspace?.id,
    select: (res) => res.data,
  })

  if (isLoading) return (
    <div className="flex items-center justify-center h-64 text-gray-400">Loading analytics…</div>
  )

  const stats = overview ?? {}
  const articles = stats.articles ?? {}
  const suggestions = stats.suggestions ?? {}
  const spy = stats.spy ?? {}
  const credits = stats.credits ?? {}
  const aiCosts = stats.ai_costs ?? {}

  return (
    <div className="space-y-8">
      <div>
        <h1 className="text-2xl font-bold text-white">Analytics</h1>
        <p className="text-gray-400 mt-1">Platform performance overview</p>
      </div>

      {/* Stats Grid */}
      <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
        <StatCard
          label="Articles Published"
          value={articles.published ?? 0}
          sub={`${articles.total ?? 0} total`}
          color="green"
        />
        <StatCard
          label="New Suggestions"
          value={suggestions.this_week ?? 0}
          sub="this week"
          color="blue"
        />
        <StatCard
          label="Detections Today"
          value={spy.detections_today ?? 0}
          sub={`${spy.detections_this_week ?? 0} this week`}
          color="purple"
        />
        <StatCard
          label="AI Cost (Month)"
          value={`$${Number(aiCosts.this_month_usd ?? 0).toFixed(2)}`}
          sub={`${Number(aiCosts.this_month_tokens ?? 0).toLocaleString()} tokens`}
          color="orange"
        />
      </div>

      {/* Token Usage by Model */}
      {tokenUsage?.data?.byModel?.length > 0 && (
        <div className="bg-gray-800 rounded-xl p-6">
          <h2 className="text-lg font-semibold text-white mb-4">Token Usage by Model (30 days)</h2>
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="text-gray-400 text-left">
                  <th className="pb-3 pr-4">Model</th>
                  <th className="pb-3 pr-4">Provider</th>
                  <th className="pb-3 pr-4 text-right">Tokens</th>
                  <th className="pb-3 pr-4 text-right">Calls</th>
                  <th className="pb-3 text-right">Cost (USD)</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-700">
                {tokenUsage.data.byModel.map((row, i) => (
                  <tr key={i} className="text-gray-300">
                    <td className="py-3 pr-4 font-mono text-sm">{row.model}</td>
                    <td className="py-3 pr-4 capitalize">{row.provider}</td>
                    <td className="py-3 pr-4 text-right">{Number(row.total_tokens).toLocaleString()}</td>
                    <td className="py-3 pr-4 text-right">{row.calls}</td>
                    <td className="py-3 text-right text-green-400">${Number(row.total_cost).toFixed(4)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>
      )}

      {/* Daily Usage Chart placeholder */}
      <div className="bg-gray-800 rounded-xl p-6">
        <h2 className="text-lg font-semibold text-white mb-4">Daily AI Cost (30 days)</h2>
        {tokenUsage?.data?.daily?.length > 0 ? (
          <div className="space-y-2">
            {tokenUsage.data.daily.map((day, i) => {
              const maxCost = Math.max(...tokenUsage.data.daily.map(d => Number(d.cost)))
              const pct = maxCost > 0 ? (Number(day.cost) / maxCost) * 100 : 0
              return (
                <div key={i} className="flex items-center gap-3">
                  <span className="text-gray-400 text-xs w-24 shrink-0">{day.date}</span>
                  <div className="flex-1 bg-gray-700 rounded-full h-2">
                    <div
                      className="bg-blue-500 h-2 rounded-full transition-all"
                      style={{ width: `${pct}%` }}
                    />
                  </div>
                  <span className="text-gray-300 text-xs w-16 text-right">${Number(day.cost).toFixed(4)}</span>
                </div>
              )
            })}
          </div>
        ) : (
          <p className="text-gray-500 text-sm">No data available for this period.</p>
        )}
      </div>

      {/* Pipeline Status */}
      <div className="bg-gray-800 rounded-xl p-6">
        <h2 className="text-lg font-semibold text-white mb-4">Article Pipeline</h2>
        <div className="grid grid-cols-2 sm:grid-cols-4 gap-4">
          {[
            { label: 'In Progress', count: articles.in_pipeline ?? 0, color: 'text-yellow-400' },
            { label: 'Ready',       count: articles.published ?? 0, color: 'text-green-400' },
            { label: 'Failed',      count: articles.failed ?? 0, color: 'text-red-400' },
            { label: 'Total',       count: articles.total ?? 0, color: 'text-blue-400' },
          ].map(s => (
            <div key={s.label} className="text-center">
              <p className={`text-2xl font-bold ${s.color}`}>{s.count}</p>
              <p className="text-gray-400 text-sm mt-1">{s.label}</p>
            </div>
          ))}
        </div>
      </div>
    </div>
  )
}
