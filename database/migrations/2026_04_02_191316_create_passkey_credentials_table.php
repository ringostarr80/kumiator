<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('passkey_credentials', static function (Blueprint $table): void {
            // Numeric, because `activity_log.subject_id` holds the polymorphic references of all models as numbers.
            $table->id();

            // The identifier clients address a passkey with. A plain char column instead of a native uuid: The value
            // arrives as a Livewire parameter, and PostgreSQL would raise 22P02 on an invalid one instead of finding
            // nothing.
            $table->char('uuid', 36)->unique();

            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // The credential ID issued by the authenticator (Base64URL-encoded).
            // Must be unique because one credential ID can only belong to one user.
            // 1364 characters are the 1023 bytes webauthn-lib accepts, encoded as Base64URL without padding.
            $credentialId = $table->string('credential_id', 1_364)->unique();

            // Base64URL is case-sensitive, MariaDB's default collation is not. ASCII keeps the column below InnoDB's
            // index limit of 3072 bytes: With utf8mb4 MariaDB falls back to a hash index that lookups do not use.
            if (DB::getDriverName() === 'mariadb') {
                $credentialId->charset('ascii')->collation('ascii_bin');
            }

            // The full PublicKeyCredentialSource serialised as JSON.
            // Storing the whole object avoids the need to re-map individual columns
            // when the library's internal representation changes.
            $table->text('credential_public_key');

            // Signature counter – used to detect cloned authenticators.
            $table->unsignedBigInteger('counter')->default(0);

            // Transport hints (e.g. "internal", "usb", "ble", "nfc", "hybrid").
            // Stored as a JSON array; used by the browser to show the right UI.
            $table->json('transports')->nullable();

            // Backup flags as defined in the WebAuthn level 3 specification.
            $table->boolean('backup_eligible')->default(false);
            $table->boolean('backup_state')->default(false);

            // Authenticator Attestation GUID – identifies the authenticator model.
            $table->string('aaguid', 36)->default('00000000-0000-0000-0000-000000000000');

            // A user-facing name so that users can identify their devices.
            // Limited to 80 characters to match the UI input maxlength constraint.
            $table->string('name', 80)->default('');

            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('passkey_credentials');
    }
};
