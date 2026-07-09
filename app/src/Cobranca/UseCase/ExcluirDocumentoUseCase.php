<?php

declare(strict_types=1);

namespace App\Cobranca\UseCase;

use App\Cobranca\Entity\CobrancaDocumento;
use App\Cobranca\Repository\CobrancaDocumentoRepository;
use App\Entity\Tenant\Tenant;
use App\Shared\Service\ArquivoStorageInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Exclui um documento de um Caso de Cobrança (Etapa 6, SPEC §15): remove o arquivo físico do disco e a
 * linha do banco. Guarda multi-tenant: só se exclui documento do próprio escritório.
 *
 * O caminho físico é reconstruído a partir do isolamento por tenant (contrato congelado, padrão M5):
 * `cobrancas/<tenantId>/<hash>`. A exclusão do arquivo é best-effort — se o arquivo já não existir no
 * disco, apenas a linha é removida (não impede a exclusão do registro).
 */
final class ExcluirDocumentoUseCase
{
    public function __construct(
        private readonly CobrancaDocumentoRepository $documentoRepository,
        private readonly ArquivoStorageInterface $storage,
        private readonly string $cobrancasUploadsDir,
    ) {
    }

    public function executar(CobrancaDocumento $documento, Tenant $tenant): void
    {
        // Guarda multi-tenant: só se exclui documento do próprio escritório.
        if ($documento->getTenant() !== $tenant) {
            throw new AccessDeniedException('Documento não pertence ao tenant do usuário.');
        }

        // Isolamento físico por tenant no disco (contrato congelado, padrão M5).
        $diretorio = $this->cobrancasUploadsDir . '/' . $tenant->getId();
        $caminho   = $this->storage->caminho($diretorio, $documento->getCaminhoArquivo());

        if ($this->storage->existe($caminho)) {
            $this->storage->excluir($caminho);
        }

        $this->documentoRepository->remover($documento, flush: true);
    }
}
