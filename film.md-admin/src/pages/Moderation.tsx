import React, { useState } from 'react';
import {
  ShieldAlertIcon,
  CheckCircleIcon,
  XCircleIcon,
  ServerIcon,
  ScrollTextIcon,
  HistoryIcon } from
'lucide-react';
import { mockFilms, mockSeries, mockAuditLog } from '../data/mockData';
import { Tabs } from '../components/shared/Tabs';
import { DataTable } from '../components/shared/DataTable';
import { Modal } from '../components/shared/Modal';
import { FormField } from '../components/shared/FormField';
import { Badge } from '../components/shared/Badge';
import { useAdmin } from '../hooks/useAdmin';
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
    label: 'Review Queue',
    icon: ShieldAlertIcon,
    count: reviewQueue.length
  },
  {
    id: 'media-jobs',
    label: 'Media Jobs',
    icon: ServerIcon
  },
  {
    id: 'audit-log',
    label: 'Audit Log',
    icon: ScrollTextIcon
  },
  {
    id: 'change-history',
    label: 'Change History',
    icon: HistoryIcon
  }];

  const mediaJobsColumns = [
  {
    key: 'content',
    header: 'Content'
  },
  {
    key: 'jobType',
    header: 'Job Type'
  },
  {
    key: 'quality',
    header: 'Quality'
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
    header: 'Started'
  },
  {
    key: 'duration',
    header: 'Duration'
  },
  {
    key: 'actions',
    header: '',
    render: (item: any) =>
    item.status === 'failed' ?
    <button className="text-sm text-indigo-600 hover:text-indigo-900 font-medium">
            Retry
          </button> :
    null
  }];

  const auditLogColumns = [
  {
    key: 'timestamp',
    header: 'Timestamp',
    sortable: true
  },
  {
    key: 'user',
    header: 'User',
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
    header: 'Action',
    render: (item: any) =>
    <span className="px-2 py-1 bg-slate-100 rounded text-xs font-medium text-slate-700">
          {item.action}
        </span>

  },
  {
    key: 'target',
    header: 'Target'
  },
  {
    key: 'details',
    header: 'Details'
  }];

  const changeHistoryColumns = [
  {
    key: 'date',
    header: 'Date',
    sortable: true
  },
  {
    key: 'changedBy',
    header: 'Changed By'
  },
  {
    key: 'field',
    header: 'Field'
  },
  {
    key: 'changes',
    header: 'Changes',
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
          Moderation & Quality Control
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
                  No items pending review.
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
                          {item.type} • Submitted 2 hours ago
                        </p>
                      </div>
                      <div className="flex items-center gap-2">
                        {can('moderation.view_queue') &&
                  <button
                    onClick={() => handleReviewClick(item)}
                    className="px-3 py-1.5 text-sm font-medium text-slate-700 bg-white border border-slate-300 rounded-md hover:bg-slate-50">
                    
                            Review Content
                          </button>
                  }
                        {can('moderation.approve') &&
                  <button
                    onClick={() => {
                      setSelectedItem(item);
                      handleApprove();
                    }}
                    className="p-1.5 text-emerald-600 hover:bg-emerald-50 rounded-md"
                    title="Approve & Publish">
                    
                            <CheckCircleIcon className="w-5 h-5" />
                          </button>
                  }
                        {can('moderation.reject') &&
                  <button
                    onClick={() => handleRejectClick(item)}
                    className="p-1.5 text-red-600 hover:bg-red-50 rounded-md"
                    title="Reject">
                    
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
            searchPlaceholder="Search jobs..." />

          }

          {activeTab === 'audit-log' && can('moderation.view_audit_log') &&
          <div className="flex flex-col h-full space-y-4">
              <div className="flex gap-4">
                <select className="text-sm border-slate-300 rounded-md py-1.5 pl-3 pr-8">
                  <option>All Actions</option>
                  <option>Created</option>
                  <option>Updated</option>
                  <option>Published</option>
                  <option>Archived</option>
                  <option>Deleted</option>
                </select>
                <select className="text-sm border-slate-300 rounded-md py-1.5 pl-3 pr-8">
                  <option>All Users</option>
                  <option>Andrei Ciobanu</option>
                  <option>Vasile Munteanu</option>
                  <option>System</option>
                </select>
              </div>
              <DataTable
              data={mockAuditLog}
              columns={auditLogColumns}
              keyExtractor={(item) => item.id}
              searchPlaceholder="Search logs..." />
            
            </div>
          }

          {activeTab === 'change-history' &&
          <div className="flex flex-col h-full space-y-4">
              <div className="flex gap-4">
                <select className="text-sm border-slate-300 rounded-md py-1.5 pl-3 pr-8">
                  <option>All Content</option>
                  <option>Carbon</option>
                  <option>Umbre</option>
                  <option>Moromeții 2</option>
                </select>
              </div>
              <DataTable
              data={changeHistory}
              columns={changeHistoryColumns}
              keyExtractor={(item) => item.id}
              searchPlaceholder="Search history..." />
            
            </div>
          }
        </div>
      </div>

      <Modal
        isOpen={showReviewModal}
        onClose={() => setShowReviewModal(false)}
        title="Review Content"
        size="xl"
        footer={
        <>
            <button
            onClick={() => handleRejectClick()}
            className="px-4 py-2 text-sm font-medium text-white bg-red-600 rounded-lg hover:bg-red-700">
            
              Reject
            </button>
            <button
            onClick={() => setShowReviewModal(false)}
            className="px-4 py-2 text-sm font-medium text-amber-700 bg-amber-100 rounded-lg hover:bg-amber-200">
            
              Request Changes
            </button>
            <button
            onClick={handleApprove}
            className="px-4 py-2 text-sm font-medium text-white bg-emerald-600 rounded-lg hover:bg-emerald-700">
            
              Approve & Publish
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
                Description preview would go here...
              </p>
            </div>

            <div>
              <h4 className="text-sm font-medium text-slate-900 mb-3">
                Publish Checklist
              </h4>
              <ul className="space-y-3">
                <li className="flex items-center gap-3 text-sm">
                  <CheckCircleIcon className="w-5 h-5 text-emerald-500" />
                  <span className="text-slate-700">Basic metadata filled</span>
                </li>
                <li className="flex items-center gap-3 text-sm">
                  <CheckCircleIcon className="w-5 h-5 text-emerald-500" />
                  <span className="text-slate-700">Poster image uploaded</span>
                </li>
                <li className="flex items-center gap-3 text-sm">
                  <CheckCircleIcon className="w-5 h-5 text-emerald-500" />
                  <span className="text-slate-700">
                    At least 1 locale translated
                  </span>
                </li>
                <li className="flex items-center gap-3 text-sm">
                  <CheckCircleIcon className="w-5 h-5 text-emerald-500" />
                  <span className="text-slate-700">Video assets processed</span>
                </li>
                <li className="flex items-center gap-3 text-sm">
                  <CheckCircleIcon className="w-5 h-5 text-emerald-500" />
                  <span className="text-slate-700">Age rating set</span>
                </li>
              </ul>
            </div>

            <FormField
            label="Reviewer Notes"
            type="textarea"
            rows={4}
            value={reviewNotes}
            onChange={(e) => setReviewNotes(e.target.value)}
            placeholder="Add any notes for the editor..." />
          
          </div>
        }
      </Modal>

      <Modal
        isOpen={showRejectModal}
        onClose={() => setShowRejectModal(false)}
        title="Reject Content"
        size="md"
        footer={
        <>
            <button
            onClick={() => setShowRejectModal(false)}
            className="px-4 py-2 text-sm font-medium text-slate-700 bg-white border border-slate-300 rounded-lg hover:bg-slate-50">
            
              Cancel
            </button>
            <button
            onClick={confirmReject}
            className="px-4 py-2 text-sm font-medium text-white bg-red-600 rounded-lg hover:bg-red-700">
            
              Confirm Rejection
            </button>
          </>
        }>
        
        <div className="space-y-4">
          <p className="text-sm text-slate-600">
            Please provide a reason for rejecting &quot;{selectedItem?.title}
            &quot;.
          </p>
          <FormField
            label="Rejection Reason"
            type="textarea"
            rows={4}
            value={rejectReason}
            onChange={(e) => setRejectReason(e.target.value)}
            placeholder="Explain what needs to be fixed..." />
          
        </div>
      </Modal>
    </div>);

}
