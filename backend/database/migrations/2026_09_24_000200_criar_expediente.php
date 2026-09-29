<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Expediente de cada profissional.
 *
 * Horarios aqui sao "de parede" no fuso do estabelecimento
 * (estabelecimento.fuso_horario), porque "abre as 08:00" continua sendo
 * 08:00 mesmo se o offset mudar. A conversao para timestamptz acontece
 * ao calcular disponibilidade (etapa 2).
 *
 *   - expedientes_semanais: janelas de trabalho por dia da semana. O
 *     intervalo de almoco e o BURACO entre duas janelas do mesmo dia
 *     (ex.: 08:00-12:00 e 13:00-20:00).
 *   - excecoes_expediente: numa data especifica, as janelas desta tabela
 *     SUBSTITUEM as semanais (abrir num domingo, fechar mais cedo...).
 *   - folga, ferias, feriado e compromisso NAO ficam aqui: sao
 *     bloqueios_agenda, que ocupam a agenda com garantia do banco contra
 *     conflito com agendamentos (ver migration de agendamentos).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE expedientes_semanais (
              id              bigserial PRIMARY KEY,
              profissional_id bigint   NOT NULL REFERENCES profissionais (id) ON DELETE CASCADE,
              -- 0 = domingo ... 6 = sabado (mesma convencao do site original)
              dia_semana      smallint NOT NULL,
              hora_inicio     time     NOT NULL,
              hora_fim        time     NOT NULL,
              created_at      timestamptz NOT NULL DEFAULT now(),
              updated_at      timestamptz NOT NULL DEFAULT now(),
              CONSTRAINT expedientes_dia_semana CHECK (dia_semana BETWEEN 0 AND 6),
              CONSTRAINT expedientes_intervalo  CHECK (hora_fim > hora_inicio),
              -- Janelas do mesmo dia nao se sobrepoem; encostar pode
              -- ([08:00,12:00) e [12:00,13:00)). Data fixa so para montar o range.
              CONSTRAINT expedientes_sem_sobreposicao EXCLUDE USING gist (
                profissional_id WITH =,
                dia_semana WITH =,
                tsrange(DATE '2000-01-02' + hora_inicio, DATE '2000-01-02' + hora_fim, '[)') WITH &&
              )
            );

            CREATE TABLE excecoes_expediente (
              id              bigserial PRIMARY KEY,
              profissional_id bigint NOT NULL REFERENCES profissionais (id) ON DELETE CASCADE,
              data            date   NOT NULL,
              hora_inicio     time   NOT NULL,
              hora_fim        time   NOT NULL,
              motivo          varchar(200),
              created_at      timestamptz NOT NULL DEFAULT now(),
              updated_at      timestamptz NOT NULL DEFAULT now(),
              CONSTRAINT excecoes_intervalo CHECK (hora_fim > hora_inicio),
              CONSTRAINT excecoes_sem_sobreposicao EXCLUDE USING gist (
                profissional_id WITH =,
                tsrange(data + hora_inicio, data + hora_fim, '[)') WITH &&
              )
            );
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TABLE IF EXISTS excecoes_expediente;
            DROP TABLE IF EXISTS expedientes_semanais;
        SQL);
    }
};
