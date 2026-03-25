import { useState } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import api from '../lib/api';

export default function SocialAccounts() {
  const queryClient = useQueryClient();
  const [showConnectModal, setShowConnectModal] = useState(false);
  const [selectedPlatform, setSelectedPlatform] = useState(null);
  const [selectedSite, setSelectedSite] = useState(null);

  const { data: accounts, isLoading } = useQuery({
    queryKey: ['social-accounts'],
    queryFn: () => api.get('/social/accounts').then(r => r.data.accounts || []),
  });

  const { data: sites } = useQuery({
    queryKey: ['sites'],
    queryFn: () => api.get('/sites').then(r => r.data.sites || []),
  });

  const disconnectMutation = useMutation({
    mutationFn: (id) => api.delete(`/social/accounts/${id}`),
    onSuccess: () => {
      queryClient.invalidateQueries(['social-accounts']);
    },
  });

  const handleConnect = async (platform) => {
    if (!selectedSite) {
      alert('Please select a site first');
      return;
    }
    
    const response = await api.get(`/social/${platform}/connect?site_id=${selectedSite}`);
    window.location.href = response.data.redirect_url;
  };

  const getStatusBadge = (account) => {
    if (!account.is_active) {
      return <span className="px-2 py-1 text-xs rounded-full bg-gray-100 text-gray-600">Disconnected</span>;
    }
    if (account.token_expires_at && new Date(account.token_expires_at) < new Date(Date.now() + 7 * 24 * 60 * 60 * 1000)) {
      return <span className="px-2 py-1 text-xs rounded-full bg-yellow-100 text-yellow-700">Expiring Soon</span>;
    }
    return <span className="px-2 py-1 text-xs rounded-full bg-green-100 text-green-700">Connected</span>;
  };

  const getPlatformIcon = (platform) => {
    const icons = {
      facebook: '🔵',
      instagram: '📸',
      tiktok: '🎵',
      pinterest: '📌',
    };
    return icons[platform] || '📱';
  };

  const getPlatformName = (platform) => {
    return platform.charAt(0).toUpperCase() + platform.slice(1);
  };

  return (
    <div className="p-6">
      <div className="flex justify-between items-center mb-6">
        <h1 className="text-2xl font-bold">Social Media Accounts</h1>
        <button
          onClick={() => setShowConnectModal(true)}
          className="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700"
        >
          Connect Account
        </button>
      </div>

      {/* Connected Accounts */}
      <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
        {isLoading ? (
          <div className="col-span-full text-center py-8 text-gray-500">Loading...</div>
        ) : accounts?.length === 0 ? (
          <div className="col-span-full text-center py-8 text-gray-500">
            No social accounts connected. Click "Connect Account" to get started.
          </div>
        ) : (
          accounts?.map((account) => (
            <div key={account.id} className="bg-white rounded-lg shadow p-4">
              <div className="flex items-start justify-between">
                <div className="flex items-center gap-3">
                  <span className="text-2xl">{getPlatformIcon(account.platform)}</span>
                  <div>
                    <h3 className="font-semibold">{getPlatformName(account.platform)}</h3>
                    <p className="text-sm text-gray-500">{account.account_name}</p>
                  </div>
                </div>
                {getStatusBadge(account)}
              </div>
              <div className="mt-4 flex gap-2">
                <button
                  onClick={() => disconnectMutation.mutate(account.id)}
                  className="px-3 py-1 text-sm text-red-600 border border-red-600 rounded hover:bg-red-50"
                >
                  Disconnect
                </button>
              </div>
            </div>
          ))
        )}
      </div>

      {/* Connect Modal */}
      {showConnectModal && (
        <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50">
          <div className="bg-white rounded-lg p-6 w-full max-w-md">
            <h2 className="text-xl font-bold mb-4">Connect Social Account</h2>
            
            <div className="mb-4">
              <label className="block text-sm font-medium mb-2">Select Site</label>
              <select
                value={selectedSite || ''}
                onChange={(e) => setSelectedSite(e.target.value)}
                className="w-full border rounded-lg px-3 py-2"
              >
                <option value="">Choose a site...</option>
                {sites?.map((site) => (
                  <option key={site.id} value={site.id}>{site.name}</option>
                ))}
              </select>
            </div>

            <div className="grid grid-cols-2 gap-3 mb-4">
              {['facebook', 'instagram', 'tiktok', 'pinterest'].map((platform) => (
                <button
                  key={platform}
                  onClick={() => handleConnect(platform)}
                  disabled={!selectedSite}
                  className="p-4 border rounded-lg hover:bg-gray-50 disabled:opacity-50 disabled:cursor-not-allowed"
                >
                  <span className="text-2xl block mb-2">{getPlatformIcon(platform)}</span>
                  <span className="font-medium">{getPlatformName(platform)}</span>
                </button>
              ))}
            </div>

            <button
              onClick={() => setShowConnectModal(false)}
              className="w-full px-4 py-2 border rounded-lg hover:bg-gray-50"
            >
              Cancel
            </button>
          </div>
        </div>
      )}
    </div>
  );
}