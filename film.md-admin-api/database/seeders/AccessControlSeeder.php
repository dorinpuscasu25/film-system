<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class AccessControlSeeder extends Seeder
{
    /**
     * @var array<int, array{code: string, name: string, group: string, description: string}>
     */
    protected array $permissions = [
        ['code' => 'admin.access', 'name' => 'Access admin panel', 'group' => 'admin', 'description' => 'Allows access to the admin SPA.'],
        ['code' => 'storefront.access', 'name' => 'Access storefront', 'group' => 'storefront', 'description' => 'Allows access to the public storefront.'],
        ['code' => 'wallet.top_up', 'name' => 'Top up wallet', 'group' => 'wallet', 'description' => 'Allows wallet funding actions.'],
        ['code' => 'content.purchase', 'name' => 'Purchase content', 'group' => 'storefront', 'description' => 'Allows buying access to titles.'],
        ['code' => 'content.watch', 'name' => 'Watch content', 'group' => 'storefront', 'description' => 'Allows playback for owned titles.'],
        ['code' => 'profile.manage', 'name' => 'Manage own profile', 'group' => 'account', 'description' => 'Allows editing personal settings.'],
        ['code' => 'content.view', 'name' => 'View content', 'group' => 'content', 'description' => 'View content catalog in admin.'],
        ['code' => 'content.scope_assigned', 'name' => 'View only assigned content', 'group' => 'content', 'description' => 'Limit admin visibility to explicitly assigned titles only.'],
        ['code' => 'content.create', 'name' => 'Create content', 'group' => 'content', 'description' => 'Create new titles.'],
        ['code' => 'content.edit', 'name' => 'Edit content', 'group' => 'content', 'description' => 'Edit existing titles.'],
        ['code' => 'content.publish', 'name' => 'Publish content', 'group' => 'content', 'description' => 'Publish titles to the storefront.'],
        ['code' => 'content.archive', 'name' => 'Archive content', 'group' => 'content', 'description' => 'Archive titles.'],
        ['code' => 'content.delete', 'name' => 'Delete content', 'group' => 'content', 'description' => 'Delete titles permanently.'],
        ['code' => 'media.view', 'name' => 'View media', 'group' => 'media', 'description' => 'View uploaded media assets.'],
        ['code' => 'media.upload', 'name' => 'Upload media', 'group' => 'media', 'description' => 'Upload new media assets.'],
        ['code' => 'media.delete', 'name' => 'Delete media', 'group' => 'media', 'description' => 'Delete media assets.'],
        ['code' => 'taxonomies.view', 'name' => 'View taxonomies', 'group' => 'taxonomies', 'description' => 'View taxonomies and collections.'],
        ['code' => 'taxonomies.create', 'name' => 'Create taxonomies', 'group' => 'taxonomies', 'description' => 'Create genres, tags and collections.'],
        ['code' => 'taxonomies.edit', 'name' => 'Edit taxonomies', 'group' => 'taxonomies', 'description' => 'Edit taxonomies and collections.'],
        ['code' => 'taxonomies.delete', 'name' => 'Delete taxonomies', 'group' => 'taxonomies', 'description' => 'Delete taxonomies and collections.'],
        ['code' => 'commerce.view', 'name' => 'View commerce', 'group' => 'commerce', 'description' => 'View offers and pricing.'],
        ['code' => 'commerce.create_offers', 'name' => 'Create offers', 'group' => 'commerce', 'description' => 'Create offers and pricing rules.'],
        ['code' => 'commerce.edit_offers', 'name' => 'Edit offers', 'group' => 'commerce', 'description' => 'Edit offers and pricing rules.'],
        ['code' => 'commerce.view_billing', 'name' => 'View billing', 'group' => 'commerce', 'description' => 'View billing reports.'],
        ['code' => 'commerce.manage_costs', 'name' => 'Manage cost settings', 'group' => 'commerce', 'description' => 'Update platform-wide cost engine settings.'],
        ['code' => 'commerce.process_refunds', 'name' => 'Process refunds', 'group' => 'commerce', 'description' => 'Issue customer refunds.'],
        ['code' => 'exports.manage', 'name' => 'Manage exports', 'group' => 'exports', 'description' => 'Create export jobs and data dumps.'],
        ['code' => 'users.view', 'name' => 'View users', 'group' => 'users', 'description' => 'View users and invitations.'],
        ['code' => 'users.invite', 'name' => 'Invite users', 'group' => 'users', 'description' => 'Send user invitations.'],
        ['code' => 'users.edit', 'name' => 'Edit users', 'group' => 'users', 'description' => 'Edit users.'],
        ['code' => 'users.change_roles', 'name' => 'Change roles', 'group' => 'users', 'description' => 'Change user roles.'],
        ['code' => 'users.suspend', 'name' => 'Suspend users', 'group' => 'users', 'description' => 'Suspend or reactivate users.'],
        ['code' => 'users.delete', 'name' => 'Delete users', 'group' => 'users', 'description' => 'Delete users permanently.'],
        ['code' => 'cms.view', 'name' => 'View CMS pages', 'group' => 'cms', 'description' => 'View CMS pages.'],
        ['code' => 'cms.create', 'name' => 'Create CMS pages', 'group' => 'cms', 'description' => 'Create CMS pages.'],
        ['code' => 'cms.edit', 'name' => 'Edit CMS pages', 'group' => 'cms', 'description' => 'Edit CMS pages.'],
        ['code' => 'cms.publish', 'name' => 'Publish CMS pages', 'group' => 'cms', 'description' => 'Publish CMS pages.'],
        ['code' => 'cms.delete', 'name' => 'Delete CMS pages', 'group' => 'cms', 'description' => 'Delete CMS pages.'],
        ['code' => 'moderation.view_queue', 'name' => 'View moderation queue', 'group' => 'moderation', 'description' => 'View moderation queue.'],
        ['code' => 'moderation.approve', 'name' => 'Approve moderation items', 'group' => 'moderation', 'description' => 'Approve moderated items.'],
        ['code' => 'moderation.reject', 'name' => 'Reject moderation items', 'group' => 'moderation', 'description' => 'Reject moderated items.'],
        ['code' => 'moderation.view_audit_log', 'name' => 'View audit log', 'group' => 'moderation', 'description' => 'View moderation audit logs.'],
        ['code' => 'settings.view', 'name' => 'View settings', 'group' => 'settings', 'description' => 'View admin settings.'],
        ['code' => 'settings.edit_home_curation', 'name' => 'Edit home curation', 'group' => 'settings', 'description' => 'Edit home page curation.'],
        ['code' => 'settings.edit_search_config', 'name' => 'Edit search config', 'group' => 'settings', 'description' => 'Edit search and discovery config.'],
        ['code' => 'settings.manage_roles', 'name' => 'Manage roles', 'group' => 'settings', 'description' => 'Manage roles and permissions.'],
        ['code' => 'playback.view_sessions', 'name' => 'View playback sessions', 'group' => 'playback', 'description' => 'View playback sessions.'],
        ['code' => 'playback.revoke_tokens', 'name' => 'Revoke playback tokens', 'group' => 'playback', 'description' => 'Revoke playback tokens.'],
        ['code' => 'advertising.view', 'name' => 'View advertising', 'group' => 'advertising', 'description' => 'View ad campaigns and ad analytics.'],
        ['code' => 'advertising.manage', 'name' => 'Manage advertising', 'group' => 'advertising', 'description' => 'Create, update and delete ad campaigns.'],
    ];

    public function run(): void
    {
        DB::transaction(function (): void {
            foreach ($this->permissions as $permission) {
                Permission::query()->updateOrCreate(
                    ['code' => $permission['code']],
                    [...$permission, 'is_system' => true],
                );
            }

            $viewer = Role::query()->updateOrCreate(
                ['name' => 'Viewer'],
                [
                    'description' => 'Customer role for buying and watching titles.',
                    'is_system' => true,
                    'is_default' => true,
                    'admin_panel_access' => false,
                ],
            );

            $admin = Role::query()->updateOrCreate(
                ['name' => 'Admin'],
                [
                    'description' => 'Base admin role with access to the backoffice.',
                    'is_system' => true,
                    'is_default' => false,
                    'admin_panel_access' => true,
                ],
            );

            $producer = Role::query()->updateOrCreate(
                ['name' => 'Producer'],
                [
                    'description' => 'Scoped admin role that can view only assigned films and their stats.',
                    'is_system' => true,
                    'is_default' => false,
                    'admin_panel_access' => true,
                ],
            );

            $viewer->permissions()->sync(
                Permission::query()
                    ->whereIn('code', [
                        'storefront.access',
                        'wallet.top_up',
                        'content.purchase',
                        'content.watch',
                        'profile.manage',
                    ])
                    ->pluck('id')
                    ->all(),
            );

            $admin->permissions()->sync(
                Permission::query()
                    ->whereIn('code', [
                        'admin.access',
                        'content.view',
                        'content.create',
                        'content.edit',
                        'content.publish',
                        'content.archive',
                        'content.delete',
                        'media.view',
                        'media.upload',
                        'taxonomies.view',
                        'taxonomies.create',
                        'taxonomies.edit',
                        'taxonomies.delete',
                        'commerce.view',
                        'commerce.create_offers',
                        'commerce.edit_offers',
                        'commerce.view_billing',
                        'commerce.manage_costs',
                        'exports.manage',
                        'users.view',
                        'users.invite',
                        'users.edit',
                        'users.change_roles',
                        'users.suspend',
                        'cms.view',
                        'cms.create',
                        'cms.edit',
                        'cms.publish',
                        'moderation.view_queue',
                        'moderation.approve',
                        'moderation.reject',
                        'moderation.view_audit_log',
                        'settings.view',
                        'settings.edit_home_curation',
                        'settings.edit_search_config',
                        'settings.manage_roles',
                        'playback.view_sessions',
                        'playback.revoke_tokens',
                        'advertising.view',
                        'advertising.manage',
                    ])
                    ->pluck('id')
                    ->all(),
            );

            $producer->permissions()->sync(
                Permission::query()
                    ->whereIn('code', [
                        'admin.access',
                        'content.view',
                        'content.scope_assigned',
                        'commerce.view_billing',
                        'playback.view_sessions',
                        'advertising.view',
                    ])
                    ->pluck('id')
                    ->all(),
            );

            $adminUser = User::query()->updateOrCreate(
                ['email' => 'admin@filmoteca.md'],
                [
                    'name' => 'Filmoteca.md Admin',
                    'password' => 'password',
                    'preferred_locale' => 'ro',
                    'status' => 'active',
                    'email_verified_at' => now(),
                    'last_seen_at' => now(),
                ],
            );

            $adminUser->roles()->sync([$admin->id]);
        });
    }
}
