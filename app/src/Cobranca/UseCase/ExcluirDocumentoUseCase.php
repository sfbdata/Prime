<?php

declare(strict_types=1);

namespace App\Cobranca\UseCase;

use App\Cobranca\Armazenamento\ChavesDeCobranca;
use App\Cobranca\Entity\CobrancaDocumento;
use App\Cobranca\Repository\CobrancaDocumentoRepository;
use App\Entity\Tenant\Tenant;
use App\Shared\Armazenamento\RemocaoAposTransacao;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Exclui um documento de um Caso de Cobrança (Etapa 6, SPEC §15): remove a linha do banco e o
 * arquivo físico. Guarda multi-tenant: só se exclui documento do próprio escritório.
 *
 * **Ordem (E2.5, INV-6):** a chave é montada antes (`ChavesDeCobranca`, a partir da entidade), a
 * linha sai e é confirmada, e só então o arquivo é removido. Arquivo que já não existia não
 * impede a exclusão do registro; falha física depois do COMMIT vira registro no log e órfão
 * recuperável, sem desfazer nada.
 */
final class ExcluirDocumentoUseCase
{
    public function __construct(
        private readonly CobrancaDocumentoRepository $documentoRepository,
        private readonly RemocaoAposTransacao $remocao,
    ) {
    }

    public function executar(CobrancaDocumento $documento, Tenant $tenant): void
    {
        // Guarda multi-tenant: só se exclui documento do próprio escritório.
        if ($documento->getTenant() !== $tenant) {
            throw new AccessDeniedException('Documento não pertence ao tenant do usuário.');
        }

        $chave = ChavesDeCobranca::documentoDeCaso($documento);

        $this->documentoRepository->remover($documento, flush: true);

        $this->remocao->remover([$chave], 'ExcluirDocumentoUseCase');
    }
}
