import React, { useState, useEffect } from 'react'
import { useAuthStore } from '../stores/authStore'
import { workspacesApi } from '../lib/api'

export default function SettingsPage() {
  const { workspace, setWorkspace } = useAuthStore()
  const [form, setForm] = useState({ name: '', default_tone: '', default_word_count: '' })
  const [saving, setSaving] = useState(false)
  const [saved, setSaved] = useState(false)
  const [error, setError] = useState(null)

  useEffect(() => {
    if (workspace) {
      setForm({
        name: workspace.name || '',
        default_tone: workspace.default_tone || '',
        default_word_count: workspace.default_word_count || '',
      })
    }
  }, [workspace])

  const handleSubmit = async (e) => {
    e.preventDefault()
    setSaving(true)
    setError(null)
    setSaved(false)
    try {
      const res = await workspacesApi.update(workspace.id, form)
      setWorkspace(res.data)
      setSaved(true)
      setTimeout(() => setSaved(false), 3000)
    } catch (err) {
      setError(err.response?.data?.message || 'Failed to save settings.')
    } finally {
      setSaving(false)
    }
  }

  return (
    <div>
      <h1 className="text-2xl font-bold text-gray-900 mb-6">Settings</h1>

      <div className="bg-white rounded-xl border border-gray-200 p-8 max-w-xl">
        <h2 className="text-lg font-semibold text-gray-900 mb-5">Workspace Settings</h2>

        <form onSubmit={handleSubmit} className="space-y-5">
          {error && (
            <div className="bg-red-50 border border-red-200 text-red-700 text-sm px-4 py-3 rounded-lg">{error}</div>
          )}
          {saved && (
            <div className="bg-green-50 border border-green-200 text-green-700 text-sm px-4 py-3 rounded-lg">Settings saved.</div>
          )}

          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">Workspace Name</label>
            <input
              type="text"
              value={form.name}
              onChange={e => setForm(f => ({ ...f, name: e.target.value }))}
              className="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 outline-none"
            />
          </div>

          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">Default Tone</label>
            <select
              value={form.default_tone}
              onChange={e => setForm(f => ({ ...f, default_tone: e.target.value }))}
              className="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 outline-none"
            >
              <option value="">Select tone…</option>
              {['informative', 'conversational', 'professional', 'casual', 'authoritative'].map(t => (
                <option key={t} value={t}>{t.charAt(0).toUpperCase() + t.slice(1)}</option>
              ))}
            </select>
          </div>

          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">Default Word Count</label>
            <input
              type="number"
              value={form.default_word_count}
              onChange={e => setForm(f => ({ ...f, default_word_count: e.target.value }))}
              min="300"
              max="10000"
              step="100"
              className="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 outline-none"
            />
          </div>

          <button
            type="submit"
            disabled={saving}
            className="bg-indigo-600 hover:bg-indigo-700 text-white font-medium py-2.5 px-6 rounded-lg text-sm transition-colors disabled:opacity-50"
          >
            {saving ? 'Saving…' : 'Save settings'}
          </button>
        </form>
      </div>
    </div>
  )
}
