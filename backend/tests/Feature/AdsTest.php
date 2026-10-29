<?php

namespace Tests\Feature;

use App\Models\Ad;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AdsTest extends TestCase
{
    public function test_manager_manages_adverts_and_shop_shows_only_live_ones(): void
    {
        Storage::fake('public');
        $manager = $this->staff('manager');
        // A real 1x1 PNG (the GD-based fake image generator isn't available on this PHP).
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8DwHwAFBQIAX8jx0gAAAABJRU5ErkJggg==');
        $img = fn (string $name = 'banner.png') => UploadedFile::fake()->createWithContent($name, $png);

        $live = $this->actingAs($manager)->post('/api/admin/ads', [
            'title' => 'Back to school', 'placement' => 'hero', 'link_url' => '/c/computing', 'image' => $img(), 'image_mobile' => $img('m.png'),
        ], ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('status', 'live')->json();
        Storage::disk('public')->assertExists($live['image_path']);

        $this->actingAs($manager)->post('/api/admin/ads', ['title' => 'Off', 'placement' => 'hero', 'is_active' => '0', 'image' => $img()], ['Accept' => 'application/json'])
            ->assertCreated()->assertJsonPath('status', 'off');
        $this->actingAs($manager)->post('/api/admin/ads', ['title' => 'Next week', 'placement' => 'strip', 'starts_at' => now()->addWeek()->toDateTimeString(), 'image' => $img()], ['Accept' => 'application/json'])
            ->assertCreated()->assertJsonPath('status', 'scheduled');
        $this->actingAs($manager)->post('/api/admin/ads', ['title' => 'Over', 'placement' => 'strip', 'starts_at' => now()->subWeek()->toDateTimeString(), 'ends_at' => now()->subDay()->toDateTimeString(), 'image' => $img()], ['Accept' => 'application/json'])
            ->assertCreated()->assertJsonPath('status', 'ended');
        $this->actingAs($manager)->post('/api/admin/ads', ['title' => 'Bad link', 'placement' => 'hero', 'link_url' => 'javascript:alert(1)', 'image' => $img()], ['Accept' => 'application/json'])
            ->assertStatus(422);

        Cache::flush();
        $home = $this->getJson('/api/shop/home')->assertOk();
        $this->assertSame(['Back to school'], array_column($home->json('ads.hero') ?? [], 'title'));
        $this->assertNull($home->json('ads.strip'), 'scheduled and ended adverts are not shown');

        $this->postJson("/api/shop/ads/{$live['id']}/click")->assertNoContent();
        $this->assertSame(1, Ad::find($live['id'])->clicks);

        // Switching it off removes it from the shop straight away.
        $this->actingAs($manager)->post("/api/admin/ads/{$live['id']}", ['title' => 'Back to school', 'placement' => 'hero', 'is_active' => '0'], ['Accept' => 'application/json'])->assertOk();
        $this->assertNull($this->getJson('/api/shop/home')->json('ads.hero'));

        $this->actingAs($this->staff('cashier'))->getJson('/api/admin/ads')->assertForbidden();
        $this->actingAs($manager)->deleteJson("/api/admin/ads/{$live['id']}")->assertNoContent();
        Storage::disk('public')->assertMissing($live['image_path']);
    }
}
