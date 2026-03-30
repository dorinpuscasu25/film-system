import React from 'react';
import { FormField } from '../components/shared/FormField';
export function SearchDiscovery() {
  return (
    <div className="w-full space-y-6">
      <div>
        <h1 className="text-2xl font-bold text-slate-900">
          Search & Discovery
        </h1>
        <p className="text-slate-500 mt-1">
          Configure how users find content on the platform.
        </p>
      </div>

      <div className="bg-white rounded-xl border border-slate-200 overflow-hidden">
        <div className="p-6 border-b border-slate-200">
          <h2 className="text-lg font-medium text-slate-900 mb-4">
            Exposed Filters
          </h2>
          <p className="text-sm text-slate-500 mb-4">
            Select which filters are visible to users on the catalog page.
          </p>

          <div className="grid grid-cols-2 md:grid-cols-3 gap-4">
            <FormField label="Genre" type="toggle" checked={true} />
            <FormField label="Release Year" type="toggle" checked={true} />
            <FormField label="Country" type="toggle" checked={true} />
            <FormField label="Age Rating" type="toggle" checked={false} />
            <FormField label="Content Type" type="toggle" checked={true} />
            <FormField label="Price/Free" type="toggle" checked={true} />
          </div>
        </div>

        <div className="p-6 border-b border-slate-200">
          <h2 className="text-lg font-medium text-slate-900 mb-4">
            Search Suggestions
          </h2>
          <div className="space-y-4">
            <FormField
              label="Enable Auto-suggestions"
              type="toggle"
              checked={true} />
            
            <FormField
              label="Suggestion Source"
              type="select"
              options={[
              {
                label: 'Popularity (Views)',
                value: 'views'
              },
              {
                label: 'Recent Additions',
                value: 'recent'
              },
              {
                label: 'Exact Match Only',
                value: 'exact'
              }]
              } />
            
          </div>
        </div>

        <div className="p-6">
          <h2 className="text-lg font-medium text-slate-900 mb-4">
            Related Content Rules
          </h2>
          <p className="text-sm text-slate-500 mb-4">
            How the "More like this" section is populated on content detail
            pages.
          </p>

          <div className="space-y-4">
            <FormField label="Match by Genre" type="toggle" checked={true} />
            <FormField
              label="Match by Director/Cast"
              type="toggle"
              checked={true} />
            
            <FormField
              label="Prioritize Same Series/Universe"
              type="toggle"
              checked={true} />
            
            <FormField label="Max Related Items" type="number" value={12} />
          </div>
        </div>

        <div className="p-4 bg-slate-50 border-t border-slate-200 flex justify-end">
          <button className="px-4 py-2 bg-indigo-600 text-white rounded-lg text-sm font-medium hover:bg-indigo-700">
            Save Configuration
          </button>
        </div>
      </div>
    </div>);

}
