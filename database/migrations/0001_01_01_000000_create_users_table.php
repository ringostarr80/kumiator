<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\ColumnDefinition;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class () extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // MariaDBs Standard-Collation setzt `müller@` und `muller@` gleich: Das zweite Konto scheiterte am
        // Unique-Index, ein Lookup träfe das falsche, und das Reset-Token des einen öffnete auch das andere.
        // Für die Schreibweise sorgt `User::normalizeEmail()`, die Spalten müssen nur exakt vergleichen.
        $email = static function (Blueprint $table): ColumnDefinition {
            $column = $table->string('email');

            return DB::getDriverName() === 'mariadb'
                ? $column->collation('utf8mb4_bin')
                : $column;
        };

        Schema::create('users', static function (Blueprint $table) use ($email): void {
            $table->id();
            $table->string('name');
            $email($table)->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->rememberToken();
            $table->foreignId('current_team_id')->nullable();
            $table->string('profile_photo_path', 2_048)->nullable();
            $table->timestamps();
        });

        Schema::create('password_reset_tokens', static function (Blueprint $table) use ($email): void {
            $email($table)->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', static function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('sessions');
    }
};
