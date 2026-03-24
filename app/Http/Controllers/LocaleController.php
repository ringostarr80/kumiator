<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;

class LocaleController extends Controller
{
    public function switch(string $locale): RedirectResponse
    {
        $supported = ['de', 'en'];

        if (in_array($locale, $supported, true)) {
            session(['locale' => $locale]);
        }

        return redirect()->back();
    }
}
