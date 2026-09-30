<?php

namespace App\Console\Protegidos;

use Illuminate\Database\Console\WipeCommand;

/** db:wipe so contra banco descartavel comprovado (ver ExigeAlvoDescartavel). */
final class WipeProtegido extends WipeCommand
{
    use ExigeAlvoDescartavel;
}
