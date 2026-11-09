<?php

declare(strict_types=1);

namespace App\Ponto\Service;

use App\Entity\Tenant\Tenant;

/**
 * Os valores padrão do desenho (L774 e L817): 120 horas de limite e 6 meses de prazo, iguais para todo escritório,
 * enquanto a configuração de cada escritório (E7) não existe.
 */
final class LimitesPadraoDoBancoDeHoras implements LimitesDoBancoDeHoras
{
    public const LIMITE_EM_HORAS = 120;
    public const PRAZO_EM_MESES = 6;

    public function limiteEmMinutos(Tenant $tenant): int
    {
        return self::LIMITE_EM_HORAS * 60;
    }

    public function prazoEmMeses(Tenant $tenant): int
    {
        return self::PRAZO_EM_MESES;
    }
}
