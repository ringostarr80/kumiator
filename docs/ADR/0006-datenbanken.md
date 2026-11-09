# ADR-0006: Unterstützte Datenbanken

**Datum:** 2026-11-06

**Status:** Akzeptiert

## Kontext

Die Tests liefen ausschließlich gegen SQLite und waren dort grün. Ein Probelauf gegen MariaDB und PostgreSQL zeigte, was SQLite verdeckt: `migrate` brach auf beiden Servern ab, und nachdem das umgangen war, scheiterten jeweils rund 200 Tests. SQLite speichert eine UUID klaglos in einer numerischen Spalte, setzt `varchar`-Längen nicht durch und vergleicht Text anders als die Server. Welche Datenbanken die App unterstützt, muss vor den Fachmigrationen feststehen, denn jede weitere Migration verteuert das Nachziehen.

## Betrachtete Alternativen

Laravel bringt Treiber für fünf Datenbanken mit:

- **SQLite** — eingebettet, ohne eigenen Server
- **MariaDB** und **MySQL** — Open Source, MariaDB ist aus MySQL hervorgegangen
- **PostgreSQL** — Open Source
- **SQL Server** — proprietär

Nur über Pakete außerhalb des Frameworks, die Laravel 13 unterstützen:

- **Oracle** (`yajra/laravel-oci8`) — proprietär
- **SingleStore** (`singlestoredb/singlestoredb-laravel`)
- **MongoDB** (`mongodb/laravel-mongodb`) — dokumentenorientiert, nicht relational

Für IBM Db2, Firebird und libSQL fand sich am 2026-11-09 kein Paket, das Laravel 13 unterstützt.

## Entscheidung

Ich habe mich für **SQLite, MariaDB und PostgreSQL** entschieden. Die CI testet alle drei, die übrigen Datenbanken werden nicht unterstützt.

### Gründe

- **SQLite** bleibt die Vorgabe: Entwicklung und kleine Instanzen kommen ohne eigenen Datenbankserver aus. Nur SQLite hätte die App aber auf Hosting mit eigenem Dateisystem beschränkt.
- **MariaDB** ist bei Webhostern verbreitet.
- **PostgreSQL** ist am strengsten: Es vergleicht `bigint` nicht mit `uuid`, unterscheidet bei `like` Groß- und Kleinschreibung und bricht nach einem fehlgeschlagenen Statement die ganze Transaktion ab. Im Probelauf deckte es die meisten Fehler auf.
- **MySQL** brächte für die einfachen Abfragen der App neben MariaDB wenig. Ohne eigenen CI-Job wäre seine Unterstützung nur behauptet.
- **SQL Server und Oracle** sind nicht Open Source. Kostenlos gibt es sie nur mit Grenzen: SQL Server Express mit 50 GB je Datenbank, Oracle Free mit 12 GB Nutzdaten, bei ungeklärtem Einsatz in Produktion. Der Aufwand, sie zusätzlich zu portieren und in der CI zu testen, lohnt sich für ein Open-Source-Projekt wie dieses nicht.
- **SingleStore** hängt an einem Fremdpaket: Jedes Laravel-Update müsste zusätzlich auf dessen Release warten.
- **MongoDB** passt nicht: Die Migrationen, `whereHasMorph` und die rohen SQL-Ausdrücke der Namenssuche setzen eine relationale Datenbank voraus. Das wäre ein Umbau, keine Portierung.

## Konsequenzen

- Die Jobs `Tests (mariadb)` und `Tests (pgsql)` laufen neben den SQLite-Tests, auf einer PHP-Version und ohne Coverage. Getestet wird je eine Version, die Images stehen in `docker/test-databases/compose.yml`. Ältere Versionen sind kein Versprechen.
- Mutationstests, Browser-Tests und die lokale Verifikations-Pipeline bleiben auf SQLite. Infection meldet Mutanten in Zweigen, die nur ein Server durchläuft, deshalb als überlebend. Abgesichert sind diese Zweige durch die Tests in den beiden Jobs.
- Migrationen und rohe Abfragen müssen auf allen drei Datenbanken laufen. Wo sich die Datenbanken unterscheiden, unterscheidet der Code nach Treiber. So sichert die Eindeutigkeit der E-Mail-Adresse `User::normalizeEmail()` und nicht die Collation, und MariaDB vergleicht E-Mail- und Base64URL-Spalten binär, weil `utf8mb4_unicode_ci` `müller@` und `muller@` gleichsetzt.
- Passkeys haben einen numerischen Schlüssel, weil das Aktivitätslog seine Subjekte numerisch speichert. Die UUID bleibt die öffentliche Kennung.
- Die bestehenden Migrationen wurden direkt geändert, weil das Projekt noch im Pre-Alpha-Stadium ist und es keine produktive Instanz gibt. Eine Datenbank, die vorher migriert wurde, muss neu aufgesetzt werden.
- Auf PostgreSQL ignoriert die Namenssuche im Aktivitätslog die Schreibweise von Umlauten nur unter einer UTF-8-Locale, siehe [docs/deployment.md](../deployment.md).
- Auf den Servern ungeprüft bleiben der `database`-Treiber für Cache, Session und Queue, weil die Suite `array` und `sync` nutzt, und ob `lockForUpdate()` Race Conditions verhindert: SQLite ignoriert die Sperre, auf den Servern läuft sie ohne Nebenläufigkeit.
