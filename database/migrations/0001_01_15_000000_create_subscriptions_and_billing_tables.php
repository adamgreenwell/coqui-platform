<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspaces', function (Blueprint $table): void {
            $table->string('stripe_id')->nullable()->index()->after('owner_id');
            $table->string('stripe_subscription_id')->nullable()->after('stripe_id');
            $table->string('subscription_status', 32)->default('active')->after('stripe_subscription_id');
            $table->boolean('has_runnr_addon')->default(false)->after('subscription_status');
            $table->timestamp('current_period_end')->nullable()->after('has_runnr_addon');
        });

        Schema::create('workspace_subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->string('type', 64)->default('default');
            $table->string('stripe_id')->unique();
            $table->string('stripe_status', 32);
            $table->string('stripe_price')->nullable();
            $table->unsignedInteger('quantity')->nullable();
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'stripe_status']);
        });

        Schema::create('billing_invoices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->string('stripe_invoice_id')->unique();
            $table->decimal('amount_paid_usd', 10, 2)->default(0.00);
            $table->string('currency', 10)->default('usd');
            $table->string('status', 32)->default('paid');
            $table->string('invoice_pdf_url')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_invoices');
        Schema::dropIfExists('workspace_subscriptions');

        Schema::table('workspaces', function (Blueprint $table): void {
            $table->dropColumn([
                'stripe_id',
                'stripe_subscription_id',
                'subscription_status',
                'has_runnr_addon',
                'current_period_end',
            ]);
        });
    }
};
