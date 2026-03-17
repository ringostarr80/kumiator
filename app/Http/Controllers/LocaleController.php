<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class LocaleController extends Controller
{
    public function switch(string $locale)
    {
        $supported = ['de', 'en'];

        if (in_array($locale, $supported)) {
            session(['locale' => $locale]);
        }

        return redirect()->back();
    }
}
