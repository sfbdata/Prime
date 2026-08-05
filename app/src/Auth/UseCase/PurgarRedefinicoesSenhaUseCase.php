<?php

declare(strict_types=1);

namespace App\Auth\UseCase;

use App\Auth\Repository\RedefinicaoSenhaRepository;

/**
 * Purga registros de `redefinicao_senha` sem uso futuro: os já consumidos e os que
 * expiraram sem uso. Pedidos ainda no prazo são links vivos e são preservados.
 *
 * Existe porque cada linha guarda IP + user agent — PII que não tem por que sobreviver
 * ao pedido. É também o que sustenta a decisão de deixar a tabela fora da auditoria:
 * sem esta purga, "efêmero" seria só uma afirmação no comentário.
 *
 * Espelha o PurgarCadastrosPendentesUseCase: tabela global, sem tenant, sem loop.
 */
final class PurgarRedefinicoesSenhaUseCase
{
    public function __construct(
        private readonly RedefinicaoSenhaRepository $repository,
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
