<?php

declare(strict_types=1);

namespace App\Djen\DTO;

/**
 * Intervalo de datas de disponibilização usado na sincronização com o DJEN (inclusivo nas duas pontas).
 */
final class PeriodoConsulta
{
    public function __construct(
        public readonly \DateTimeImmutable $inicio,
        public readonly \DateTimeImmutable $fim,
    ) {
    }

    public static function dia(\DateTimeImmutable $data): self
    {
        return new self($data, $data);
    }

    /**
     * Janela terminando em $referencia com $dias de amplitude (dias=1 => só a referência).
     */
    public static function ultimosDias(int $dias, \DateTimeImmutable $referencia): self
    {
        $dias = max(1, $dias);
        $inicio = $referencia->modify(sprintf('-%d days', $dias - 1));

        return new self($inicio, $referencia);
    }
}
