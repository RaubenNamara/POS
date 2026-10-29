<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_exports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('report', 40);
            $table->string('format', 5); // csv | xlsx | pdf
            $table->json('params')->nullable();
            $table->string('status', 10)->default('queued'); // queued | running | done | failed
            $table->string('path')->nullable();
            $table->unsignedInteger('rows')->nullable();
            $table->string('error')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_exports');
    }
};
