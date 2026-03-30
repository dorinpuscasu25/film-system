import React, { useState } from 'react';
import { FileTextIcon, EditIcon, PlusIcon, TrashIcon } from 'lucide-react';
import { Modal } from '../components/shared/Modal';
import { FormField } from '../components/shared/FormField';
import { useAdmin } from '../hooks/useAdmin';
export function CMSPages() {
  const { can } = useAdmin();
  const [pages, setPages] = useState([
  {
    id: 1,
    title: 'Termeni și condiții',
    slug: '/terms',
    updated: '2023-10-01',
    status: 'Publicată',
    content: 'Conținut termeni și condiții...'
  },
  {
    id: 2,
    title: 'Politica de confidențialitate',
    slug: '/privacy',
    updated: '2023-10-01',
    status: 'Publicată',
    content: 'Conținut politică de confidențialitate...'
  },
  {
    id: 3,
    title: 'Despre noi',
    slug: '/about',
    updated: '2023-08-15',
    status: 'Publicată',
    content: 'Conținut despre noi...'
  },
  {
    id: 4,
    title: 'Ajutor și întrebări frecvente',
    slug: '/help',
    updated: '2023-10-20',
    status: 'Publicată',
    content: 'Conținut ajutor...'
  },
  {
    id: 5,
    title: 'Contact',
    slug: '/contact',
    updated: '2023-05-10',
    status: 'Publicată',
    content: 'Informații de contact...'
  }]
  );
  const [isModalOpen, setIsModalOpen] = useState(false);
  const [editingPage, setEditingPage] = useState<any>(null);
  const [formData, setFormData] = useState({
    title: '',
    slug: '',
    status: 'Ciornă',
    content: ''
  });
  const handleOpenModal = (page?: any) => {
    if (page) {
      setEditingPage(page);
      setFormData({
        title: page.title,
        slug: page.slug,
        status: page.status,
        content: page.content || ''
      });
    } else {
      setEditingPage(null);
      setFormData({
        title: '',
        slug: '/',
        status: 'Ciornă',
        content: ''
      });
    }
    setIsModalOpen(true);
  };
  const handleSave = () => {
    const pageData = {
      ...formData,
      updated: new Date().toISOString().split('T')[0]
    };
    if (editingPage) {
      setPages(
        pages.map((p) =>
        p.id === editingPage.id ?
        {
          ...p,
          ...pageData
        } :
        p
        )
      );
    } else {
      setPages([
      {
        id: Date.now(),
        ...pageData
      },
      ...pages]
      );
    }
    setIsModalOpen(false);
  };
  const handleDelete = (id: number) => {
    if (confirm('Sigur vrei să ștergi această pagină?')) {
      setPages(pages.filter((p) => p.id !== id));
    }
  };
  return (
    <div className="w-full space-y-6">
      <div className="flex items-center justify-between">
        <h1 className="text-2xl font-bold text-slate-900">Pagini CMS</h1>
        {can('cms.create') &&
        <button
          onClick={() => handleOpenModal()}
          className="inline-flex items-center px-4 py-2 border border-indigo-600 text-sm font-medium rounded-lg text-white bg-indigo-600 hover:bg-indigo-700">
          
            <PlusIcon className="w-4 h-4 mr-2" />
            Creează pagină
          </button>
        }
      </div>

      <div className="bg-white rounded-xl border border-slate-200 overflow-hidden">
        <table className="min-w-full divide-y divide-slate-200">
          <thead className="bg-slate-50">
            <tr>
              <th className="px-6 py-3 text-left text-xs font-medium text-slate-500 uppercase">
                Titlu pagină
              </th>
              <th className="px-6 py-3 text-left text-xs font-medium text-slate-500 uppercase">
                Slug
              </th>
              <th className="px-6 py-3 text-left text-xs font-medium text-slate-500 uppercase">
                Ultima actualizare
              </th>
              <th className="px-6 py-3 text-left text-xs font-medium text-slate-500 uppercase">
                Stare
              </th>
              <th className="px-6 py-3 text-right text-xs font-medium text-slate-500 uppercase">
                Acțiuni
              </th>
            </tr>
          </thead>
          <tbody className="divide-y divide-slate-200">
            {pages.map((page) =>
            <tr key={page.id} className="hover:bg-slate-50">
                <td className="px-6 py-4 whitespace-nowrap">
                  <div className="flex items-center gap-3">
                    <FileTextIcon className="w-5 h-5 text-slate-400" />
                    <span className="text-sm font-medium text-slate-900">
                      {page.title}
                    </span>
                  </div>
                </td>
                <td className="px-6 py-4 whitespace-nowrap text-sm text-slate-500">
                  {page.slug}
                </td>
                <td className="px-6 py-4 whitespace-nowrap text-sm text-slate-500">
                  {page.updated}
                </td>
                <td
                className={`px-2 py-1 rounded-full text-xs font-medium ${page.status === 'Publicată' ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-100 text-slate-800'}`}>
                
                  {page.status}
                </td>
                <td className="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                  <div className="flex justify-end gap-2">
                    {can('cms.edit') &&
                  <button
                    onClick={() => handleOpenModal(page)}
                    className="p-1.5 text-slate-400 hover:text-indigo-600 hover:bg-indigo-50 rounded transition-colors">
                    
                        <EditIcon className="w-4 h-4" />
                      </button>
                  }
                    {can('cms.delete') &&
                  <button
                    onClick={() => handleDelete(page.id)}
                    className="p-1.5 text-slate-400 hover:text-red-600 hover:bg-red-50 rounded transition-colors">
                    
                        <TrashIcon className="w-4 h-4" />
                      </button>
                  }
                  </div>
                </td>
              </tr>
            )}
          </tbody>
        </table>
      </div>

      <Modal
        isOpen={isModalOpen}
        onClose={() => setIsModalOpen(false)}
        title={editingPage ? 'Editează pagina' : 'Creează pagină'}
        size="lg"
        footer={
        <>
            <button
            onClick={() => setIsModalOpen(false)}
            className="px-4 py-2 text-sm font-medium text-slate-700 bg-white border border-slate-300 rounded-lg hover:bg-slate-50">
            
              Cancel
              Anulează
            </button>
            <button
            onClick={handleSave}
            className="px-4 py-2 text-sm font-medium text-white bg-indigo-600 rounded-lg hover:bg-indigo-700">
            
              Salvează pagina
            </button>
          </>
        }>
        
        <div className="space-y-4">
          <div className="grid grid-cols-2 gap-4">
            <FormField
              label="Titlu pagină"
              value={formData.title}
              onChange={(e) => {
                const title = e.target.value;
                setFormData({
                  ...formData,
                  title,
                  slug: editingPage ?
                  formData.slug :
                  '/' + title.toLowerCase().replace(/\s+/g, '-')
                });
              }} />
            
            <FormField
              label="Slug URL"
              value={formData.slug}
              onChange={(e) =>
              setFormData({
                ...formData,
                slug: e.target.value
              })
              } />
            
          </div>
          <FormField
            label="Stare"
            type="select"
            value={formData.status}
            onChange={(e) =>
            setFormData({
              ...formData,
              status: e.target.value
            })
            }
            options={[
            {
              label: 'Ciornă',
              value: 'Ciornă'
            },
            {
              label: 'Publicată',
              value: 'Publicată'
            }]
            } />
          
          <FormField
            label="Conținut pagină (HTML/Markdown)"
            type="textarea"
            rows={10}
            value={formData.content}
            onChange={(e) =>
            setFormData({
              ...formData,
              content: e.target.value
            })
            } />
          
        </div>
      </Modal>
    </div>);

}
