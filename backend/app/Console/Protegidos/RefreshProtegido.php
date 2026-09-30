<?php

namespace App\Console\Protegidos;

use Illuminate\Database\Console\Migrations\RefreshCommand;

/** migrate:refresh so contra banco descartavel comprovado (ver ExigeAlvoDescartavel). */
final class RefreshProtegido extends RefreshCommand
{
    use ExigeAlvoDescartavel;
}
