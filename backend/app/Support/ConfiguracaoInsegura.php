<?php

namespace App\Support;

use RuntimeException;

/**
 * Producao configurada de forma insegura: a aplicacao se recusa a subir.
 * A mensagem cita so os NOMES das variaveis, nunca valores.
 */
class ConfiguracaoInsegura extends RuntimeException {}
