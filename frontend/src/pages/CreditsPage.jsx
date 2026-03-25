import React from 'react'
import { useQuery } from '@tanstack/react-query'
import { useAuthStore } from '../stores/authStore'
import { creditsApi } from '../lib/api'

export default function CreditsPage() {
  const workspace = useAuthStore(s => s.workspace)
  const wid = workspace?.id

  const { data: balance, isLoading: balanceLoading } = useQuery({
    queryKey: ['credits', wid, 'balance'],
    queryFn: () => creditsApi(wid).balance().then(r => r.data),
    enabled: !!wid,
  })

  const { data: transactions, isLoading: txLoading } = useQuery({
    queryKey: ['credits', wid, 'transactions'],
    queryFn: () => creditsApi(wid).transactions().then(r => r.data),
    enabled: !!wid,
  })

  return (
    <div>
      <h1 className="text-2xl font-bold text-gray-900 mb-6">Credits</h1>

      {/* Balance cards */}
      <div className="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-8">
        <div className="bg-white rounded-xl border border-gray-200 p-6">
          <div className="text-sm text-gray-500 mb-1">Available</div>
          <div className="text-3xl font-bold text-green-600">
            {balanceLoading ? '…' : (balance?.available?.toLocaleString() ?? '—')}
          </div>
        </div>
        <div className="bg-white rounded-xl border border-gray-200 p-6">
          <div className="text-sm text-gray-500 mb-1">Used (this month)</div>
          <div className="text-3xl font-bold text-gray-700">
            {balanceLoading ? '…' : (balance?.used_this_month?.toLocaleString() ?? '—')}
          </div>
        </div>
        <div className="bg-white rounded-xl border border-gray-200 p-6">
          <div className="text-sm text-gray-500 mb-1">Total purchased</div>
          <div className="text-3xl font-bold text-gray-700">
            {balanceLoading ? '…' : (balance?.total_purchased?.toLocaleString() ?? '—')}
          </div>
        </div>
      </div>

      {/* Transaction history */}
      <div className="bg-white rounded-xl border border-gray-200 overflow-hidden">
        <div className="px-6 py-4 border-b border-gray-200">
          <h2 className="text-lg font-semibold text-gray-900">Transaction History</h2>
        </div>
        {txLoading ? (
          <div className="px-6 py-8 text-gray-400 text-sm">Loading transactions…</div>
        ) : transactions?.data?.length ? (
          <table className="w-full text-sm">
            <thead className="bg-gray-50 border-b border-gray-200">
              <tr>
                <th className="text-left px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">Date</th>
                <th className="text-left px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">Description</th>
                <th className="text-left px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">Type</th>
                <th className="text-right px-6 py-3 text-xs font-medium text-gray-500 uppercase tracking-wider">Amount</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-100">
              {transactions.data.map(tx => (
                <tr key={tx.id} className="hover:bg-gray-50">
                  <td className="px-6 py-3 text-gray-500">{new Date(tx.created_at).toLocaleDateString()}</td>
                  <td className="px-6 py-3 text-gray-700">{tx.description}</td>
                  <td className="px-6 py-3">
                    <span className={`text-xs px-2 py-0.5 rounded-full ${
                      tx.type === 'credit' ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700'
                    }`}>
                      {tx.type}
                    </span>
                  </td>
                  <td className={`px-6 py-3 text-right font-medium ${
                    tx.type === 'credit' ? 'text-green-600' : 'text-red-600'
                  }`}>
                    {tx.type === 'credit' ? '+' : '-'}{Math.abs(tx.amount).toLocaleString()}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        ) : (
          <div className="px-6 py-8 text-gray-400 text-sm">No transactions yet.</div>
        )}
      </div>
    </div>
  )
}
