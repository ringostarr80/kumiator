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

    public function testTextAlignAvoidsFlowRelativeValues(): void
    {
        $html = $this->renderMail();

        $this->assertDoesNotMatchRegularExpression('/text-align: (start|end)/', $html);
        $this->assertMatchesRegularExpression('/<h1 [^>]*text-align: left/', $html);
    }

    public function testCenteredTablesRelyOnAlignAttributes(): void
    {
        $html = $this->renderMail();

        $this->assertDoesNotMatchRegularExpression('/<table class="action"[^>]*text-align/', $html);
        $this->assertDoesNotMatchRegularExpression('/<table class="footer"[^>]*text-align/', $html);
        // Die Attribute kommen aus Laravels Blade-Vorlagen, die kein Hash-Test bewacht; ohne sie wäre nichts
        // mehr zentriert
        $this->assertMatchesRegularExpression('/<table class="action"[^>]*>\s*<tr>\s*<td align="center"/', $html);
        $this->assertMatchesRegularExpression(
            '/<table class="footer"[^>]*>\s*<tr>\s*<td class="content-cell" align="center"/',
            $html,
        );
    }

    private function renderMail(): string
    {
        return (string) (new AccountApprovedNotification())->toMail(User::factory()->make())->render();
    }
}
