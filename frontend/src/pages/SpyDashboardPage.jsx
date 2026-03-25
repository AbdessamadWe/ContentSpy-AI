import { useState } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import api from '../lib/api';

export default function SpyDashboard() {
  const queryClient = useQueryClient();
  const [selectedSite, setSelectedSite] = useState(null);
  const [showAddCompetitor, setShowAddCompetitor] = useState(false);

  const { data: sites } = useQuery({
    queryKey: ['sites'],
    queryFn: () => api.get('/sites').then(r => r.data.sites || []),
  });

  const { data: competitors, isLoading } = useQuery({
    queryKey: ['competitors', selectedSite],
    queryFn: () => {
      const url = selectedSite ? `/competitors?site_id=${selectedSite}` : '/competitors';
      return api.get(url).then(r => r.data.competitors || []);
    },
    enabled: true,
  });

  const scanMutation = useMutation({
    mutationFn: (id) => api.post(`/competitors/${id}/scan`),
    onSuccess: () => {
      queryClient.invalidateQueries(['competitors']);
    },
  });

  const toggleAutoSpyMutation = useMutation({
    mutationFn: ({ id, autoSpy }) => api.patch(`/competitors/${id}`, { auto_spy: autoSpy }),
    onSuccess: () => {
      queryClient.invalidateQueries(['competitors']);
    },
  });

  const getMethodBadge = (method) => {
    const badges = {
      rss: { bg: 'bg-blue-100', text: 'text-blue-700', label: 'RSS' },
      html_scraping: { bg: 'bg-purple-100', text: 'text-purple-700', label: 'HTML' },
      sitemap: { bg: 'bg-green-100', text: 'text-green-700', label: 'Sitemap' },
      google_news: { bg: 'bg-yellow-100', text: 'text-yellow-700', label: 'Google News' },
      social_signal: { bg: 'bg-pink-100', text: 'text-pink-700', label: 'Social' },
      keyword_gap: { bg: 'bg-indigo-100', text: 'text-indigo-700', label: 'Keyword Gap' },
      serp: { bg: 'bg-orange-100', text: 'text-orange-700', label: 'SERP' },
    };
    return badges[method] || { bg: 'bg-gray-100', text: 'text-gray-700', label: method };
  };

  const getScoreColor = (score) => {
    if (score >= 80) return 'text-green-600';
    if (score >= 60) return 'text-yellow-600';
    return 'text-red-600';
  };

  return (
    <div className="p-6">
      <div className="flex justify-between items-center mb-6">
        <h1 className="text-2xl font-bold">Spy Dashboard</h1>
        <div className="flex gap-4">
          <select
            value={selectedSite || ''}
            onChange={(e) => setSelectedSite(e.target.value || null)}
            className="border rounded-lg px-3 py-2"
          >
            <option value="">All Sites</option>
            {sites?.map((site) => (
              <option key={site.id} value={site.id}>{site.name}</option>
            ))}
          </select>
          <button
            onClick={() => setShowAddCompetitor(true)}
            className="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700"
          >
            Add Competitor
          </button>
        </div>
      </div>

      {/* Stats Cards */}
      <div className="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
        <div className="bg-white rounded-lg shadow p-4">
          <div className="text-2xl font-bold">{competitors?.length || 0}</div>
          <div className="text-gray-500">Total Competitors</div>
        </div>
        <div className="bg-white rounded-lg shadow p-4">
          <div className="text-2xl font-bold">
            {competitors?.filter(c => c.auto_spy).length || 0}
          </div>
          <div className="text-gray-500">Auto-Spy Enabled</div>
        </div>
        <div className="bg-white rounded-lg shadow p-4">
          <div className="text-2xl font-bold">
            {competitors?.reduce((sum, c) => sum + (c.total_articles_detected || 0), 0) || 0}
          </div>
          <div className="text-gray-500">Articles Detected</div>
        </div>
        <div className="bg-white rounded-lg shadow p-4">
          <div className="text-2xl font-bold">
            {competitors?.reduce((sum, c) => sum + (c.detections_count || 0), 0) || 0}
          </div>
          <div className="text-gray-500">Recent Detections</div>
        </div>
      </div>

      {/* Competitors List */}
      <div className="bg-white rounded-lg shadow overflow-hidden">
        <table className="w-full">
          <thead className="bg-gray-50">
            <tr>
              <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Competitor</th>
              <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Methods</th>
              <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Auto-Spy</th>
              <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Last Scan</th>
              <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Detections</th>
              <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Actions</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-gray-200">
            {isLoading ? (
              <tr>
                <td colSpan={6} className="px-4 py-8 text-center text-gray-500">Loading...</td>
              </tr>
            ) : competitors?.length === 0 ? (
              <tr>
                <td colSpan={6} className="px-4 py-8 text-center text-gray-500">
                  No competitors found. Add your first competitor to start spying!
                </td>
              </tr>
            ) : (
              competitors?.map((competitor) => (
                <tr key={competitor.id} className="hover:bg-gray-50">
                  <td className="px-4 py-3">
                    <div className="font-medium">{competitor.name}</div>
                    <div className="text-sm text-gray-500">{competitor.domain}</div>
                  </td>
                  <td className="px-4 py-3">
                    <div className="flex flex-wrap gap-1">
                      {competitor.active_methods?.map((method) => {
                        const badge = getMethodBadge(method);
                        return (
                          <span
                            key={method}
                            className={`px-2 py-1 text-xs rounded-full ${badge.bg} ${badge.text}`}
                          >
                            {badge.label}
                          </span>
                        );
                      })}
                    </div>
                  </td>
                  <td className="px-4 py-3">
                    <button
                      onClick={() => toggleAutoSpyMutation.mutate({ id: competitor.id, autoSpy: !competitor.auto_spy })}
                      className={`px-3 py-1 text-xs rounded-full ${competitor.auto_spy ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-700'}`}
                    >
                      {competitor.auto_spy ? 'Active' : 'Disabled'}
                    </button>
                  </td>
                  <td className="px-4 py-3 text-sm text-gray-500">
                    {competitor.last_scanned_at ? new Date(competitor.last_scanned_at).toLocaleDateString() : 'Never'}
                  </td>
                  <td className="px-4 py-3">
                    <span className="font-medium">{competitor.detections_count || 0}</span>
                  </td>
                  <td className="px-4 py-3">
                    <button
                      onClick={() => scanMutation.mutate(competitor.id)}
                      disabled={scanMutation.isPending}
                      className="px-3 py-1 text-xs bg-blue-100 text-blue-700 rounded-full hover:bg-blue-200"
                    >
                      {scanMutation.isPending ? 'Scanning...' : 'Scan Now'}
                    </button>
                  </td>
                </tr>
              ))
            )}
          </tbody>
        </table>
      </div>

      {/* Add Competitor Modal */}
      {showAddCompetitor && (
        <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50">
          <div className="bg-white rounded-lg p-6 w-full max-w-lg">
            <h2 className="text-xl font-bold mb-4">Add Competitor</h2>
            <form className="space-y-4">
              <div>
                <label className="block text-sm font-medium mb-2">Site</label>
                <select required className="w-full border rounded-lg px-3 py-2">
                  <option value="">Select site...</option>
                  {sites?.map((site) => (
                    <option key={site.id} value={site.id}>{site.name}</option>
                  ))}
                </select>
              </div>
              <div>
                <label className="block text-sm font-medium mb-2">Name</label>
                <input required type="text" className="w-full border rounded-lg px-3 py-2" placeholder="Competitor name" />
              </div>
              <div>
                <label className="block text-sm font-medium mb-2">Domain</label>
                <input required type="url" className="w-full border rounded-lg px-3 py-2" placeholder="https://competitor.com" />
              </div>
              <div>
                <label className="block text-sm font-medium mb-2">RSS URL (optional)</label>
                <input type="url" className="w-full border rounded-lg px-3 py-2" placeholder="https://competitor.com/feed" />
              </div>
              <div>
                <label className="block text-sm font-medium mb-2">Sitemap URL (optional)</label>
                <input type="url" className="w-full border rounded-lg px-3 py-2" placeholder="https://competitor.com/sitemap.xml" />
              </div>
              <div className="flex gap-3">
                <button type="submit" className="flex-1 px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
                  Add Competitor
                </button>
                <button type="button" onClick={() => setShowAddCompetitor(false)} className="px-4 py-2 border rounded-lg hover:bg-gray-50">
                  Cancel
                </button>
              </div>
            </form>
          </div>
        </div>
      )}
    </div>
  );
}