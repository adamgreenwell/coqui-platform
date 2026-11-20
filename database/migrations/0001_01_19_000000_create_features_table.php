<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Laravel\Pennant\Migrations\PennantMigration;

/**
 * Laravel Pennant's feature store. Pennant defaults to the database driver, and
 * the runnr-integration flag keeps its on/off value here.
 */
return new class extends PennantMigration
{
    public function up(): void
    {
        if (Schema::hasTable('features')) {
            return;
        }

        Schema::create('features', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('scope');
            $table->text('value');
            $table->timestamps();

            $table->unique(['name', 'scope']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('features');
    }
};
