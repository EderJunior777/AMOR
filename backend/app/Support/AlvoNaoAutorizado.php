<?php

namespace App\Support;

use RuntimeException;

/**
 * Alvo de operacao destrutiva (migrate:fresh, reset, rollback, db:wipe,
 * TRUNCATE de teste...) nao comprovado como o banco descartavel certo.
 * A mensagem nunca contem URL, usuario ou senha.
 */
final class AlvoNaoAutorizado extends RuntimeException {}
