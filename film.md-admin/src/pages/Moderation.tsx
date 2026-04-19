import React, { useEffect, useMemo, useState } from 'react';
import {
  ShieldAlertIcon,
  CheckCircleIcon,
  XCircleIcon,
  ServerIcon,
  ScrollTextIcon,
  HistoryIcon } from
'lucide-react';
import { mockFilms, mockSeries } from '../data/mockData';
import { Tabs } from '../components/shared/Tabs';
import { DataTable } from '../components/shared/DataTable';
import { Modal } from '../components/shared/Modal';
import { FormField } from '../components/shared/FormField';
import { Badge } from '../components/shared/Badge';
import { useAdmin } from '../hooks/useAdmin';
import { adminApi } from '../lib/api';
import { AuditLogItem } from '../types';
const mediaJobs = [
{
  id: 'mj1',
  content: 'Carbon',
  jobType: 'HLS Encoding',
  quality: '4K',
  status: 'done',
  started: '2023-10-27 08:00',
  duration: '45 min'
},
{
  id: 'mj2',
  content: 'Carbon',
  jobType: 'HLS Encoding',
  quality: '1080p',
  status: 'done',
  started: '2023-10-27 08:00',
  duration: '22 min'
},
{
  id: 'mj3',
  content: 'Teambuilding',
  jobType: 'Thumbnail Generation',
  quality: '-',
  status: 'processing',
  started: '2023-10-27 10:30',
  duration: '-'
},
{
  id: 'mj4',
  content: 'Milika',
  jobType: 'HLS Encoding',
  quality: '4K',
  status: 'queued',
  started: '-',
  duration: '-'
},
{
  id: 'mj5',
  content: 'Afacerea Est',
  jobType: 'Subtitle Processing',
  quality: '-',
  status: 'failed',
  started: '2023-10-26 14:00',
  duration: '2 min'
},
{
  id: 'mj6',
  content: 'Clanul',
  jobType: 'HLS Encoding',
  quality: '720p',
  status: 'done',
  started: '2023-10-26 09:00',
  duration: '15 min'
}];

const changeHistory = [
{
  id: 'ch1',
  content: 'Carbon',
  field: 'Status',
  oldValue: 'Ready',
  newValue: 'Published',
  changedBy: 'Andrei Ciobanu',
  date: '2023-10-27 10:45'
},
{
  id: 'ch2',
  content: 'Carbon',
  field: 'Meta Description (RO)',
  oldValue: 'Un film despre...',
  newValue: 'O poveste captivantă...',
  changedBy: 'Vasile Munteanu',
  date: '2023-10-27 09:30'
},
{
  id: 'ch3',
  content: 'Umbre',
  field: 'Age Rating',
  oldValue: '16+',
  newValue: '18+',
  changedBy: 'Andrei Ciobanu',
  date: '2023-10-26 16:00'
},
{
  id: 'ch4',
  content: 'Moromeții 2',
  field: 'Status',
  oldValue: 'Published',
  newValue: 'Archived',
  changedBy: 'Vasile Munteanu',
  date: '2023-10-26 11:05'
}];

export function Moderation() {
  const { can } = useAdmin();
  const [activeTab, setActiveTab] = useState('review');
  const [reviewQueue, setReviewQueue] = useState(
    [...mockFilms, ...mockSeries].filter((f) => f.status === 'ready')
  );
  const [showReviewModal, setShowReviewModal] = useState(false);
  const [showRejectModal, setShowRejectModal] = useState(false);
  const [selectedItem, setSelectedItem] = useState<any>(null);
  const [reviewNotes, setReviewNotes] = useState('');
  const [rejectReason, setRejectReason] = useState('');
  const [auditLogs, setAuditLogs] = useState<AuditLogItem[]>([]);
  const [auditActionFilter, setAuditActionFilter] = useState('');
  const [auditUserFilter, setAuditUserFilter] = useState('');
  const [auditLoading, setAuditLoading] = useState(false);
  const [auditError, setAuditError] = useState<string | null>(null);
  const handleReviewClick = (item: any) => {
    setSelectedItem(item);
    setReviewNotes('');
    setShowReviewModal(true);
  };
  const handleApprove = () => {
    if (!selectedItem) return;
    setReviewQueue(reviewQueue.filter((i) => i.id !== selectedItem.id));
    setShowReviewModal(false);
    alert(`${selectedItem.title} approved and published!`);
  };
  const handleRejectClick = (item?: any) => {
    if (item) setSelectedItem(item);
    setRejectReason('');
    setShowRejectModal(true);
    setShowReviewModal(false);
  };
  const confirmReject = () => {
    if (!selectedItem) return;
    setReviewQueue(reviewQueue.filter((i) => i.id !== selectedItem.id));
    setShowRejectModal(false);
  };
  const tabs = [
  {
    id: 'review',
    label: 'Coada de review',
    icon: ShieldAlertIcon,
    count: reviewQueue.length
  },
  {
    id: 'media-jobs',
    label: 'Joburi media',
    icon: ServerIcon
  },
  {
    id: 'audit-log',
    label: 'Jurnal audit',
    icon: ScrollTextIcon
  },
  {
    id: 'change-history',
    label: 'Istoric modificări',
    icon: HistoryIcon
  }];

  const mediaJobsColumns = [
  {
    key: 'content',
    header: 'Conținut'
  },
  {
    key: 'jobType',
    header: 'Tip job'
  },
  {
    key: 'quality',
    header: 'Calitate'
  },
  {
    key: 'status',
    header: 'Status',
    render: (item: any) => {
      const variant =
      item.status === 'done' ?
      'published' :
      item.status === 'processing' ?
      'ready' :
      item.status === 'queued' ?
      'draft' :
      'archived';
      return (
        <div className="flex items-center gap-2">
            {item.status === 'processing' &&
          <div className="w-2 h-2 rounded-full bg-yellow-400 animate-pulse" />
          }
            <Badge variant={variant}>{item.status}</Badge>
          </div>);

    }
  },
  {
    key: 'started',
    header: 'Pornit'
  },
  {
    key: 'duration',
    header: 'Durată'
  },
  {
    key: 'actions',
    header: '',
    render: (item: any) =>
    item.status === 'failed' ?
    <button className="text-sm text-indigo-600 hover:text-indigo-900 font-medium">
            Reîncearcă
          </button> :
    null
  }];

  const auditLogColumns = [
  {
    key: 'timestamp',
    header: 'Dată și oră',
    sortable: true
  },
  {
    key: 'user',
    header: 'Utilizator',
    render: (item: any) =>
    <div className="flex items-center gap-2">
          <div className="w-6 h-6 rounded-full bg-slate-100 flex items-center justify-center text-xs font-medium text-slate-600">
            {item.user.charAt(0)}
          </div>
          <span className="text-sm text-slate-900">{item.user}</span>
        </div>

  },
  {
    key: 'action',
    header: 'Acțiune',
    render: (item: any) =>
    <span className="px-2 py-1 bg-slate-100 rounded text-xs font-medium text-slate-700">
          {item.action}
        </span>

  },
  {
    key: 'target',
    header: 'Țintă'
  },
  {
    key: 'details',
    header: 'Detalii'
  }];

  const auditActions = useMemo(
    () =>
      Array.from(new Set(auditLogs.map((item) => item.action)))
        .filter(Boolean)
        .sort(),
    [auditLogs],
  );

  const auditUsers = useMemo(
    () =>
      Array.from(new Set(auditLogs.map((item) => item.user)))
        .filter(Boolean)
        .sort(),
    [auditLogs],
  );

  useEffect(() => {
    if (activeTab !== 'audit-log' || !can('moderation.view_audit_log')) {
      return;
    }

    let cancelled = false;
    setAuditLoading(true);
    setAuditError(null);

    adminApi
      .getAuditLogs(auditActionFilter ? { action: auditActionFilter } : undefined)
      .then((response) => {
        if (!cancelled) {
          setAuditLogs(response.items);
        }
      })
      .catch((error: Error) => {
        if (!cancelled) {
          setAuditError(error.message || 'Nu am putut încărca jurnalul de audit.');
        }
      })
      .finally(() => {
        if (!cancelled) {
          setAuditLoading(false);
        }
      });

    return () => {
      cancelled = true;
    };
  }, [activeTab, auditActionFilter, can]);

  const filteredAuditLogs = auditUserFilter
    ? auditLogs.filter((item) => item.user === auditUserFilter)
    : auditLogs;

  const changeHistoryColumns = [
  {
    key: 'date',
    header: 'Date',
    sortable: true
  },
  {
    key: 'changedBy',
    header: 'Modificat de'
  },
  {
    key: 'field',
    header: 'Câmp'
  },
  {
    key: 'changes',
    header: 'Modificări',
    render: (item: any) =>
    <div className="flex items-center gap-2 text-sm">
          <span className="text-red-600 bg-red-50 px-1 rounded line-through">
            {item.oldValue}
          </span>
          <span className="text-slate-400">→</span>
          <span className="text-emerald-600 bg-emerald-50 px-1 rounded">
            {item.newValue}
          </span>
        </div>

  }];

  return (
    <div className="space-y-6 h-full flex flex-col">
      <div className="flex items-center justify-between">
        <h1 className="text-2xl font-bold text-slate-900">
          Moderare și controlul calității
        </h1>
      </div>

      <div className="bg-white rounded-xl border border-slate-200 flex-1 flex flex-col overflow-hidden">
        <div className="px-4 pt-4 border-b border-slate-200">
          <Tabs tabs={tabs} activeTab={activeTab} onChange={setActiveTab} />
        </div>

        <div className="flex-1 overflow-hidden flex flex-col p-4">
          {activeTab === 'review' &&
          <div className="flex-1 overflow-y-auto">
              {reviewQueue.length === 0 ?
            <div className="p-8 text-center text-slate-500">
                  Nu există elemente în așteptare pentru review.
                </div> :

            <div className="divide-y divide-slate-200 border border-slate-200 rounded-lg">
                  {reviewQueue.map((item) =>
              <div
                key={item.id}
                className="p-4 flex items-center justify-between hover:bg-slate-50">
                
                      <div>
                        <h3 className="text-sm font-medium text-slate-900">
                          {item.title}
                        </h3>
                        <p className="text-xs text-slate-500 capitalize">
                          {item.type} • trimis acum 2 ore
                        </p>
                      </div>
                      <div className="flex items-center gap-2">
                        {can('moderation.view_queue') &&
                  <button
                    onClick={() => handleReviewClick(item)}
                    className="px-3 py-1.5 text-sm font-medium text-slate-700 bg-white border border-slate-300 rounded-md hover:bg-slate-50">
                    Revizuiește
                  </button>
                  }
                        {can('moderation.approve') &&
                  <button
                    onClick={() => {
                      setSelectedItem(item);
                      handleApprove();
                    }}
                    className="p-1.5 text-emerald-600 hover:bg-emerald-50 rounded-md"
                    title="Aprobă și publică">
                    
                            <CheckCircleIcon className="w-5 h-5" />
                          </button>
                  }
                        {can('moderation.reject') &&
                  <button
                    onClick={() => handleRejectClick(item)}
                    className="p-1.5 text-red-600 hover:bg-red-50 rounded-md"
                    title="Respinge">
                    
                            <XCircleIcon className="w-5 h-5" />
                          </button>
                  }
                      </div>
                    </div>
              )}
                </div>
            }
            </div>
          }

          {activeTab === 'media-jobs' &&
          <DataTable
            data={mediaJobs}
            columns={mediaJobsColumns}
            keyExtractor={(item) => item.id}
            searchPlaceholder="Caută joburi..." />

          }

          {activeTab === 'audit-log' && can('moderation.view_audit_log') &&
          <div className="flex flex-col h-full space-y-4">
              <div className="flex gap-4">
                <select
                  className="text-sm border-slate-300 rounded-md py-1.5 pl-3 pr-8"
                  value={auditActionFilter}
                  onChange={(event) => setAuditActionFilter(event.target.value)}
                >
                  <option value="">Toate acțiunile</option>
                  {auditActions.map((action) =>
                  <option key={action} value={action}>{action}</option>
                  )}
                </select>
                <select
                  className="text-sm border-slate-300 rounded-md py-1.5 pl-3 pr-8"
                  value={auditUserFilter}
                  onChange={(event) => setAuditUserFilter(event.target.value)}
                >
                  <option value="">Toți utilizatorii</option>
                  {auditUsers.map((user) =>
                  <option key={user} value={user}>{user}</option>
                  )}
                </select>
              </div>
              {auditError ?
              <div className="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
                  {auditError}
                </div> :
              null}
              {auditLoading ?
              <div className="rounded-md border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-600">
                  Se încarcă jurnalul de audit...
                </div> :
              null}
              <DataTable
              data={filteredAuditLogs}
              columns={auditLogColumns}
              keyExtractor={(item) => String(item.id)}
              searchPlaceholder="Caută în jurnale..." />
            
            </div>
          }

          {activeTab === 'change-history' &&
          <div className="flex flex-col h-full space-y-4">
              <div className="flex gap-4">
                <select className="text-sm border-slate-300 rounded-md py-1.5 pl-3 pr-8">
                  <option>Tot conținutul</option>
                  <option>Carbon</option>
                  <option>Umbre</option>
                  <option>Moromeții 2</option>
                </select>
              </div>
              <DataTable
              data={changeHistory}
              columns={changeHistoryColumns}
              keyExtractor={(item) => item.id}
              searchPlaceholder="Caută în istoric..." />
            
            </div>
          }
        </div>
      </div>

      <Modal
        isOpen={showReviewModal}
        onClose={() => setShowReviewModal(false)}
        title="Revizuiește conținutul"
        size="xl"
        footer={
        <>
            <button
            onClick={() => handleRejectClick()}
            className="px-4 py-2 text-sm font-medium text-white bg-red-600 rounded-lg hover:bg-red-700">
            
              Respinge
            </button>
            <button
            onClick={() => setShowReviewModal(false)}
            className="px-4 py-2 text-sm font-medium text-amber-700 bg-amber-100 rounded-lg hover:bg-amber-200">
            
              Cere modificări
            </button>
            <button
            onClick={handleApprove}
            className="px-4 py-2 text-sm font-medium text-white bg-emerald-600 rounded-lg hover:bg-emerald-700">
            
              Aprobă și publică
            </button>
          </>
        }>
        
        {selectedItem &&
        <div className="space-y-6">
            <div className="bg-slate-50 p-4 rounded-lg border border-slate-200">
              <h3 className="text-lg font-bold text-slate-900 mb-2">
                {selectedItem.title}
              </h3>
              <p className="text-sm text-slate-600 mb-4 capitalize">
                {selectedItem.type} • {selectedItem.year} •{' '}
                {selectedItem.country} • {selectedItem.ageRating}
              </p>
              <p className="text-sm text-slate-700">
                Aici va apărea previzualizarea descrierii...
              </p>
            </div>

            <div>
              <h4 className="text-sm font-medium text-slate-900 mb-3">
                Checklist de publicare
              </h4>
              <ul className="space-y-3">
                <li className="flex items-center gap-3 text-sm">
                  <CheckCircleIcon className="w-5 h-5 text-emerald-500" />
                  <span className="text-slate-700">Metadatele de bază sunt completate</span>
                </li>
                <li className="flex items-center gap-3 text-sm">
                  <CheckCircleIcon className="w-5 h-5 text-emerald-500" />
                  <span className="text-slate-700">Posterul este încărcat</span>
                </li>
                <li className="flex items-center gap-3 text-sm">
                  <CheckCircleIcon className="w-5 h-5 text-emerald-500" />
                  <span className="text-slate-700">
                    Cel puțin o limbă este tradusă
                  </span>
                </li>
                <li className="flex items-center gap-3 text-sm">
                  <CheckCircleIcon className="w-5 h-5 text-emerald-500" />
                  <span className="text-slate-700">Asset-urile video sunt procesate</span>
                </li>
                <li className="flex items-center gap-3 text-sm">
                  <CheckCircleIcon className="w-5 h-5 text-emerald-500" />
                  <span className="text-slate-700">Restricția de vârstă este setată</span>
                </li>
              </ul>
            </div>

            <FormField
            label="Note reviewer"
            type="textarea"
            rows={4}
            value={reviewNotes}
            onChange={(e) => setReviewNotes(e.target.value)}
            placeholder="Adaugă note pentru editor..." />
          
          </div>
        }
      </Modal>

      <Modal
        isOpen={showRejectModal}
        onClose={() => setShowRejectModal(false)}
        title="Respinge conținutul"
        size="md"
        footer={
        <>
            <button
            onClick={() => setShowRejectModal(false)}
            className="px-4 py-2 text-sm font-medium text-slate-700 bg-white border border-slate-300 rounded-lg hover:bg-slate-50">
            
              Anulează
            </button>
            <button
            onClick={confirmReject}
            className="px-4 py-2 text-sm font-medium text-white bg-red-600 rounded-lg hover:bg-red-700">
            
              Confirmă respingerea
            </button>
          </>
        }>
        
        <div className="space-y-4">
          <p className="text-sm text-slate-600">
            Te rugăm să oferi un motiv pentru respingerea titlului &quot;{selectedItem?.title}
            &quot;.
          </p>
          <FormField
            label="Motiv respingere"
            type="textarea"
            rows={4}
            value={rejectReason}
            onChange={(e) => setRejectReason(e.target.value)}
            placeholder="Explică ce trebuie corectat..." />
          
        </div>
      </Modal>
    </div>);

}
