<?php

declare(strict_types=1);

namespace App\Inteligencia\DTO;

/**
 * O rodapé "Registro de auditoria (N)" do drawer: `total` é o N (todas as linhas da pasta) e
 * `registros`, as mais recentes até o limite do UseCase.
 */
final readonly class AuditoriaDaPastaOutput
{
    /** @param list<RegistroDeAuditoriaOutput> $registros */
    public function __construct(
        public int $total,
        public array $registros,
    ) {
    }

    public function temMaisQueOsListados(): bool
    {
        return $this->total > count($this->registros);
    }
}
