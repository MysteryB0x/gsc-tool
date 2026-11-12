<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Accounts, workspaces (teams), plans, credits and the audit log — the same model as WhoCitedMe,
 * so the two apps can later share one core package.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_admin')->default(false)->after('password');
        });

        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();                                   // owner, starter, pro, agency
            $table->string('name');
            $table->unsignedInteger('price_monthly_cents')->default(0);
            $table->unsignedInteger('price_yearly_cents')->default(0);
            $table->unsignedSmallInteger('max_properties')->nullable();         // null = unlimited
            $table->unsignedSmallInteger('max_bulk_properties')->nullable();    // properties on full BigQuery storage
            $table->unsignedSmallInteger('max_members')->nullable();
            $table->unsignedInteger('monthly_credits')->default(0);            // granted at the start of each period
            $table->boolean('unlimited_credits')->default(false);              // the owner's plan: logged, never charged
            $table->unsignedSmallInteger('trial_days')->default(0);
            $table->boolean('is_public')->default(true);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::create('teams', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->foreignId('plan_id')->nullable()->constrained()->nullOnDelete();
            $table->string('subscription_status')->default('active');          // trialing, active, past_due, paused, canceled
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('current_period_ends_at')->nullable();
            $table->integer('credit_balance')->default(0);
            $table->timestamp('credits_granted_at')->nullable();
            $table->text('admin_notes')->nullable();
            $table->timestamps();
        });

        Schema::create('team_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role')->default('owner');                          // owner, member, viewer
            $table->timestamps();
            $table->unique(['team_id', 'user_id']);
        });

        Schema::create('credit_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->integer('amount');                                         // + granted / bought / released, - used / reserved
            $table->string('reason');                                          // plan_grant, purchase, admin_grant, bq_reserve, bq_release, ai_brief …
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('reference')->nullable();                           // bq query id, payment id …
            $table->timestamps();
            $table->index(['team_id', 'created_at']);
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action');
            $table->nullableMorphs('subject');
            $table->json('meta')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('settings', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->text('value')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['settings', 'audit_logs', 'credit_transactions', 'team_user', 'teams', 'plans'] as $t) {
            Schema::dropIfExists($t);
        }
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('is_admin'));
    }
};
