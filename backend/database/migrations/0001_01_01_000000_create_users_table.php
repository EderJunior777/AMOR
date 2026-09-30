<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Identidades ADMINISTRATIVAS (proprietario, barbeiro, recepcao).
 * Clientes da barbearia NAO ficam aqui: estao em `clientes`, sem senha.
 *
 * MFA, bloqueio por tentativas e trilha de auditoria entram na etapa 3,
 * junto com o login. Nenhum usuario e criado por migration ou seed: o
 * primeiro proprietario nasce pelo comando `cleison:criar-proprietario`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('email', 254);
            $table->timestampTz('email_verified_at')->nullable();
            $table->string('password');
            $table->string('papel', 20);
            $table->boolean('ativo')->default(true);
            $table->rememberToken();
            $table->timestampsTz();
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE users
              ADD CONSTRAINT users_papel_valido
                CHECK (papel IN ('proprietario', 'barbeiro', 'recepcao')),
              ADD CONSTRAINT users_nome_preenchido
                CHECK (length(btrim(name)) >= 2),
              ADD CONSTRAINT users_email_formato
                CHECK (email ~ '^[^@\s]+@[^@\s]+\.[^@\s]+$');

            -- E-mail unico sem diferenciar maiusculas.
            CREATE UNIQUE INDEX users_email_unico ON users (lower(email));
        SQL);

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestampTz('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
    }
};
