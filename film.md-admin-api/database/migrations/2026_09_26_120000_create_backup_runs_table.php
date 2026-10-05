<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backup_runs', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 64)->unique();
            $table->string('trigger', 16)->default('manual');
            $table->string('type', 16)->default('backup');
            $table->foreignId('source_run_id')->nullable()->constrained('backup_runs')->nullOnDelete();
            $table->string('status', 16)->default('queued');
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('components')->nullable();
            $table->json('artifacts')->nullable();
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->boolean('encrypted')->default(false);
            $table->string('remote_status', 16)->default('skipped');
            $table->string('remote_path')->nullable();
            $table->text('remote_error')->nullable();
            $table->boolean('is_locked')->default(false);
            $table->string('note', 500)->nullable();
            $table->text('error_message')->nullable();
            $table->longText('log')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('files_deleted_at')->nullable();
            $table->timestamps();

            $table->index(['type', 'status', 'created_at']);
        });

        $permissionId = DB::table('permissions')->where('code', 'settings.manage_backups')->value('id')
            ?? DB::table('permissions')->insertGetId([
                'code' => 'settings.manage_backups',
                'name' => 'Manage backups',
                'group' => 'settings',
                'description' => 'Configure, run, download and delete platform backups.',
                'is_system' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

        $adminRoleId = DB::table('roles')->where('name', 'Admin')->value('id');
        if ($adminRoleId !== null) {
            DB::table('permission_role')->updateOrInsert(
                ['permission_id' => $permissionId, 'role_id' => $adminRoleId],
                ['created_at' => now(), 'updated_at' => now()],
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('backup_runs');

        $permissionId = DB::table('permissions')->where('code', 'settings.manage_backups')->value('id');
        if ($permissionId !== null) {
            DB::table('permission_role')->where('permission_id', $permissionId)->delete();
            DB::table('permissions')->where('id', $permissionId)->delete();
        }
    }
};
