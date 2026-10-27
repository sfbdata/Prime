<?php

declare(strict_types=1);

namespace App\Master\UseCase;

use App\Master\DTO\RegistrarBackupInput;
use App\Master\Entity\RegistroDeBackup;
use App\Master\Repository\RegistroDeBackupRepository;
use App\Shared\Audit\EscopoDeAuditoriaDePlataforma;
use Psr\Clock\ClockInterface;

/**
 * Master registra um backup ou um teste de restauração (spec master-desempenho D3). Alimenta os indicadores
 * "Último backup" e "Teste de restauração". Auditável, na trilha da plataforma.
 */
final class RegistrarBackupUseCase
{
    public function __construct(
        private readonly RegistroDeBackupRepository $registros,
        private readonly ClockInterface $clock,
        private readonly EscopoDeAuditoriaDePlataforma $escopoDePlataforma,
    ) {
    }

    public function executar(RegistrarBackupInput $input): RegistroDeBackup
    {
        $registro = new RegistroDeBackup(
            $input->tipo,
            $input->ok,
            $input->observacao,
            \DateTimeImmutable::createFromInterface($this->clock->now()),
            $input->ator,
        );
        $this->escopoDePlataforma->executar(fn () => $this->registros->salvar($registro, flush: true));

        return $registro;
    }
}
