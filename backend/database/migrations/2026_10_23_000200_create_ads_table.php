<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Shop-managed adverts: the home-page banner slider and wide banners between sections. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ads', function (Blueprint $t) {
            $t->id();
            $t->string('title', 120);              // alt text / admin label (also shown on the banner if set to)
            $t->string('subtitle', 200)->nullable();
            $t->string('placement', 10)->default('hero'); // hero = top slider | strip = wide banner between sections
            $t->string('image_path');              // wide picture (computers / tablets)
            $t->string('image_mobile_path')->nullable(); // optional taller picture for phones
            $t->unsignedSmallInteger('width')->nullable();
            $t->unsignedSmallInteger('height')->nullable();
            $t->string('link_url', 500)->nullable(); // "/p/slug", "/c/slug", "/search?..." or https://...
            $t->boolean('show_text')->default(false); // draw title/subtitle over the picture
            $t->unsignedInteger('sort_order')->default(0);
            $t->timestamp('starts_at')->nullable();
            $t->timestamp('ends_at')->nullable();
            $t->boolean('is_active')->default(true);
            $t->unsignedInteger('clicks')->default(0);
            $t->timestamps();
            $t->index(['placement', 'is_active', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ads');
    }
};
