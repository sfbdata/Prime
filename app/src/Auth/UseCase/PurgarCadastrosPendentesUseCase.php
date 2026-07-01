<?php

declare(strict_types=1);

namespace App\Auth\UseCase;

use App\Auth\Repository\CadastroPendenteRepository;

/**
 * Purga registros de `cadastro_pendente` que guardam senha_hash + PII e não têm mais
 * uso: os confirmados (o User já foi criado; o hash aqui virou cópia morta) e os
 * pendentes com token expirado (cadastro abandonado). Pendentes ainda no prazo são
 * cadastros vivos e são preservados.
 *
 * Disparado pelo job de manutenção (sem usuário/tenant no contexto). A tabela é global
 * (não-TenantAware, não-auditável), então a purga é uma única operação, sem loop por
 * tenant. Apagar é seguro: não há FK apontando para a tabela.
 */
final class PurgarCadastrosPendentesUseCase
{
    public function __construct(
        private readonly CadastroPendenteRepository $repository,
    ) {
    }

    /**
     * Em dry-run apenas conta o que seria apagado. Retorna o nº de registros
     * purgados (ou que seriam purgados).
     */
    public function executar(\DateTimeImmutable $agora, bool $dryRun): int
    {
        if ($dryRun === true) {
            return $this->repository->contarPurgaveis($agora);
        }

        return $this->repository->purgar($agora);
    }
}
