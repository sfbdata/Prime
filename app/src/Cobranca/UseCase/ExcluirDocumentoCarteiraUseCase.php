<?php

declare(strict_types=1);

namespace App\Cobranca\UseCase;

use App\Cobranca\Entity\CarteiraDocumento;
use App\Cobranca\Repository\CarteiraDocumentoRepository;
use App\Entity\Tenant\Tenant;
use App\Shared\Service\ArquivoStorageInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Exclui um documento da Carteira de Cobrança (Ajuste #5): remove o arquivo físico do disco e a
 * linha do banco. Guarda multi-tenant: só se exclui documento do próprio escritório.
 *
 * O caminho físico é reconstruído a partir do MESMO diretório flat dos documentos de caso
 * (decisão deliberada, ver `EnviarDocumentoCarteiraUseCase`): `<cobrancasUploadsDir>/<tenantId>/
 * <hash>`. A exclusão do arquivo é best-effort — se o arquivo já não existir no disco, apenas a
 * linha é removida (não impede a exclusão do registro).
 */
final class ExcluirDocumentoCarteiraUseCase
{
    public function __construct(
        private readonly CarteiraDocumentoRepository $documentoRepository,
        private readonly ArquivoStorageInterface $storage,
        private readonly string $cobrancasUploadsDir,
    ) {
    }

    public function executar(CarteiraDocumento $documento, Tenant $tenant): void
    {
        // Guarda multi-tenant: só se exclui documento do próprio escritório.
        if ($documento->getTenant() !== $tenant) {
            throw new AccessDeniedException('Documento não pertence ao tenant do usuário.');
        }

        // MESMO diretório flat dos documentos de caso (padrão M5, decisão deliberada).
        $diretorio = $this->cobrancasUploadsDir . '/' . $tenant->getId();
        $caminho   = $this->storage->caminho($diretorio, $documento->getCaminhoArquivo());

        if ($this->storage->existe($caminho)) {
            $this->storage->excluir($caminho);
        }

        $this->documentoRepository->remover($documento, flush: true);
    }
}
