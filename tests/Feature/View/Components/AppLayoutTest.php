<?php

declare(strict_types=1);

namespace Tests\Feature\View\Components;

use App\View\Components\AppLayout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\View\View;
use Tests\TestCase;

final class AppLayoutTest extends TestCase
{
    use RefreshDatabase;

    public function testAppLayoutRendersCorrectView(): void
    {
        $component = new AppLayout();
        $view = $component->render();

        $this->assertInstanceOf(View::class, $view);
        $this->assertEquals('layouts.app', $view->name());
    }
}
