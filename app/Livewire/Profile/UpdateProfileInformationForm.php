<?php

declare(strict_types=1);

namespace App\Livewire\Profile;

use App\DataTransferObjects\UploadLimitData;
use App\Services\Upload\Contracts\UploadLimitResolverContract;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Number;
use Illuminate\Validation\ValidationException;
use Laravel\Jetstream\Http\Livewire\UpdateProfileInformationForm as JetstreamUpdateProfileInformationForm;
use Spatie\Activitylog\Facades\Activity;

/**
 * Erweiterung der Jetstream-Komponente um Activity-Log-Erfassung für
 * Profilfoto-Löschungen und die Anzeige des effektiven Upload-Limits.
 *
 * Jetstream feuert kein Framework-Event, wenn der Nutzer sein Profilfoto
 * entfernt — der Trait `HasProfilePhoto::deleteProfilePhoto()` ruft nur
 * `Storage::delete()` + `forceFill(['profile_photo_path' => null])->save()`
 * auf. Den Upload-Pfad deckt `App\Actions\Fortify\UpdateUserProfileInformation`
 * ab; hier hängt der Audit-Eintrag für den UI-Delete dran.
 *
 * Der Eintrag wird nur geschrieben, wenn ein Foto vorhanden war — sonst
 * ist der Parent-Aufruf ein No-Op und ein Removal-Audit ohne Vorgänger-
 * Pfad wäre irreführend.
 */
final class UpdateProfileInformationForm extends JetstreamUpdateProfileInformationForm
{
    private UploadLimitResolverContract $uploadLimitResolver;

    /**
     * Livewires `boot()` ist Container-aware und löst Type-Hints per DI auf —
     * der Resolver kann nicht über den Konstruktor injiziert werden, weil die
     * Parent-Komponente keinen passenden Konstruktor anbietet.
     */
    public function boot(UploadLimitResolverContract $uploadLimitResolver): void
    {
        $this->uploadLimitResolver = $uploadLimitResolver;
    }

    /**
     * Effektives Upload-Limit fürs Profilfoto — von der Blade-View für den
     * Größenhinweis unter dem Datei-Feld genutzt.
     */
    public function getPhotoUploadLimitProperty(): UploadLimitData
    {
        return $this->uploadLimitResolver->resolveProfilePhotoLimit();
    }

    /**
     * Wert fürs `accept`-Attribut des Datei-Felds — Komma-getrennte Liste
     * der erlaubten Endungen (z. B. `.jpg,.jpeg,.png,.webp,.avif`). Quelle
     * ist dieselbe Config wie für die server-seitige `mimes:`-Regel, damit
     * Client- und Server-Filter nicht auseinanderlaufen.
     */
    public function getPhotoAcceptAttributeProperty(): string
    {
        $extensions = $this->uploadLimitResolver->resolveProfilePhotoAcceptedExtensions();

        return implode(',', array_map(static fn (string $ext): string => '.' . $ext, $extensions));
    }

    // phpcs:disable PSR2.Methods.MethodDeclaration.Underscore -- Methodenname ist von Livewires WithFileUploads-Trait vorgegeben.
    /**
     * Schlägt ein Livewire-Temp-Upload fehl, ruft das JS diese Action auf.
     *
     * `$errorsInJson === null` bedeutet: der Upload ist auf Infrastruktur-
     * Ebene gescheitert (Nginx `client_max_body_size`, PHP `post_max_size`,
     * Timeout) — es gab keine Laravel-Validierung, deren Fehler durchgereicht
     * werden könnten. Livewires Parent wirft dann nur die nichtssagende
     * `validation.uploaded`-Meldung. Wir ersetzen sie durch einen Hinweis mit
     * dem konkreten effektiven Limit. Den JSON-Fall (Livewires eigene Temp-
     * Upload-Validierung ist angeschlagen) reichen wir unverändert weiter —
     * dort steckt bereits eine spezifische Meldung drin.
     *
     * Signatur bleibt untypisiert: der Vendor-Trait `WithFileUploads`
     * deklariert keine Parameter-Typen, und PHP verbietet es, in einer
     * überschreibenden Methode native Typen zu *ergänzen* (LSP-Bruch). Die
     * Typen stehen daher nur in `@param`; der PHPCS-Native-Type-Sniff wird
     * für genau diesen unvermeidbaren Fall punktuell unterdrückt.
     *
     * @phpcsSuppress SlevomatCodingStandard.TypeHints.ParameterTypeHint.MissingNativeTypeHint
     * @param string $name
     * @param string|null $errorsInJson
     * @param bool $isMultiple
     */
    public function _uploadErrored($name, $errorsInJson, $isMultiple): void
    {
        if ($errorsInJson === null) {
            $limit = $this->uploadLimitResolver->resolveProfilePhotoLimit();

            throw ValidationException::withMessages([
                $name => __('app.profile_photo_upload_failed', [
                    'size' => Number::fileSize($limit->bytes),
                ]),
            ]);
        }

        parent::_uploadErrored($name, $errorsInJson, $isMultiple);
    }
    // phpcs:enable PSR2.Methods.MethodDeclaration.Underscore

    public function deleteProfilePhoto(): void
    {
        $user = Auth::user();

        // Pfad VOR dem Parent-Aufruf snapshotten — danach hat der Trait das
        // Feld auf `null` gesetzt und wir hätten keinen Vorgänger mehr fürs
        // Audit-Property.
        $previousPath = $user instanceof Model
            ? $user->getAttribute('profile_photo_path')
            : null;

        parent::deleteProfilePhoto();

        // Post-Condition: nur loggen, wenn der Parent tatsächlich gelöscht hat.
        // `HasProfilePhoto::deleteProfilePhoto()` macht einen Early-Return,
        // sobald `Features::managesProfilePhotos()` false ist oder kein Foto
        // gesetzt war — in beiden Fällen würde ein Audit-Eintrag den Status
        // verfälschen.
        $currentPath = $user instanceof Model
            ? $user->getAttribute('profile_photo_path')
            : null;

        $hasPreviousPath = is_string($previousPath) && $previousPath !== '';

        if (!$user instanceof Model || !$hasPreviousPath || $currentPath !== null) {
            return;
        }

        Activity::useLog('user')
            ->event('profile_photo_removed')
            ->causedBy($user)
            ->performedOn($user)
            ->withProperties(['previous_profile_photo_path' => $previousPath])
            ->log(__('app.activity_profile_photo_removed'));
    }
}
