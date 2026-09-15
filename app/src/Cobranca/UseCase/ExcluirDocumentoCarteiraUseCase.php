<?php

declare(strict_types=1);

namespace App\Cobranca\UseCase;

use App\Cobranca\Armazenamento\ChavesDeCobranca;
use App\Cobranca\Entity\CarteiraDocumento;
use App\Cobranca\Repository\CarteiraDocumentoRepository;
use App\Entity\Tenant\Tenant;
use App\Shared\Armazenamento\ArmazenamentoDeArquivos;
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
 *
 * Estado misto da E2.2 (D2): a PRESENÇA é perguntada ao armazenamento novo, por chave montada a
 * partir da entidade (`ChavesDeCobranca`); a REMOÇÃO ainda passa pela interface antiga, por
 * caminho, até a E2.5 migrar `excluir()`.
 */
final class ExcluirDocumentoCarteiraUseCase
{
    public function __construct(
        private readonly CarteiraDocumentoRepository $documentoRepository,
        private readonly ArquivoStorageInterface $storage,
        private readonly ArmazenamentoDeArquivos $armazenamento,
        private readonly string $cobrancasUploadsDir,
    ) {
    }

    public function executar(CarteiraDocumento $documento, Tenant $tenant): void
    {
        // Guarda multi-tenant: só se exclui documento do próprio escritório.
        if ($documento->getTenant() !== $tenant) {
            throw new AccessDeniedException('Documento não pertence ao tenant do usuário.');
        }

        if ($this->armazenamento->existe(ChavesDeCobranca::documentoDeCarteira($documento))) {
            // MESMO diretório flat dos documentos de caso (padrão M5, decisão deliberada).
            $diretorio = $this->cobrancasUploadsDir . '/' . $tenant->getId();
            $this->storage->excluir($this->storage->caminho($diretorio, $documento->getCaminhoArquivo()));
        }

        $this->documentoRepository->remover($documento, flush: true);
    }
}
