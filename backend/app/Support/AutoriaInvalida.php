<?php

namespace App\Support;

use LogicException;

/** Ator e usuario incoerentes para uma TransacaoAuditada. Nada foi gravado. */
class AutoriaInvalida extends LogicException {}
