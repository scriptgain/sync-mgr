<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::table('devices', function (Blueprint $t) {
            if (! Schema::hasColumn('devices', 'enrollment_plain')) {
                $t->string('enrollment_plain')->nullable()->after('enrollment_token');
            }
        });
    }
    public function down(): void {
        Schema::table('devices', fn (Blueprint $t) => $t->dropColumn('enrollment_plain'));
    }
};
