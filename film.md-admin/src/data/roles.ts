export type Role = 'super-admin' | 'admin' | 'moderator' | 'editor';

export const roles: {id: Role;name: string;description: string;}[] = [
{
  id: 'super-admin',
  name: 'Super Admin',
  description: 'Full access to everything, can manage other admins.'
},
{
  id: 'admin',
  name: 'Admin',
  description:
  'Full content/commerce/CMS access, can manage users but not super-admins.'
},
{
  id: 'moderator',
  name: 'Moderator',
  description: 'Can review/approve content, manage taxonomies, view users.'
},
{
  id: 'editor',
  name: 'Editor',
  description:
  'Can create/edit content drafts, manage media, no publish/commerce access.'
}];


export const permissionCategories = [
{
  id: 'content',
  name: 'Content',
  permissions: [
  { id: 'content.view', name: 'View Content' },
  { id: 'content.create', name: 'Create Content' },
  { id: 'content.edit', name: 'Edit Content' },
  { id: 'content.publish', name: 'Publish Content' },
  { id: 'content.archive', name: 'Archive Content' },
  { id: 'content.delete', name: 'Delete Content' }]

},
{
  id: 'media',
  name: 'Media',
  permissions: [
  { id: 'media.view', name: 'View Media' },
  { id: 'media.upload', name: 'Upload Media' },
  { id: 'media.delete', name: 'Delete Media' }]

},
{
  id: 'taxonomies',
  name: 'Taxonomies',
  permissions: [
  { id: 'taxonomies.view', name: 'View Taxonomies' },
  { id: 'taxonomies.create', name: 'Create Taxonomies' },
  { id: 'taxonomies.edit', name: 'Edit Taxonomies' },
  { id: 'taxonomies.delete', name: 'Delete Taxonomies' }]

},
{
  id: 'commerce',
  name: 'Commerce',
  permissions: [
  { id: 'commerce.view', name: 'View Commerce' },
  { id: 'commerce.create_offers', name: 'Create Offers' },
  { id: 'commerce.edit_offers', name: 'Edit Offers' },
  { id: 'commerce.view_billing', name: 'View Billing' },
  { id: 'commerce.process_refunds', name: 'Process Refunds' }]

},
{
  id: 'users',
  name: 'Users',
  permissions: [
  { id: 'users.view', name: 'View Users' },
  { id: 'users.edit', name: 'Edit Users' },
  { id: 'users.change_roles', name: 'Change Roles' },
  { id: 'users.suspend', name: 'Suspend Users' },
  { id: 'users.delete', name: 'Delete Users' }]

},
{
  id: 'cms',
  name: 'CMS',
  permissions: [
  { id: 'cms.view', name: 'View CMS Pages' },
  { id: 'cms.create', name: 'Create CMS Pages' },
  { id: 'cms.edit', name: 'Edit CMS Pages' },
  { id: 'cms.publish', name: 'Publish CMS Pages' },
  { id: 'cms.delete', name: 'Delete CMS Pages' }]

},
{
  id: 'moderation',
  name: 'Moderation',
  permissions: [
  { id: 'moderation.view_queue', name: 'View Review Queue' },
  { id: 'moderation.approve', name: 'Approve Content' },
  { id: 'moderation.reject', name: 'Reject Content' },
  { id: 'moderation.view_audit_log', name: 'View Audit Log' }]

},
{
  id: 'settings',
  name: 'Settings',
  permissions: [
  { id: 'settings.view', name: 'View Settings' },
  { id: 'settings.edit_home_curation', name: 'Edit Home Curation' },
  { id: 'settings.edit_search_config', name: 'Edit Search Config' },
  { id: 'settings.manage_roles', name: 'Manage Roles' }]

},
{
  id: 'playback',
  name: 'Playback',
  permissions: [
  { id: 'playback.view_sessions', name: 'View Active Sessions' },
  { id: 'playback.revoke_tokens', name: 'Revoke Playback Tokens' }]

}];


// Flatten permissions for easier checking
export const allPermissions = permissionCategories.flatMap((c) =>
c.permissions.map((p) => p.id)
);

export const rolePermissions: Record<Role, string[]> = {
  'super-admin': [...allPermissions],
  admin: [
  ...permissionCategories.
  find((c) => c.id === 'content')!.
  permissions.map((p) => p.id),
  ...permissionCategories.
  find((c) => c.id === 'media')!.
  permissions.map((p) => p.id),
  ...permissionCategories.
  find((c) => c.id === 'taxonomies')!.
  permissions.map((p) => p.id),
  ...permissionCategories.
  find((c) => c.id === 'commerce')!.
  permissions.map((p) => p.id),
  ...permissionCategories.
  find((c) => c.id === 'cms')!.
  permissions.map((p) => p.id),
  ...permissionCategories.
  find((c) => c.id === 'moderation')!.
  permissions.map((p) => p.id),
  'users.view',
  'users.edit',
  'users.suspend',
  'settings.view',
  'settings.edit_home_curation',
  'settings.edit_search_config',
  'playback.view_sessions'],

  moderator: [
  'content.view',
  'media.view',
  ...permissionCategories.
  find((c) => c.id === 'taxonomies')!.
  permissions.map((p) => p.id),
  'users.view',
  'moderation.view_queue',
  'moderation.approve',
  'moderation.reject',
  'moderation.view_audit_log'],

  editor: [
  'content.view',
  'content.create',
  'content.edit',
  'media.view',
  'media.upload',
  'taxonomies.view',
  'cms.view',
  'cms.create',
  'cms.edit']

};

export function hasPermission(role: Role, permission: string): boolean {
  return rolePermissions[role]?.includes(permission) || false;
}
