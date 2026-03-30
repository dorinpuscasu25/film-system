import React from 'react';
import { ActivityIcon, ServerIcon, ShieldIcon } from 'lucide-react';
import { StatsCard } from '../components/shared/StatsCard';
export function PlaybackOps() {
  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <h1 className="text-2xl font-bold text-slate-900">
          Playback Operations
        </h1>
        <div className="flex items-center gap-2 text-sm text-emerald-600 bg-emerald-50 px-3 py-1.5 rounded-full font-medium border border-emerald-200">
          <div className="w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></div>
          All Systems Operational
        </div>
      </div>

      <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
        <StatsCard
          title="Active Streams"
          value="1,245"
          icon={ActivityIcon}
          trend={5} />
        
        <StatsCard
          title="CDN Bandwidth"
          value="4.2 Tbps"
          icon={ServerIcon}
          colorClass="text-blue-600 bg-blue-100" />
        
        <StatsCard
          title="DRM Token Rejects"
          value="12"
          icon={ShieldIcon}
          colorClass="text-orange-600 bg-orange-100" />
        
      </div>

      <div className="bg-white rounded-xl border border-slate-200 overflow-hidden">
        <div className="p-4 border-b border-slate-200 bg-slate-50">
          <h2 className="text-lg font-medium text-slate-900">
            Active Sessions (Live)
          </h2>
        </div>
        <table className="min-w-full divide-y divide-slate-200">
          <thead className="bg-slate-50">
            <tr>
              <th className="px-6 py-3 text-left text-xs font-medium text-slate-500 uppercase">
                User
              </th>
              <th className="px-6 py-3 text-left text-xs font-medium text-slate-500 uppercase">
                Content
              </th>
              <th className="px-6 py-3 text-left text-xs font-medium text-slate-500 uppercase">
                Quality
              </th>
              <th className="px-6 py-3 text-left text-xs font-medium text-slate-500 uppercase">
                Device
              </th>
              <th className="px-6 py-3 text-left text-xs font-medium text-slate-500 uppercase">
                Started
              </th>
              <th className="px-6 py-3 text-right text-xs font-medium text-slate-500 uppercase">
                Actions
              </th>
            </tr>
          </thead>
          <tbody className="divide-y divide-slate-200">
            {[1, 2, 3, 4, 5].map((i) =>
            <tr key={i} className="hover:bg-slate-50">
                <td className="px-6 py-4 whitespace-nowrap text-sm font-medium text-slate-900">
                  user_{i}29@email.com
                </td>
                <td className="px-6 py-4 whitespace-nowrap text-sm text-slate-600">
                  Carbon (Film)
                </td>
                <td className="px-6 py-4 whitespace-nowrap text-sm text-slate-600">
                  <span className="px-2 py-1 bg-slate-100 rounded text-xs font-medium">
                    1080p
                  </span>
                </td>
                <td className="px-6 py-4 whitespace-nowrap text-sm text-slate-600">
                  Smart TV (Tizen)
                </td>
                <td className="px-6 py-4 whitespace-nowrap text-sm text-slate-600">
                  {i * 12} mins ago
                </td>
                <td className="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                  <button className="text-red-600 hover:text-red-900">
                    Revoke Token
                  </button>
                </td>
              </tr>
            )}
          </tbody>
        </table>
      </div>
    </div>);

}
