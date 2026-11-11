<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Models\User;
use App\Notifications\AccountApprovedNotification;
use Tests\TestCase;

final class MailThemeTest extends TestCase
{
    public function testMailsCarryNoPosition(): void
    {
        $html = $this->renderMail();

        $this->assertStringNotContainsString('position:', $html);
        // Gegenprobe: Ohne sie bestünde der Test auch, wenn gar kein Theme mehr inline landet
        $this->assertStringContainsString('font-family:', $html);
    }

    public function testOnlyTheButtonCarriesBoxSizing(): void
    {
        $html = $this->renderMail();

        $this->assertSame(1, substr_count($html, 'box-sizing:'));
        $this->assertMatchesRegularExpression('/<a [^>]*class="button [^>]*box-sizing: border-box/', $html);
    }

    private function renderMail(): string
    {
        return (string) (new AccountApprovedNotification())->toMail(User::factory()->make())->render();
    }
}
