<?php

return [

    /*
     * Retencao de cliente inativo (LGPD, docs/LGPD-ANONIMIZACAO.md, D5).
     *
     * SEM VALOR PADRAO de proposito: o prazo e decisao do responsavel +
     * juridico. Sem os dois valores, cleison:anonimizar-inativos nao
     * anonimiza ninguem (e nem e agendado).
     *
     *   meses:          inteiro > 0; cliente sem agendamento ha mais que
     *                   isso (e sem agendamento em aberto) e anonimizado.
     *   responsavel_id: id do usuario PROPRIETARIO ativo em nome de quem a
     *                   rotina roda (a funcao do banco exige proprietario).
     */
    'retencao' => [
        'meses' => env('CLEISON_RETENCAO_CLIENTE_INATIVO_MESES'),
        'responsavel_id' => env('CLEISON_RETENCAO_RESPONSAVEL_ID'),
    ],

    /*
     * API publica v1 (routes/api.php). Limites de requisicao, em tentativas
     * por janela, contados por IP e (na criacao) por telefone normalizado ou
     * (na reserva existente) por IP + codigo. Padroes conservadores; cada
     * valor pode ser trocado por variavel de ambiente (.env.example).
     * Chaves com telefone ou codigo sao HMAC (AppServiceProvider), nunca o
     * valor cru: o cache padrao e uma tabela do banco.
     */
    'api' => [
        'limites' => [
            // Toda rota, por IP, por minuto.
            'geral_por_minuto' => (int) env('API_LIMITE_GERAL_POR_MINUTO', 60),
            // POST /reservas: por IP por minuto e por telefone por hora.
            'criar_por_minuto_ip' => (int) env('API_LIMITE_CRIAR_POR_MINUTO_IP', 10),
            'criar_por_hora_telefone' => (int) env('API_LIMITE_CRIAR_POR_HORA_TELEFONE', 5),
            // consultar/cancelar/remarcar: por IP + codigo por minuto e por IP por hora.
            'reserva_por_minuto_ip_codigo' => (int) env('API_LIMITE_RESERVA_POR_MINUTO_IP_CODIGO', 5),
            'reserva_por_hora_ip' => (int) env('API_LIMITE_RESERVA_POR_HORA_IP', 30),
        ],
    ],

];
