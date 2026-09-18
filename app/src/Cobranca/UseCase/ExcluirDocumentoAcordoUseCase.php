<?php

declare(strict_types=1);

namespace App\Cobranca\UseCase;

use App\Cobranca\Armazenamento\ChavesDeCobranca;
use App\Cobranca\Entity\AcordoDocumento;
use App\Cobranca\Repository\AcordoDocumentoRepository;
use App\Entity\Tenant\Tenant;
use App\Shared\Armazenamento\RemocaoAposTransacao;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Exclui um documento de um Acordo (Ajuste #4): remove a linha do banco e o arquivo
 * físico. Guarda multi-tenant: só se exclui documento do próprio escritório.
 *
 * O arquivo mora no MESMO diretório por escritório dos documentos de caso (decisão deliberada, ver
 * `EnviarDocumentoAcordoUseCase`); a chave sai da entidade (`ChavesDeCobranca`).
 *
 * **Ordem (E2.5, INV-6):** a chave é montada antes, a linha sai e é confirmada, e só então o
 * arquivo é removido. Falha física depois do COMMIT não desfaz a exclusão: vira registro no log e
 * órfão recuperável. Antes da E2.5 o arquivo saía primeiro, e um `flush` recusado deixava a linha
 * apontando para o vazio.
 */
final class ExcluirDocumentoAcordoUseCase
{
    public function __construct(
        private readonly AcordoDocumentoRepository $documentoRepository,
        private readonly RemocaoAposTransacao $remocao,
    ) {
    }

    public function executar(AcordoDocumento $documento, Tenant $tenant): void
    {
        // Guarda multi-tenant: só se exclui documento do próprio escritório.
        if ($documento->getTenant() !== $tenant) {
            throw new AccessDeniedException('Documento não pertence ao tenant do usuário.');
        }

        $chave = ChavesDeCobranca::documentoDeAcordo($documento);

        $this->documentoRepository->remover($documento, flush: true);

        $this->remocao->remover([$chave], 'ExcluirDocumentoAcordoUseCase');
    }
}
