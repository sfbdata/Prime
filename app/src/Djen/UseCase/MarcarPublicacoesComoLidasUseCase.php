<?php

declare(strict_types=1);

namespace App\Djen\UseCase;

use App\Djen\Repository\PublicacaoDjenRepository;
use App\Entity\Tenant\Tenant;

/**
 * "Marcar como lidas" (em lote) e "Marcar como lida/não lida" (menu da linha) da caixa do Push.
 * Só mexe em publicação do escritório e fora das pastas que o usuário não enxerga.
 */
final class MarcarPublicacoesComoLidasUseCase
{
    public function __construct(private readonly PublicacaoDjenRepository $publicacoes)
    {
    }

    /**
     * @param list<int> $ids
     * @param list<string> $numerosOcultos
     *
     * @return int quantas publicações mudaram de estado
     */
    public function executar(Tenant $tenant, array $ids, bool $lida, array $numerosOcultos = []): int
    {
        return $this->publicacoes->atualizarLeituraDoTenant($tenant, $ids, $lida, $numerosOcultos);
    }
}
