<?php

declare(strict_types=1);

namespace App\Comercial\UseCase;

use App\Comercial\Repository\ConfirmacaoDeEnderecoRepository;

/**
 * Purga das pendências de confirmação de endereço NUNCA confirmadas, 30 dias depois de vencer: o estado e os fatos
 * `pedido` delas (spec `comercial-e-emails.md` §3.3 Retenção). Estado com `confirmado_em` e fato `confirmado` nunca
 * saem por aqui (a purga desses fatos fica para a fatia 3).
 *
 * Espelha o PurgarRedefinicoesSenhaUseCase, para o `app:purgar-dados-expirados` chamar como mais uma faxina.
 */
final class PurgarConfirmacoesVencidasUseCase
{
    public const CARENCIA = '-30 days';

    public function __construct(
        private readonly ConfirmacaoDeEnderecoRepository $repository,
    ) {
    }

    /** Em dry-run só conta. Devolve quantas pendências foram (ou seriam) purgadas. */
    public function executar(\DateTimeImmutable $agora, bool $dryRun): int
    {
        $limite = $agora->modify(self::CARENCIA);
        if ($dryRun === true) {
            return $this->repository->contarPendentesVencidas($limite);
        }

        return $this->repository->purgarPendentesVencidas($limite);
    }
}
