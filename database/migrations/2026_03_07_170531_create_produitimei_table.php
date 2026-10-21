<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // database/migrations/xxxx_xx_xx_create_produit_imei_table.php

Schema::create('produit_imei', function (Blueprint $table) {
    $table->id();
    $table->foreignId('produit_id')->constrained('produit');
    $table->string('numero_imei', 17)->unique();
    $table->string('numero_serie', 100)->nullable();
    $table->foreignId('succursale_id')->nullable()->constrained('succursales');
    $table->date('date_entree');
    $table->date('date_sortie')->nullable();
    $table->date('date_achat')->nullable();
    $table->foreignId('id_client')->nullable()->constrained('clients');
    $table->foreignId('id_facture')->nullable()->constrained('facture');
    $table->integer('garanti_jour')->nullable();
    $table->date('date_debut_garanti')->nullable();
    $table->date('date_fin_garanti')->nullable();
    $table->decimal('prix_achat', 10, 2)->nullable();
    $table->decimal('prix_vente', 10, 2)->nullable();
    $table->string('emplacement', 150)->nullable();
    $table->text('note')->nullable();
    $table->string('statut', 50)->default('EN_STOCK');
    $table->timestamps();
    $table->softDeletes();
});
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('produitimei');
    }
};
