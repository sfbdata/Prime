<?php

declare(strict_types=1);

namespace App\Cobranca\UseCase;

use App\Cobranca\Entity\CasoCobranca;
use App\Cobranca\Repository\CobrancaSecaoRepository;
use App\Entity\Tenant\Tenant;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Reordena as seções de um Caso de Cobrança conforme a lista de ids arrastada no file-manager
 * (Etapa 8C). Espelha `App\Pasta\UseCase\ReordenarSecoesUseCase`: parte SEMPRE das seções do próprio
 * caso+tenant (`secoesDoCaso`, já escopado), então ids estranhos são ignorados (no-op), nunca tocando
 * dado alheio. O flush único persiste toda a nova ordem.
 */
final class ReordenarSecoesCasoUseCase
{
    public function __construct(
        private readonly CobrancaSecaoRepository $secaoRepository,
    ) {
    }

    /**
     * @param list<int> $idsOrdenados IDs das seções na nova ordem desejada
     */
    public function executar(CasoCobranca $caso, Tenant $tenant, array $idsOrdenados): void
    {
        if ($caso->getTenant() !== $tenant) {
            throw new AccessDeniedException('Caso não pertence ao tenant do usuário.');
        }
        if ($idsOrdenados === []) {
            return;
        }

        $mapa = [];
        foreach ($this->secaoRepository->secoesDoCaso($caso) as $secao) {
            $mapa[(int) $secao->getId()] = $secao;
        }

        $ordem = 1;
        $ultima = null;
        foreach ($idsOrdenados as $id) {
            $id = (int) $id;
            if (!isset($mapa[$id])) {
                continue;
            }
            $mapa[$id]->setOrdem($ordem);
            ++$ordem;
            $ultima = $mapa[$id];
        }

        // Flush único da unidade de trabalho: persiste a nova ordem de TODAS as seções alteradas.
        if ($ultima !== null) {
            $this->secaoRepository->salvar($ultima, true);
        }
    }
}
