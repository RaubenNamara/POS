<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Ad;
use App\Services\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

/** Adverts shown on the storefront home page. */
class AdController extends Controller
{
    public function index()
    {
        return Ad::orderBy('placement')->orderBy('sort_order')->orderByDesc('id')->get()
            ->map(fn (Ad $a) => $a->toArray() + ['status' => $a->status()]);
    }

    public function store(Request $request)
    {
        $d = $this->validated($request, true);
        $ad = new Ad($d);
        $this->storeImages($request, $ad);
        $ad->save();
        Audit::log('ad.created', $ad, null, ['title' => $ad->title, 'placement' => $ad->placement]);
        $this->bump();

        return response()->json($ad->toArray() + ['status' => $ad->status()], 201);
    }

    /** Multipart form, so updates arrive as POST /ads/{id}. */
    public function update(Request $request, Ad $ad)
    {
        $d = $this->validated($request, false);
        $ad->fill($d);
        $this->storeImages($request, $ad);
        if ($request->boolean('remove_mobile_image') && $ad->image_mobile_path) {
            Storage::disk('public')->delete($ad->image_mobile_path);
            $ad->image_mobile_path = null;
        }
        $ad->save();
        Audit::log('ad.updated', $ad, null, ['title' => $ad->title, 'is_active' => $ad->is_active]);
        $this->bump();

        return $ad->toArray() + ['status' => $ad->status()];
    }

    public function destroy(Ad $ad)
    {
        Storage::disk('public')->delete(array_filter([$ad->image_path, $ad->image_mobile_path]));
        Audit::log('ad.deleted', $ad, ['title' => $ad->title]);
        $ad->delete();
        $this->bump();

        return response()->noContent();
    }

    private function validated(Request $request, bool $creating): array
    {
        $d = $request->validate([
            'title' => 'required|string|max:120',
            'subtitle' => 'nullable|string|max:200',
            'placement' => 'required|in:hero,strip',
            // Inside the shop ("/p/...", "/c/...", "/search?...") or a full https:// address.
            'link_url' => ['nullable', 'string', 'max:500', 'regex:#^(/[^\s]*|https?://[^\s]+)$#'],
            'show_text' => 'nullable|boolean',
            'sort_order' => 'nullable|integer|min:0|max:1000',
            'starts_at' => 'nullable|date',
            'ends_at' => 'nullable|date|after:starts_at',
            'is_active' => 'nullable|boolean',
            'image' => [$creating ? 'required' : 'nullable', 'file', 'mimes:webp,jpg,jpeg,png,avif', 'max:4096'],
            'image_mobile' => 'nullable|file|mimes:webp,jpg,jpeg,png,avif|max:2048',
            'width' => 'nullable|integer|max:4000', 'height' => 'nullable|integer|max:4000',
        ], ['link_url.regex' => 'Link must start with "/" (a page in this shop) or with https://']);
        unset($d['image'], $d['image_mobile']);
        $d['show_text'] = $request->boolean('show_text');
        $d['is_active'] = $request->has('is_active') ? $request->boolean('is_active') : true;

        return $d;
    }

    private function storeImages(Request $request, Ad $ad): void
    {
        if ($request->hasFile('image')) {
            if ($ad->image_path) {
                Storage::disk('public')->delete($ad->image_path);
            }
            $ad->image_path = $request->file('image')->store('ads', 'public');
        }
        if ($request->hasFile('image_mobile')) {
            if ($ad->image_mobile_path) {
                Storage::disk('public')->delete($ad->image_mobile_path);
            }
            $ad->image_mobile_path = $request->file('image_mobile')->store('ads', 'public');
        }
    }

    /** Ads are part of the cached home page: refresh it. */
    private function bump(): void
    {
        Cache::increment('catalog_version');
    }
}
