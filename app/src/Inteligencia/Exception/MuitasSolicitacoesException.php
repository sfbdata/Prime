<?php

declare(strict_types=1);

namespace App\Inteligencia\Exception;

/** O limitador de ritmo por pessoa recusou criar mais uma análise agora (429). */
final class MuitasSolicitacoesException extends \RuntimeException
{
}
