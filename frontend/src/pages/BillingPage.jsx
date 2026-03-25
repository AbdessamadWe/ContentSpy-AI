import { useState } from 'react'
import { useMutation, useQuery } from '@tanstack/react-query'
import { api } from '../lib/api'
import { useAuthStore } from '../stores/authStore'

const PLANS = [
  {
    key: 'starter',
    name: 'Starter',
    price: '$29/mo',
    credits: 500,
    features: ['3 sites', '5 competitors/site', '500 credits/month', 'RSS + Sitemap spy', 'Basic analytics'],
  },
  {
    key: 'pro',
    name: 'Pro',
    price: '$79/mo',
    credits: 2000,
    features: ['15 sites', '20 competitors/site', '2,000 credits/month', 'All 7 spy methods', 'Auto-pilot mode', 'Social publishing'],
    popular: true,
  },
  {
    key: 'agency',
    name: 'Agency',
    price: '$199/mo',
    credits: 6000,
    features: ['Unlimited sites', 'Unlimited competitors', '6,000 credits/month', 'White-label', 'Priority support', 'API access'],
  },
]

const CREDIT_PACKS = [
  { key: 'pack_500',  name: '500 Credits',  price: '$9',  credits: 500 },
  { key: 'pack_1500', name: '1,500 Credits', price: '$24', credits: 1500 },
  { key: 'pack_5000', name: '5,000 Credits', price: '$69', credits: 5000 },
]

export default function BillingPage() {
  const workspace = useAuthStore(s => s.workspace)
  const [activeTab, setActiveTab] = useState('plans')

  const subscribeMutation = useMutation({
    mutationFn: (plan) => api.billing.subscribe(workspace.id, plan),
    onSuccess: (data) => {
      window.location.href = data.data.checkout_url
    },
  })

  const buyCredits = useMutation({
    mutationFn: (pack) => api.billing.buyCredits(workspace.id, pack),
    onSuccess: (data) => {
      window.location.href = data.data.checkout_url
    },
  })

  const cancelMutation = useMutation({
    mutationFn: () => api.billing.cancel(workspace.id),
  })

  const currentPlan = workspace?.plan ?? 'starter'

  return (
    <div className="space-y-8">
      <div>
        <h1 className="text-2xl font-bold text-white">Billing</h1>
        <p className="text-gray-400 mt-1">Manage your plan and credits</p>
      </div>

      {/* Current plan badge */}
      <div className="bg-gray-800 rounded-xl p-5 flex items-center justify-between">
        <div>
          <p className="text-gray-400 text-sm">Current plan</p>
          <p className="text-white font-bold text-xl capitalize mt-0.5">{currentPlan}</p>
        </div>
        <div className="text-right">
          <p className="text-gray-400 text-sm">Credits available</p>
          <p className="text-white font-bold text-xl mt-0.5">
            {workspace?.credits_balance ?? 0}
            <span className="text-gray-400 text-sm font-normal ml-1">
              ({workspace?.credits_reserved ?? 0} reserved)
            </span>
          </p>
        </div>
      </div>

      {/* Tabs */}
      <div className="flex gap-2 border-b border-gray-700">
        {['plans', 'credits'].map(tab => (
          <button
            key={tab}
            onClick={() => setActiveTab(tab)}
            className={`pb-3 px-4 text-sm font-medium capitalize transition-colors ${
              activeTab === tab
                ? 'text-blue-400 border-b-2 border-blue-400'
                : 'text-gray-400 hover:text-gray-200'
            }`}
          >
            {tab === 'plans' ? 'Subscription Plans' : 'Credit Packs'}
          </button>
        ))}
      </div>

      {/* Plans */}
      {activeTab === 'plans' && (
        <div className="grid md:grid-cols-3 gap-6">
          {PLANS.map(plan => (
            <div
              key={plan.key}
              className={`bg-gray-800 rounded-xl p-6 flex flex-col ${
                plan.popular ? 'ring-2 ring-blue-500' : ''
              }`}
            >
              {plan.popular && (
                <span className="bg-blue-500 text-white text-xs font-bold px-3 py-1 rounded-full self-start mb-3">
                  MOST POPULAR
                </span>
              )}
              <h3 className="text-white font-bold text-xl">{plan.name}</h3>
              <p className="text-3xl font-bold text-white mt-2">{plan.price}</p>
              <ul className="mt-4 space-y-2 flex-1">
                {plan.features.map(f => (
                  <li key={f} className="text-gray-300 text-sm flex items-start gap-2">
                    <span className="text-green-400 mt-0.5">✓</span> {f}
                  </li>
                ))}
              </ul>
              <button
                onClick={() => subscribeMutation.mutate(plan.key)}
                disabled={currentPlan === plan.key || subscribeMutation.isPending}
                className={`mt-6 w-full py-2.5 rounded-lg font-semibold text-sm transition-colors ${
                  currentPlan === plan.key
                    ? 'bg-gray-600 text-gray-400 cursor-not-allowed'
                    : 'bg-blue-600 hover:bg-blue-700 text-white'
                }`}
              >
                {currentPlan === plan.key ? 'Current Plan' : `Switch to ${plan.name}`}
              </button>
            </div>
          ))}
        </div>
      )}

      {/* Credit Packs */}
      {activeTab === 'credits' && (
        <div className="grid sm:grid-cols-3 gap-6">
          {CREDIT_PACKS.map(pack => (
            <div key={pack.key} className="bg-gray-800 rounded-xl p-6 flex flex-col">
              <h3 className="text-white font-bold text-lg">{pack.name}</h3>
              <p className="text-4xl font-bold text-white mt-2">{pack.price}</p>
              <p className="text-gray-400 text-sm mt-1">One-time purchase</p>
              <button
                onClick={() => buyCredits.mutate(pack.key)}
                disabled={buyCredits.isPending}
                className="mt-6 w-full py-2.5 rounded-lg bg-green-600 hover:bg-green-700 text-white font-semibold text-sm transition-colors disabled:opacity-50"
              >
                {buyCredits.isPending ? 'Redirecting…' : `Buy ${pack.name}`}
              </button>
            </div>
          ))}
        </div>
      )}

      {/* Cancel */}
      {currentPlan !== 'starter' && (
        <div className="bg-gray-800 rounded-xl p-5 border border-red-900">
          <h3 className="text-red-400 font-semibold">Cancel Subscription</h3>
          <p className="text-gray-400 text-sm mt-1">Your plan will remain active until the end of the billing period.</p>
          <button
            onClick={() => {
              if (confirm('Cancel your subscription? Your plan will remain active until end of billing period.')) {
                cancelMutation.mutate()
              }
            }}
            className="mt-3 px-4 py-2 rounded-lg bg-red-900 hover:bg-red-800 text-red-300 text-sm font-medium transition-colors"
          >
            Cancel Subscription
          </button>
        </div>
      )}
    </div>
  )
}
