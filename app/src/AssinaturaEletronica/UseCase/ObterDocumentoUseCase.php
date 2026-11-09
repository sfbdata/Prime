<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\UseCase;

use App\AssinaturaEletronica\Exception\DocumentoNaoEncontrado;
use App\AssinaturaEletronica\Repository\DocumentoParaAssinarRepository;
use App\AssinaturaEletronica\Repository\EventoDaTrilhaRepository;
use App\AssinaturaEletronica\Service\ApresentacaoDoDocumento;
use App\Entity\Tenant\Tenant;

/** O `Detalhe` de um documento do escritório, mesmo na Lixeira, com a trilha do mais recente para o mais antigo. */
final readonly class ObterDocumentoUseCase
{
    public function __construct(
        private DocumentoParaAssinarRepository $documentos,
        private EventoDaTrilhaRepository $eventos,
        private ApresentacaoDoDocumento $apresentacao,
    ) {
    }

    /**
     * @return array<string, mixed>
     *
     * @throws DocumentoNaoEncontrado
     */
    public function executar(Tenant $tenant, int $id): array
    {
        $documento = $this->documentos->doEscritorioMesmoNaLixeira($id, $tenant) ?? throw new DocumentoNaoEncontrado();

        return $this->apresentacao->detalhe($documento, $this->eventos->doDocumento($documento));
    }
}
