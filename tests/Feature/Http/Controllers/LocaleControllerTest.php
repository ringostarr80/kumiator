<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Controllers;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class LocaleControllerTest extends TestCase
{
    use RefreshDatabase;

    public function testLocaleCanBeSwitchedToEnglish(): void
    {
        $response = $this->get(route('locale.switch', ['locale' => 'en']));

        $response->assertRedirect();
        $this->assertEquals('en', session('locale'));
    }

    public function testLocaleCanBeSwitchedToGerman(): void
    {
        $response = $this->get(route('locale.switch', ['locale' => 'de']));

        $response->assertRedirect();
        $this->assertEquals('de', session('locale'));
    }

    public function testUnsupportedLocaleIsIgnored(): void
    {
        session(['locale' => 'de']);

        $response = $this->get(route('locale.switch', ['locale' => 'fr']));

        $response->assertRedirect();
        $this->assertEquals('de', session('locale'));
    }
}
