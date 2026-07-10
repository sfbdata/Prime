<?php

declare(strict_types=1);

namespace App\Cobranca\UseCase;

use App\Cobranca\Entity\CasoCobranca;
use App\Cobranca\Repository\CobrancaDocumentoRepository;
use App\Entity\Tenant\Tenant;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Reordena os documentos de um Caso de Cobrança conforme a lista de ids arrastada no file-manager
 * (Etapa 8C). Espelha `App\Pasta\UseCase\ReordenarDocumentosUseCase`: parte SEMPRE dos documentos do
 * próprio caso+tenant (`documentosDoCaso`, já escopado), então ids estranhos (outro caso/tenant) são
 * simplesmente ignorados (no-op), nunca tocando dado alheio. O flush único persiste toda a nova ordem.
 */
final class ReordenarDocumentosCasoUseCase
{
    public function __construct(
        private readonly CobrancaDocumentoRepository $documentoRepository,
    ) {
    }

    /**
     * @param list<int> $idsOrdenados IDs dos documentos na nova ordem desejada
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
        foreach ($this->documentoRepository->documentosDoCaso($caso) as $documento) {
            $mapa[(int) $documento->getId()] = $documento;
        }

        $ordem = 1;
        $ultimo = null;
        foreach ($idsOrdenados as $id) {
            $id = (int) $id;
            if (!isset($mapa[$id])) {
                continue;
            }
            $mapa[$id]->setOrdem($ordem);
            ++$ordem;
            $ultimo = $mapa[$id];
        }

        // Flush único da unidade de trabalho: persiste a nova ordem de TODOS os documentos alterados.
        if ($ultimo !== null) {
            $this->documentoRepository->salvar($ultimo, true);
        }
    }
}
