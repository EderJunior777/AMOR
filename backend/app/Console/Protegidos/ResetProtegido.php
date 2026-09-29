<?php

namespace App\Console\Protegidos;

use Illuminate\Database\Console\Migrations\ResetCommand;

/** migrate:reset so contra banco descartavel comprovado (ver ExigeAlvoDescartavel). */
final class ResetProtegido extends ResetCommand
{
    use ExigeAlvoDescartavel;
}
