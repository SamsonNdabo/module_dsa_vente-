<?php
// database/migrations/[timestamp]_create_permission_tables.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
{
    $teams = config('permission.teams');
    $tableNames = config('permission.table_names');
    $columnNames = config('permission.column_names');
    $pivotRole = $columnNames['role_pivot_key'] ?? 'role_id';
    $pivotPermission = $columnNames['permission_pivot_key'] ?? 'permission_id';

    // ============================================================
    // TABLE PERMISSIONS - AVEC LONGUEURS RÉDUITES
    // ============================================================
    Schema::create($tableNames['permissions'], function (Blueprint $table) {
        $table->bigIncrements('id');
        
        // RÉDUIRE LA LONGUEUR DES CHAMPS INDEXÉS
        $table->string('name', 125);  // Réduit de 191 à 125
        $table->string('guard_name', 125)->default('web'); // Réduit
        
        // Vos champs personnalisés
        $table->string('permission_code', 50)->unique();
        $table->string('description')->nullable();
        $table->string('module', 50);
        $table->string('action', 50);
        $table->string('categorie', 50)->nullable();
        $table->boolean('est_systeme')->default(false);
        
        $table->timestamps();

        // Index avec longueur explicite (alternative)
        $table->unique(['name', 'guard_name'], 'permissions_name_guard_name_unique');
    });

    // ============================================================
    // TABLE ROLES - AVEC LONGUEURS RÉDUITES
    // ============================================================
    Schema::create($tableNames['roles'], function (Blueprint $table) use ($teams, $columnNames) {
        $table->bigIncrements('id');
        
        if ($teams || config('permission.testing')) {
            $table->unsignedBigInteger($columnNames['team_foreign_key'])->nullable();
            $table->index($columnNames['team_foreign_key'], 'roles_team_foreign_key_index');
        }
        
        // RÉDUIRE LA LONGUEUR
        $table->string('name', 125);
        $table->string('guard_name', 125)->default('web');
        
        // Vos champs personnalisés
        $table->string('code', 100)->unique();
        $table->string('nom_role', 150);
        $table->text('description')->nullable();
        $table->integer('niveau_acces')->default(1);
        $table->boolean('est_defaut')->default(false);
        $table->boolean('est_systeme')->default(false);
        $table->json('metadata')->nullable();
        
        $table->timestamps();

        // Index
        $table->index('code');
        $table->index('nom_role');
        
        if ($teams || config('permission.testing')) {
            $table->unique([$columnNames['team_foreign_key'], 'name', 'guard_name'], 'roles_team_name_guard_unique');
        } else {
            $table->unique(['name', 'guard_name'], 'roles_name_guard_unique');
        }
    });
        // ============================================================
        // TABLE MODEL HAS PERMISSIONS
        // ============================================================
        Schema::create($tableNames['model_has_permissions'], function (Blueprint $table) use ($tableNames, $columnNames, $pivotPermission, $teams) {
            $table->unsignedBigInteger($pivotPermission);

            $table->string('model_type');
            $table->unsignedBigInteger($columnNames['model_morph_key']);
            $table->index([$columnNames['model_morph_key'], 'model_type'], 'model_has_permissions_model_id_model_type_index');

            $table->foreign($pivotPermission)
                ->references('id')
                ->on($tableNames['permissions'])
                ->onDelete('cascade');

            if ($teams) {
                $table->unsignedBigInteger($columnNames['team_foreign_key']);
                $table->index($columnNames['team_foreign_key'], 'model_has_permissions_team_foreign_key_index');

                $table->primary([$columnNames['team_foreign_key'], $pivotPermission, $columnNames['model_morph_key'], 'model_type'],
                    'model_has_permissions_permission_model_type_primary');
            } else {
                $table->primary([$pivotPermission, $columnNames['model_morph_key'], 'model_type'],
                    'model_has_permissions_permission_model_type_primary');
            }
        });

        // ============================================================
        // TABLE MODEL HAS ROLES
        // ============================================================
        Schema::create($tableNames['model_has_roles'], function (Blueprint $table) use ($tableNames, $columnNames, $pivotRole, $teams) {
            $table->unsignedBigInteger($pivotRole);

            $table->string('model_type');
            $table->unsignedBigInteger($columnNames['model_morph_key']);
            $table->index([$columnNames['model_morph_key'], 'model_type'], 'model_has_roles_model_id_model_type_index');

            $table->foreign($pivotRole)
                ->references('id')
                ->on($tableNames['roles'])
                ->onDelete('cascade');

            if ($teams) {
                $table->unsignedBigInteger($columnNames['team_foreign_key']);
                $table->index($columnNames['team_foreign_key'], 'model_has_roles_team_foreign_key_index');

                $table->primary([$columnNames['team_foreign_key'], $pivotRole, $columnNames['model_morph_key'], 'model_type'],
                    'model_has_roles_role_model_type_primary');
            } else {
                $table->primary([$pivotRole, $columnNames['model_morph_key'], 'model_type'],
                    'model_has_roles_role_model_type_primary');
            }
        });

        // ============================================================
        // TABLE ROLE HAS PERMISSIONS (avec vos champs personnalisés)
        // ============================================================
        Schema::create($tableNames['role_has_permissions'], function (Blueprint $table) use ($tableNames, $pivotRole, $pivotPermission) {
            $table->unsignedBigInteger($pivotPermission);
            $table->unsignedBigInteger($pivotRole);
            
            // Vos champs personnalisés
            $table->timestamp('date_attribution')->useCurrent();
            $table->unsignedBigInteger('attribue_par')->nullable();
            $table->timestamps(); 

            $table->foreign($pivotPermission)
                ->references('id')
                ->on($tableNames['permissions'])
                ->onDelete('cascade');

            $table->foreign($pivotRole)
                ->references('id')
                ->on($tableNames['roles'])
                ->onDelete('cascade');
                
            $table->foreign('attribue_par')
                ->references('id')
                ->on('utilisateurs')
                ->onDelete('set null');

            $table->primary([$pivotPermission, $pivotRole], 'role_has_permissions_permission_id_role_id_primary');
            $table->index('created_at');
            $table->index('updated_at');
        });

        app('cache')
            ->store(config('permission.cache.store') != 'default' ? config('permission.cache.store') : null)
            ->forget(config('permission.cache.key'));
            
    }

    public function down(): void
    {
        $tableNames = config('permission.table_names');

        Schema::drop($tableNames['role_has_permissions']);
        Schema::drop($tableNames['model_has_roles']);
        Schema::drop($tableNames['model_has_permissions']);
        Schema::drop($tableNames['roles']);
        Schema::drop($tableNames['permissions']);
    }
};