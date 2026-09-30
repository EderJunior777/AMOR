<?php

namespace App\Console\Protegidos;

use Illuminate\Database\Console\Migrations\FreshCommand;

/** migrate:fresh so contra banco descartavel comprovado (ver ExigeAlvoDescartavel). */
final class FreshProtegido extends FreshCommand
{
    use ExigeAlvoDescartavel;
}
