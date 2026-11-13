<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Change alerts: what each person wants per workspace, and every digest that went out. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alert_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->boolean('daily')->default(false);
            $table->boolean('weekly')->default(true);
            $table->boolean('email')->default(true);
            $table->boolean('in_app')->default(true);
            $table->unsignedSmallInteger('min_change')->default(20);   // % change that counts as big
            $table->unsignedInteger('min_clicks')->default(50);        // ignore sites/pages below this baseline
            $table->json('property_ids')->nullable();                  // null = every property
            $table->timestamps();
            $table->unique(['team_id', 'user_id']);
        });

        Schema::create('alert_digests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('period');                                  // daily, weekly
            $table->date('period_end');
            $table->json('items');
            $table->boolean('emailed')->default(false);
            $table->text('error')->nullable();
            $table->timestamps();
            $table->unique(['team_id', 'user_id', 'period', 'period_end']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alert_digests');
        Schema::dropIfExists('alert_preferences');
    }
};
