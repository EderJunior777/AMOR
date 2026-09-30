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
     * Freios da reserva pelo site (achado #1 da Fase 5).
     *
     *   solicitado_expira_horas: "solicitado" nao confirmado vira "cancelado"
     *     (ator sistema, motivo "expirado") depois disso, contado da criacao,
     *     ou quando o inicio chega, o que vier primeiro
     *     (cleison:expirar-solicitados, a cada 5 minutos). Inteiro >= 1.
     *   maximo_em_aberto_por_telefone: reservas em aberto (solicitado ou
     *     confirmado, inicio no futuro, de qualquer canal) que um telefone
     *     pode ter para o SITE aceitar mais uma. O operador nao tem esse
     *     limite. Inteiro >= 1.
     *   teto_diario_do_site: freio de emergencia. Reservas criadas pelo site
     *     no dia (fuso do estabelecimento), de todos os telefones; acima
     *     disso a API responde 503 generico. Inteiro >= 1.
     * Valor invalido falha fechado (nada expira; o site nao reserva).
     */
    'reservas' => [
        'solicitado_expira_horas' => env('CLEISON_SOLICITADO_EXPIRA_HORAS', 12),
        'maximo_em_aberto_por_telefone' => env('CLEISON_MAXIMO_RESERVAS_EM_ABERTO_POR_TELEFONE', 2),
        'teto_diario_do_site' => env('CLEISON_TETO_DIARIO_RESERVAS_SITE', 500),
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
        // Limites por IP. So deixe ligado se o IP do cliente for confiavel:
        // atras de proxy (Netlify), exige TRUSTED_PROXIES certo; sem isso todo
        // cliente tem o IP do proxy e o limite vira global. Desligado (false):
        // sem limite por IP; ficam o por telefone e o por codigo.
        'limites_por_ip' => filter_var(env('API_LIMITE_POR_IP', true), FILTER_VALIDATE_BOOL),

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
