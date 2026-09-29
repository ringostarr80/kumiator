<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Config\SecurityTxtConfig;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Config;
use UnexpectedValueException;

/**
 * Eine security.txt gilt nur für die Domain, unter der sie abgerufen wird
 * (RFC 9116, Abschnitt 3.1). Jede Instanz erzeugt ihre eigene deshalb aus der
 * Konfiguration ihres Betreibers.
 */
final class SecurityTxtController extends Controller
{
    private const string PRODUCT_CONTACT = 'https://github.com/ringostarr80/kumiator/security/advisories/new';

    private const string POLICY = 'https://github.com/ringostarr80/kumiator/blob/main/SECURITY.md';

    public function __invoke(): Response
    {
        $contact = SecurityTxtConfig::contact() ?? abort(Response::HTTP_NOT_FOUND);
        $expires = SecurityTxtConfig::expires() ?? abort(Response::HTTP_NOT_FOUND);
        $appUrl = Config::string('app.url');

        // RFC 9116 verlangt für Canonical https (Abschnitt 2.5.2). Eine
        // http-URI passt nie zur Abruf-URI, und dann gilt die ganze Datei als
        // nicht vertrauenswürdig.
        if (!str_starts_with($appUrl, 'https://')) {
            throw new UnexpectedValueException(
                'APP_URL must start with https:// for the security.txt Canonical field: ' . $appUrl,
            );
        }

        $lines = [
            '# Operator of this instance: server, hosting, configuration',
            'Contact: ' . $contact,
            '# Vulnerabilities in the Kumiator software itself',
            'Contact: ' . self::PRODUCT_CONTACT,
            'Expires: ' . $expires->utc()->format('Y-m-d\TH:i:s\Z'),
            'Preferred-Languages: de, en',
            // Aus APP_URL statt aus dem Request: Canonical nennt den festen Ort
            // der Datei, nicht den Host, über den sie gerade abgerufen wurde.
            'Canonical: ' . rtrim($appUrl, '/') . '/.well-known/security.txt',
            'Policy: ' . self::POLICY,
        ];

        return response(
            implode("\n", $lines) . "\n",
            Response::HTTP_OK,
            ['Content-Type' => 'text/plain; charset=utf-8'],
        );
    }
}
