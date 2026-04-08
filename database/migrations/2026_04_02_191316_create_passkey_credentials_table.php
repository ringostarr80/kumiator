<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    public function up(): void
    {
        Schema::create('passkey_credentials', static function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // The credential ID issued by the authenticator (Base64URL-encoded).
            // Must be unique because one credential ID can only belong to one user.
            $table->string('credential_id', 255)->unique();

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
