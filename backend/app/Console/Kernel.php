<?php

namespace App\Console;

use App\Support\ErroDeBanco;
use Illuminate\Foundation\Console\Kernel as KernelDoFramework;
use PDOException;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Throwable;

/**
 * So muda a saida de console: um comando (ou tarefa do scheduler, cuja saida
 * pode ir para arquivo) que falha com erro de banco imprime a mensagem
 * traduzida e o id de correlacao, nunca o texto do trigger, o SQL nem o
 * host/porta/usuario da conexao. Fica no kernel, e nao no handler de
 * excecoes, porque em desenvolvimento o Collision troca o handler e
 * renderiza tudo por conta propria.
 */
class Kernel extends KernelDoFramework
{
    /** O kernel do framework so descobre app/Console/Commands se for a propria classe. */
    protected function shouldDiscoverCommands()
    {
        return true;
    }

    protected function renderException($output, Throwable $e)
    {
        if (! $e instanceof PDOException) {
            parent::renderException($output, $e);

            return;
        }

        [, , $mensagem] = ErroDeBanco::classificar($e);
        $saida = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;

        $saida->writeln(sprintf('<error>Erro de banco: %s (correlacao: %s)</error>', $mensagem, ErroDeBanco::correlacao($e)));
    }
}
