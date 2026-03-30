import React, { useState } from 'react';
import {
  UploadCloudIcon,
  SearchIcon,
  GridIcon,
  ListIcon,
  ImageIcon,
  VideoIcon,
  FileTextIcon,
  MoreVerticalIcon } from
'lucide-react';
import { useAdmin } from '../hooks/useAdmin';
import { Modal } from '../components/shared/Modal';
import { FormField } from '../components/shared/FormField';
import { mockFilms, mockSeries } from '../data/mockData';
const initialAssets = [
{
  id: 1,
  name: 'carbon_poster_ro.jpg',
  type: 'image',
  size: '2.4 MB',
  date: '2023-10-25',
  dims: '2000x3000',
  used: 'Carbon (Film)'
},
{
  id: 2,
  name: 'carbon_backdrop.jpg',
  type: 'image',
  size: '4.1 MB',
  date: '2023-10-25',
  dims: '3840x2160',
  used: 'Carbon (Film)'
},
{
  id: 3,
  name: 'carbon_trailer_1080p.mp4',
  type: 'video',
  size: '145 MB',
  date: '2023-10-24',
  dims: '1920x1080',
  used: 'Carbon (Film)'
},
{
  id: 4,
  name: 'carbon_sub_ro.srt',
  type: 'subtitle',
  size: '45 KB',
  date: '2023-10-24',
  dims: '-',
  used: 'Carbon (Film)'
},
{
  id: 5,
  name: 'teambuilding_poster.png',
  type: 'image',
  size: '3.2 MB',
  date: '2023-09-12',
  dims: '2000x3000',
  used: 'Teambuilding (Film)'
},
{
  id: 6,
  name: 'las_fierbinti_s22_cover.jpg',
  type: 'image',
  size: '1.8 MB',
  date: '2023-08-05',
  dims: '1920x1080',
  used: 'Las Fierbinți (Series)'
}];

export function MediaLibrary() {
  const { can } = useAdmin();
  const [view, setView] = useState<'grid' | 'list'>('grid');
  const [assets, setAssets] = useState(initialAssets);
  const [selectedAsset, setSelectedAsset] = useState<any>(null);
  const [showDetailModal, setShowDetailModal] = useState(false);
  const [showUploadModal, setShowUploadModal] = useState(false);
  const [showDeleteModal, setShowDeleteModal] = useState(false);
  const [uploadForm, setUploadForm] = useState({
    name: '',
    type: 'image',
    linkedContent: ''
  });
  const handleAssetClick = (asset: any) => {
    setSelectedAsset(asset);
    setShowDetailModal(true);
  };
  const handleDeleteClick = () => {
    setShowDeleteModal(true);
  };
  const confirmDelete = () => {
    if (!selectedAsset) return;
    setAssets(assets.filter((a) => a.id !== selectedAsset.id));
    setShowDeleteModal(false);
    setShowDetailModal(false);
  };
  const handleUpload = () => {
    const newAsset = {
      id: Date.now(),
      name: uploadForm.name || 'new_asset.jpg',
      type: uploadForm.type,
      size: '1.2 MB',
      date: new Date().toISOString().split('T')[0],
      dims: uploadForm.type === 'image' ? '1920x1080' : '-',
      used: uploadForm.linkedContent || 'Unlinked'
    };
    setAssets([newAsset, ...assets]);
    setShowUploadModal(false);
    setUploadForm({
      name: '',
      type: 'image',
      linkedContent: ''
    });
  };
  const contentOptions = [...mockFilms, ...mockSeries].map((c) => ({
    label: `${c.title} (${c.type})`,
    value: c.title
  }));
  return (
    <div className="space-y-6 h-full flex flex-col">
      <div className="flex items-center justify-between">
        <h1 className="text-2xl font-bold text-slate-900">Media Library</h1>
        {can('media.upload') &&
        <button
          onClick={() => setShowUploadModal(true)}
          className="inline-flex items-center px-4 py-2 border border-indigo-600 text-sm font-medium rounded-lg text-white bg-indigo-600 hover:bg-indigo-700">
          
            <UploadCloudIcon className="w-4 h-4 mr-2" />
            Upload Assets
          </button>
        }
      </div>

      <div className="bg-white rounded-xl border border-slate-200 flex-1 flex flex-col overflow-hidden">
        <div className="p-4 border-b border-slate-200 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
          <div className="flex items-center gap-4 flex-1">
            <div className="relative max-w-md w-full">
              <div className="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                <SearchIcon className="h-4 w-4 text-slate-400" />
              </div>
              <input
                type="text"
                className="block w-full pl-10 pr-3 py-2 border border-slate-300 rounded-lg text-sm"
                placeholder="Search files..." />
              
            </div>
            <select className="text-sm border-slate-300 rounded-md py-2 pl-3 pr-8">
              <option>All Types</option>
              <option>Images</option>
              <option>Videos</option>
              <option>Subtitles</option>
            </select>
          </div>
          <div className="flex items-center gap-2 bg-slate-100 p-1 rounded-lg">
            <button
              onClick={() => setView('grid')}
              className={`p-1.5 rounded-md ${view === 'grid' ? 'bg-white border border-slate-200 text-indigo-600' : 'text-slate-500 hover:text-slate-700'}`}>
              
              <GridIcon className="w-4 h-4" />
            </button>
            <button
              onClick={() => setView('list')}
              className={`p-1.5 rounded-md ${view === 'list' ? 'bg-white border border-slate-200 text-indigo-600' : 'text-slate-500 hover:text-slate-700'}`}>
              
              <ListIcon className="w-4 h-4" />
            </button>
          </div>
        </div>

        <div className="flex-1 overflow-y-auto p-4 bg-slate-50">
          {view === 'grid' ?
          <div className="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-6 gap-4">
              {assets.map((asset) =>
            <div
              key={asset.id}
              onClick={() => handleAssetClick(asset)}
              className="bg-white rounded-xl border border-slate-200 overflow-hidden group hover:border-indigo-300 transition-all cursor-pointer">
              
                  <div className="aspect-square bg-slate-100 flex items-center justify-center relative">
                    {asset.type === 'image' ?
                <ImageIcon className="w-10 h-10 text-slate-300" /> :
                asset.type === 'video' ?
                <VideoIcon className="w-10 h-10 text-slate-300" /> :

                <FileTextIcon className="w-10 h-10 text-slate-300" />
                }
                    <div className="absolute top-2 right-2 opacity-0 group-hover:opacity-100 transition-opacity">
                      <button className="p-1 bg-white rounded border border-slate-200 text-slate-600 hover:text-indigo-600">
                        <MoreVerticalIcon className="w-4 h-4" />
                      </button>
                    </div>
                  </div>
                  <div className="p-3">
                    <p
                  className="text-xs font-medium text-slate-900 truncate"
                  title={asset.name}>
                  
                      {asset.name}
                    </p>
                    <p className="text-[10px] text-slate-500 mt-1">
                      {asset.size} • {asset.type}
                    </p>
                  </div>
                </div>
            )}
            </div> :

          <div className="bg-white border border-slate-200 rounded-lg overflow-hidden">
              <table className="min-w-full divide-y divide-slate-200">
                <thead className="bg-slate-50">
                  <tr>
                    <th className="px-6 py-3 text-left text-xs font-medium text-slate-500 uppercase">
                      File
                    </th>
                    <th className="px-6 py-3 text-left text-xs font-medium text-slate-500 uppercase">
                      Size
                    </th>
                    <th className="px-6 py-3 text-left text-xs font-medium text-slate-500 uppercase">
                      Dimensions
                    </th>
                    <th className="px-6 py-3 text-left text-xs font-medium text-slate-500 uppercase">
                      Used In
                    </th>
                    <th className="px-6 py-3 text-left text-xs font-medium text-slate-500 uppercase">
                      Date
                    </th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-200">
                  {assets.map((asset) =>
                <tr
                  key={asset.id}
                  onClick={() => handleAssetClick(asset)}
                  className="hover:bg-slate-50 cursor-pointer">
                  
                      <td className="px-6 py-4 whitespace-nowrap">
                        <div className="flex items-center gap-3">
                          <div className="w-8 h-8 rounded bg-slate-100 flex items-center justify-center">
                            {asset.type === 'image' ?
                        <ImageIcon className="w-4 h-4 text-slate-400" /> :
                        asset.type === 'video' ?
                        <VideoIcon className="w-4 h-4 text-slate-400" /> :

                        <FileTextIcon className="w-4 h-4 text-slate-400" />
                        }
                          </div>
                          <span className="text-sm font-medium text-slate-900">
                            {asset.name}
                          </span>
                        </div>
                      </td>
                      <td className="px-6 py-4 whitespace-nowrap text-sm text-slate-500">
                        {asset.size}
                      </td>
                      <td className="px-6 py-4 whitespace-nowrap text-sm text-slate-500">
                        {asset.dims}
                      </td>
                      <td className="px-6 py-4 whitespace-nowrap text-sm text-slate-500">
                        {asset.used}
                      </td>
                      <td className="px-6 py-4 whitespace-nowrap text-sm text-slate-500">
                        {asset.date}
                      </td>
                    </tr>
                )}
                </tbody>
              </table>
            </div>
          }
        </div>
      </div>

      <Modal
        isOpen={showDetailModal}
        onClose={() => setShowDetailModal(false)}
        title="Asset Details"
        size="lg"
        footer={
        <>
            {can('media.delete') &&
          <button
            onClick={handleDeleteClick}
            className="px-4 py-2 text-sm font-medium text-white bg-red-600 rounded-lg hover:bg-red-700 mr-auto">
            
                Delete Asset
              </button>
          }
            <button
            onClick={() => setShowDetailModal(false)}
            className="px-4 py-2 text-sm font-medium text-slate-700 bg-white border border-slate-300 rounded-lg hover:bg-slate-50">
            
              Close
            </button>
            <button
            onClick={() => setShowDetailModal(false)}
            className="px-4 py-2 text-sm font-medium text-white bg-indigo-600 rounded-lg hover:bg-indigo-700">
            
              Save Changes
            </button>
          </>
        }>
        
        {selectedAsset &&
        <div className="space-y-6">
            <div className="h-48 bg-slate-100 rounded-lg flex items-center justify-center border border-slate-200">
              {selectedAsset.type === 'image' ?
            <ImageIcon className="w-16 h-16 text-slate-300" /> :
            selectedAsset.type === 'video' ?
            <VideoIcon className="w-16 h-16 text-slate-300" /> :

            <FileTextIcon className="w-16 h-16 text-slate-300" />
            }
            </div>

            <div className="grid grid-cols-2 gap-4 bg-slate-50 p-4 rounded-lg border border-slate-200">
              <div>
                <p className="text-xs text-slate-500">Filename</p>
                <p className="text-sm font-medium text-slate-900">
                  {selectedAsset.name}
                </p>
              </div>
              <div>
                <p className="text-xs text-slate-500">Type</p>
                <p className="text-sm font-medium text-slate-900 capitalize">
                  {selectedAsset.type}
                </p>
              </div>
              <div>
                <p className="text-xs text-slate-500">Size</p>
                <p className="text-sm font-medium text-slate-900">
                  {selectedAsset.size}
                </p>
              </div>
              <div>
                <p className="text-xs text-slate-500">Dimensions</p>
                <p className="text-sm font-medium text-slate-900">
                  {selectedAsset.dims}
                </p>
              </div>
              <div>
                <p className="text-xs text-slate-500">Upload Date</p>
                <p className="text-sm font-medium text-slate-900">
                  {selectedAsset.date}
                </p>
              </div>
              <div>
                <p className="text-xs text-slate-500">Uploaded By</p>
                <p className="text-sm font-medium text-slate-900">
                  Andrei Ciobanu
                </p>
              </div>
            </div>

            <div>
              <p className="text-sm font-medium text-slate-900 mb-2">Used In</p>
              <a href="#" className="text-sm text-indigo-600 hover:underline">
                {selectedAsset.used}
              </a>
            </div>

            <div className="space-y-4">
              <FormField label="Tags" placeholder="e.g. poster, main, hero" />
              <FormField
              label="Alt Text"
              placeholder="Describe the image for accessibility..." />
            
            </div>
          </div>
        }
      </Modal>

      <Modal
        isOpen={showUploadModal}
        onClose={() => setShowUploadModal(false)}
        title="Upload Assets"
        size="md"
        footer={
        <>
            <button
            onClick={() => setShowUploadModal(false)}
            className="px-4 py-2 text-sm font-medium text-slate-700 bg-white border border-slate-300 rounded-lg hover:bg-slate-50">
            
              Cancel
            </button>
            <button
            onClick={handleUpload}
            className="px-4 py-2 text-sm font-medium text-white bg-indigo-600 rounded-lg hover:bg-indigo-700">
            
              Upload
            </button>
          </>
        }>
        
        <div className="space-y-6">
          <div className="border-2 border-dashed border-slate-300 rounded-xl p-8 flex flex-col items-center justify-center text-center hover:bg-slate-50 transition-colors cursor-pointer">
            <UploadCloudIcon className="w-10 h-10 text-slate-400 mb-3" />
            <p className="text-sm font-medium text-slate-900">
              Click to upload or drag and drop
            </p>
            <p className="text-xs text-slate-500 mt-1">
              Images, Videos, or Subtitles
            </p>
          </div>

          <FormField
            label="Simulate Selected File Name"
            value={uploadForm.name}
            onChange={(e) =>
            setUploadForm({
              ...uploadForm,
              name: e.target.value
            })
            }
            placeholder="e.g. new_poster.jpg" />
          

          <FormField
            label="File Type"
            type="select"
            value={uploadForm.type}
            onChange={(e) =>
            setUploadForm({
              ...uploadForm,
              type: e.target.value
            })
            }
            options={[
            {
              label: 'Image',
              value: 'image'
            },
            {
              label: 'Video',
              value: 'video'
            },
            {
              label: 'Subtitle',
              value: 'subtitle'
            }]
            } />
          

          <FormField
            label="Link to Content (Optional)"
            type="select"
            value={uploadForm.linkedContent}
            onChange={(e) =>
            setUploadForm({
              ...uploadForm,
              linkedContent: e.target.value
            })
            }
            options={[
            {
              label: 'None',
              value: ''
            },
            ...contentOptions]
            } />
          
        </div>
      </Modal>

      <Modal
        isOpen={showDeleteModal}
        onClose={() => setShowDeleteModal(false)}
        title="Delete Asset"
        size="sm"
        footer={
        <>
            <button
            onClick={() => setShowDeleteModal(false)}
            className="px-4 py-2 text-sm font-medium text-slate-700 bg-white border border-slate-300 rounded-lg hover:bg-slate-50">
            
              Cancel
            </button>
            <button
            onClick={confirmDelete}
            className="px-4 py-2 text-sm font-medium text-white bg-red-600 rounded-lg hover:bg-red-700">
            
              Delete
            </button>
          </>
        }>
        
        <p className="text-sm text-slate-600">
          Are you sure you want to delete &quot;{selectedAsset?.name}&quot;?
          This action cannot be undone.
        </p>
      </Modal>
    </div>);

}
