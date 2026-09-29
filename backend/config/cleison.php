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

];
