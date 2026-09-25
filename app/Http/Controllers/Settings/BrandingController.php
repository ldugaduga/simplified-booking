<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BrandingController extends Controller
{
    /**
     * Show the business branding settings page.
     */
    public function edit(): Response
    {
        $settings = Setting::current();

        return Inertia::render('settings/Branding', [
            'businessName' => $settings->business_name,
            'businessDescription' => $settings->business_description,
        ]);
    }

    /**
     * Update the business branding shown to visitors.
     */
    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'business_name' => ['nullable', 'string', 'max:255'],
            'business_description' => ['nullable', 'string', 'max:500'],
        ]);

        Setting::current()->update($validated);

        return back();
    }
}
