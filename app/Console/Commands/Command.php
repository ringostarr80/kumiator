<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command as IlluminateCommand;
use Override;
use Stringable;
use Symfony\Component\Console\Formatter\OutputFormatter;

/**
 * Namen und Adressen in der Ausgabe kann sich jeder bei der Registrierung
 * selbst geben. Das Terminal führte Steuersequenzen darin aus, ein
 * bidi-fähiges ordnete nach Embeddings, Overrides und Isolates den Rest der
 * Zeile um, und Symfony wertete Tags wie `<href=…>` aus. All das verfälschte
 * die Ansicht, nach der der Admin Konten freigibt, löscht und Rollen vergibt.
 *
 * Neutralisiert wird jede Zeile im Ganzen, nicht nur die eingesetzten Werte.
 * Eigene Tags in Übersetzungen erscheinen deshalb wörtlich.
 *
 * Das gilt für alles, was über `line()` und `table()` läuft, und für die
 * Fragetexte der Prompt-Methoden dieser Klasse. Die Einträge von `choice()`
 * bleiben roh, weil Symfony den gewählten Eintrag zurückgibt und ein
 * maskierter nicht mehr zum gespeicherten Wert passte. Alles andere, etwa
 * `components`, die Output-Instanz oder `fail()`, schreibt ungefiltert.
 * Gespeicherte Daten gehören in keinen dieser Wege.
 */
abstract class Command extends IlluminateCommand
{
    #[Override]
    public function line($string, $style = null, $verbosity = null): void
    {
        parent::line($this->neutralise($string), $style, $verbosity);
    }

    /**
     * @param array<mixed> $headers
     * @param \Illuminate\Contracts\Support\Arrayable<array-key, mixed>|iterable<array-key, mixed> $rows
     */
    #[Override]
    public function table($headers, $rows, $tableStyle = 'default', array $columnStyles = []): void
    {
        // `toArray()` macht auch Model- und Collection-Zeilen zu Arrays, die
        // einzige Zeilenform, die Symfony annimmt.
        $rows = array_map(
            fn (mixed $row): mixed => is_array($row)
                ? array_map($this->neutraliseCell(...), $row)
                : $row,
            collect($rows)->toArray(),
        );

        parent::table($headers, $rows, $tableStyle, $columnStyles);
    }

    #[Override]
    public function ask($question, $default = null): mixed
    {
        return parent::ask($this->neutralise($question), $default);
    }

    /**
     * @param iterable<mixed>|(callable(string): list<string>) $choices
     */
    #[Override]
    public function askWithCompletion($question, $choices, $default = null): mixed
    {
        return parent::askWithCompletion($this->neutralise($question), $choices, $default);
    }

    /**
     * @return string|array<mixed>
     */
    #[Override]
    public function choice($question, array $choices, $default = null, $attempts = null, $multiple = false): mixed
    {
        return parent::choice($this->neutralise($question), $choices, $default, $attempts, $multiple);
    }

    #[Override]
    public function confirm($question, $default = false): bool
    {
        return parent::confirm($this->neutralise($question), $default);
    }

    #[Override]
    public function secret($question, $fallback = true): mixed
    {
        return parent::secret($this->neutralise($question), $fallback);
    }

    private function neutraliseCell(mixed $cell): mixed
    {
        // Symfony gibt auch `Stringable`-Zellen als Text aus.
        return is_string($cell) || $cell instanceof Stringable
            ? $this->neutralise((string) $cell)
            : $cell;
    }

    private function neutralise(string $text): string
    {
        // `mb_scrub()` ersetzt ungültige Bytes durch `?`. Sonst scheiterte PCRE
        // an ihnen, und die ganze Zeile ginge verloren.
        return OutputFormatter::escape(
            preg_replace('/[' . User::DISPLAY_CONTROL_CHARACTERS . ']/u', '', mb_scrub($text, 'UTF-8')) ?? '',
        );
    }
}
