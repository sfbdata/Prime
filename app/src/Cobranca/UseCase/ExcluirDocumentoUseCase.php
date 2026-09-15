<?php

declare(strict_types=1);

namespace App\Cobranca\UseCase;

use App\Cobranca\Armazenamento\ChavesDeCobranca;
use App\Cobranca\Entity\CobrancaDocumento;
use App\Cobranca\Repository\CobrancaDocumentoRepository;
use App\Entity\Tenant\Tenant;
use App\Shared\Armazenamento\ArmazenamentoDeArquivos;
use App\Shared\Service\ArquivoStorageInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Exclui um documento de um Caso de Cobrança (Etapa 6, SPEC §15): remove o arquivo físico do disco e a
 * linha do banco. Guarda multi-tenant: só se exclui documento do próprio escritório.
 *
 * A exclusão do arquivo é best-effort — se o arquivo já não existir no disco, apenas a linha é
 * removida (não impede a exclusão do registro).
 *
 * Estado misto da E2.2 (D2): a PRESENÇA é perguntada ao armazenamento novo, por chave montada a
 * partir da entidade (`ChavesDeCobranca`); a REMOÇÃO ainda passa pela interface antiga, pelo
 * caminho `cobrancas/<tenantId>/<hash>` (padrão M5), até a E2.5 migrar `excluir()`.
 */
final class ExcluirDocumentoUseCase
{
    public function __construct(
        private readonly CobrancaDocumentoRepository $documentoRepository,
        private readonly ArquivoStorageInterface $storage,
        private readonly ArmazenamentoDeArquivos $armazenamento,
        private readonly string $cobrancasUploadsDir,
    ) {
    }

    public function executar(CobrancaDocumento $documento, Tenant $tenant): void
    {
        // Guarda multi-tenant: só se exclui documento do próprio escritório.
        if ($documento->getTenant() !== $tenant) {
            throw new AccessDeniedException('Documento não pertence ao tenant do usuário.');
        }

        if ($this->armazenamento->existe(ChavesDeCobranca::documentoDeCaso($documento))) {
            // Isolamento físico por tenant no disco (contrato congelado, padrão M5).
            $diretorio = $this->cobrancasUploadsDir . '/' . $tenant->getId();
            $this->storage->excluir($this->storage->caminho($diretorio, $documento->getCaminhoArquivo()));
        }

        $this->documentoRepository->remover($documento, flush: true);
    }
}
