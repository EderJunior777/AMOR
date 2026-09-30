<?php

namespace App\Http\Controllers;

use App\Support\ErroDeBanco;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use PDOException;

/**
 * GET /up para o balanceador: confere o banco de verdade (o /up padrao do
 * Laravel diz "up" com o banco fora do ar e, com APP_DEBUG, relanca a
 * mensagem do PDO com host, porta e usuario). Fora do grupo "web": a
 * sessao usa o banco e nao pode rodar antes da conferencia.
 */
class SaudeController
{
    public function __invoke(): JsonResponse
    {
        try {
            DB::select('SELECT 1');
        } catch (PDOException $e) {
            ErroDeBanco::registrar($e);

            return response()->json(['status' => 'down', 'mensagem' => 'Banco de dados indisponível'], 503);
        }

        return response()->json(['status' => 'up']);
    }
}
