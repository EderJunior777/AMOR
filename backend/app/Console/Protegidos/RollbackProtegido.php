<?php

namespace App\Console\Protegidos;

use Illuminate\Database\Console\Migrations\RollbackCommand;

/** migrate:rollback so contra banco descartavel comprovado (ver ExigeAlvoDescartavel). */
final class RollbackProtegido extends RollbackCommand
{
    use ExigeAlvoDescartavel;
}
