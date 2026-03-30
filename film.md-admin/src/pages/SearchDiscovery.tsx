import React from 'react';
import { FormField } from '../components/shared/FormField';
export function SearchDiscovery() {
  return (
    <div className="w-full space-y-6">
      <div>
        <h1 className="text-2xl font-bold text-slate-900">
          Căutare și descoperire
        </h1>
        <p className="text-slate-500 mt-1">
          Configurează modul în care utilizatorii găsesc conținutul în platformă.
        </p>
      </div>

      <div className="bg-white rounded-xl border border-slate-200 overflow-hidden">
        <div className="p-6 border-b border-slate-200">
          <h2 className="text-lg font-medium text-slate-900 mb-4">
            Filtre expuse
          </h2>
          <p className="text-sm text-slate-500 mb-4">
            Alege ce filtre sunt vizibile pentru utilizatori în pagina de catalog.
          </p>

          <div className="grid grid-cols-2 md:grid-cols-3 gap-4">
            <FormField label="Gen" type="toggle" checked={true} />
            <FormField label="An lansare" type="toggle" checked={true} />
            <FormField label="Țară" type="toggle" checked={true} />
            <FormField label="Restricție vârstă" type="toggle" checked={false} />
            <FormField label="Tip conținut" type="toggle" checked={true} />
            <FormField label="Preț/Gratuit" type="toggle" checked={true} />
          </div>
        </div>

        <div className="p-6 border-b border-slate-200">
          <h2 className="text-lg font-medium text-slate-900 mb-4">
            Sugestii de căutare
          </h2>
          <div className="space-y-4">
            <FormField
              label="Activează autosugestiile"
              type="toggle"
              checked={true} />
            
            <FormField
              label="Sursă sugestii"
              type="select"
              options={[
              {
                label: 'Popularitate (vizualizări)',
                value: 'views'
              },
              {
                label: 'Adăugate recent',
                value: 'recent'
              },
              {
                label: 'Doar potrivire exactă',
                value: 'exact'
              }]
              } />
            
          </div>
        </div>

        <div className="p-6">
          <h2 className="text-lg font-medium text-slate-900 mb-4">
            Reguli pentru conținut similar
          </h2>
          <p className="text-sm text-slate-500 mb-4">
            Cum este populată secțiunea „Mai multe ca acestea” în pagina de detaliu.
          </p>

          <div className="space-y-4">
            <FormField label="Potrivire după gen" type="toggle" checked={true} />
            <FormField
              label="Potrivire după regizor/cast"
              type="toggle"
              checked={true} />
            
            <FormField
              label="Prioritizează aceeași serie/univers"
              type="toggle"
              checked={true} />
            
            <FormField label="Număr maxim de titluri similare" type="number" value={12} />
          </div>
        </div>

        <div className="p-4 bg-slate-50 border-t border-slate-200 flex justify-end">
          <button className="px-4 py-2 bg-indigo-600 text-white rounded-lg text-sm font-medium hover:bg-indigo-700">
            Salvează configurația
          </button>
        </div>
      </div>
    </div>);

}
