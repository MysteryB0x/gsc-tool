<?php

use App\Search\SiteKey;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Each workspace gets its own Google service account (impersonated by the app's account, no extra keys),
 * so access granted in Search Console / GA4 proves which workspace it was granted to.
 * A site can belong to one workspace, unless an admin lets that workspace share.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->string('google_account_email')->nullable()->after('credits_granted_at');
            $table->string('google_account_status')->nullable()->after('google_account_email'); // pending, ready, failed
            $table->text('google_account_error')->nullable()->after('google_account_status');
            $table->boolean('uses_app_account')->default(false)->after('google_account_error');     // the owner's workspace: the app's own account
            $table->boolean('may_share_properties')->default(false)->after('uses_app_account');
        });

        Schema::table('properties', function (Blueprint $table) {
            $table->string('site_key')->nullable()->after('site_url')->index();                      // example.nl for sc-domain:example.nl, https://www.example.nl/ …
        });

        foreach (DB::table('properties')->get(['id', 'site_url']) as $p) {
            DB::table('properties')->where('id', $p->id)->update(['site_key' => SiteKey::of($p->site_url)]);
        }
    }

    public function down(): void
    {
        Schema::table('properties', fn (Blueprint $table) => $table->dropColumn('site_key'));
        Schema::table('teams', fn (Blueprint $table) => $table->dropColumn(['google_account_email', 'google_account_status', 'google_account_error', 'uses_app_account', 'may_share_properties']));
    }
};
