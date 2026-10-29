<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The dashboard cache key reads MAX(updated_at) on every hit; without an index that is a full scan.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', fn (Blueprint $t) => $t->index('updated_at'));
        Schema::table('expenses', fn (Blueprint $t) => $t->index('updated_at'));
    }

    public function down(): void
    {
        Schema::table('sales', fn (Blueprint $t) => $t->dropIndex(['updated_at']));
        Schema::table('expenses', fn (Blueprint $t) => $t->dropIndex(['updated_at']));
    }
};
