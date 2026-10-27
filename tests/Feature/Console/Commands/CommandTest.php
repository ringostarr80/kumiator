<?php

declare(strict_types=1);

namespace Tests\Feature\Console\Commands;

use App\Console\Commands\Command;
use App\Models\User;
use Closure;
use Illuminate\Console\OutputStyle;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Illuminate\Testing\PendingCommand;
use Override;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Question\Question;
use Tests\TestCase;

final class CommandTest extends TestCase
{
    private const string NAME = "<href=https://evil.test>Opfer</>\e[2K\u{202E}";

    public function testInvalidUtf8KeepsTheRestOfTheLine(): void
    {
        Artisan::registerCommand(new class extends Command {
            protected $signature = 'test:line';

            public function handle(): int
            {
                // „ü“ aus einem Terminal mit Latin-1-Locale
                $this->line("Benutzer gefunden: M\xFCller");

                return self::SUCCESS;
            }
        });

        Artisan::call('test:line');

        $this->assertSame('Benutzer gefunden: M?ller' . PHP_EOL, Artisan::output());
    }

    /**
     * @param Closure(): iterable<array-key, mixed> $rows
     */
    #[DataProvider('tableRowsProvider')]
    public function testTableNeutralisesEveryRowAndCellShape(Closure $rows): void
    {
        Artisan::registerCommand(new class ($rows()) extends Command {
            protected $signature = 'test:table';

            /**
             * @param iterable<array-key, mixed> $rows
             */
            public function __construct(private readonly iterable $rows)
            {
                parent::__construct();
            }

            public function handle(): int
            {
                $this->table(['Name'], $this->rows);

                return self::SUCCESS;
            }
        });

        Artisan::call('test:table');
        $output = Artisan::output();

        $this->assertStringContainsString('<href=https://evil.test>Opfer</>[2K', $output);
        $this->assertStringNotContainsString("\e", $output);
        $this->assertStringNotContainsString("\u{202E}", $output);
    }

    /**
     * @param Closure(Command): mixed $prompt
     */
    #[DataProvider('promptProvider')]
    public function testPromptsNeutraliseTheQuestion(Closure $prompt, string|bool $answer): void
    {
        Artisan::registerCommand(new class ($prompt) extends Command {
            protected $signature = 'test:prompt';

            /**
             * @param Closure(Command): mixed $prompt
             */
            public function __construct(private readonly Closure $prompt)
            {
                parent::__construct();
            }

            public function handle(): int
            {
                ($this->prompt)($this);

                return self::SUCCESS;
            }
        });

        $command = $this->artisan('test:prompt');
        $this->assertInstanceOf(PendingCommand::class, $command);

        $command
            ->expectsQuestion('\<href=https://evil.test\>Opfer\</\>[2K', $answer)
            ->assertSuccessful()
            ->run();
    }

    public function testChoiceStaysSingleSelect(): void
    {
        Artisan::registerCommand(new class extends Command {
            protected $signature = 'test:choice';

            public function handle(): int
            {
                return $this->choice('Rolle', ['admin', 'member'], 0) === 'admin'
                    ? self::SUCCESS
                    : self::FAILURE;
            }
        });

        // Ohne Interaktion gibt Symfony den Default zurück, bei Mehrfachauswahl als Array.
        $this->assertSame(Command::SUCCESS, Artisan::call('test:choice', ['--no-interaction' => true]));
    }

    public function testSecretKeepsTheVisibleFallback(): void
    {
        // Der Fallback wirkt erst auf einem Terminal ohne `stty`, das kein Test
        // herstellen kann. Prüfbar ist nur die `Question`, die `secret()` stellt.
        $output = new class (new ArrayInput([]), new BufferedOutput()) extends OutputStyle {
            private ?Question $askedQuestion = null;

            #[Override]
            public function askQuestion(Question $question): mixed
            {
                $this->askedQuestion = $question;

                return '';
            }

            public function askedQuestion(): ?Question
            {
                return $this->askedQuestion;
            }
        };
        $command = new class extends Command {
            protected $signature = 'test:secret';
        };
        $command->setOutput($output);

        $command->secret('Passwort');

        $this->assertTrue($output->askedQuestion()?->isHiddenFallback());
    }

    /**
     * @return iterable<string, array{Closure(): iterable<array-key, mixed>}>
     */
    public static function tableRowsProvider(): iterable
    {
        yield 'Collection als Zeile' => [static fn (): iterable => collect([collect([self::NAME])])];
        yield 'Model als Zeile' => [static fn (): iterable => collect([new User(['name' => self::NAME])])];
        yield 'Stringable als Zelle' => [static fn (): iterable => [[Str::of(self::NAME)]]];
    }

    /**
     * @return iterable<string, array{Closure(Command): mixed, string|bool}>
     */
    public static function promptProvider(): iterable
    {
        yield 'ask()' => [static fn (Command $command): mixed => $command->ask(self::NAME), ''];
        yield 'askWithCompletion()' => [
            static fn (Command $command): mixed => $command->askWithCompletion(self::NAME, []),
            '',
        ];
        yield 'choice()' => [static fn (Command $command): mixed => $command->choice(self::NAME, ['admin']), 'admin'];
        yield 'confirm()' => [static fn (Command $command): mixed => $command->confirm(self::NAME), false];
        yield 'secret()' => [static fn (Command $command): mixed => $command->secret(self::NAME), ''];
    }
}
