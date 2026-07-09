<?php

declare(strict_types=1);

namespace App\Cobranca\UseCase;

use App\Cobranca\Entity\CobrancaDocumento;
use App\Cobranca\Entity\CobrancaSecao;
use App\Cobranca\Exception\SecaoNaoEncontradaException;
use App\Cobranca\Repository\CobrancaDocumentoRepository;
use App\Entity\Tenant\Tenant;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Move um documento de um Caso de Cobrança para outra seção — ou para "sem seção" (destino null), o
 * agrupamento geral do caso (Etapa 6, SPEC §15). Não move o arquivo físico: apenas reassocia o
 * documento à seção de destino; o hash/caminho no disco permanece inalterado.
 *
 * Guardas multi-tenant: o documento tem de ser do próprio escritório; quando há seção de destino, ela
 * também tem de ser do mesmo escritório E do mesmo caso do documento — do contrário é uma seção
 * "estranha" ao caso (SecaoNaoEncontradaException).
 */
final class MoverDocumentoUseCase
{
    public function __construct(
        private readonly CobrancaDocumentoRepository $documentoRepository,
    ) {
    }

    public function executar(
        CobrancaDocumento $documento,
        ?CobrancaSecao $secaoDestino,
        Tenant $tenant,
    ): void {
        // Guarda multi-tenant do documento: só se move documento do próprio escritório.
        if ($documento->getTenant() !== $tenant) {
            throw new AccessDeniedException('Documento não pertence ao tenant do usuário.');
        }

        if ($secaoDestino !== null) {
            // A seção de destino tem de ser do próprio escritório.
            if ($secaoDestino->getTenant() !== $tenant) {
                throw new AccessDeniedException('Seção de destino não pertence ao tenant do usuário.');
            }

            // E do MESMO caso do documento — não se move para uma seção de outro caso.
            if ($secaoDestino->getCaso() !== $documento->getCaso()) {
                throw new SecaoNaoEncontradaException((int) $secaoDestino->getId());
            }
        }

        $documento->setSecao($secaoDestino);

        $this->documentoRepository->salvar($documento, flush: true);
    }
}
