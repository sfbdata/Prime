<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\UseCase;

use App\AssinaturaEletronica\Enum\CorDaLinha;
use App\AssinaturaEletronica\Exception\DocumentoNaoEncontrado;
use App\AssinaturaEletronica\Repository\DocumentoParaAssinarRepository;
use App\AssinaturaEletronica\Service\DocumentosDaSelecao;
use App\Entity\Tenant\Tenant;

/** A cor da linha dos documentos marcados (por documento, como o protótipo). Não vai para a trilha. */
final readonly class PintarDocumentosUseCase
{
    public function __construct(
        private DocumentosDaSelecao $selecao,
        private DocumentoParaAssinarRepository $documentos,
    ) {
    }

    /**
     * @param list<int> $ids
     *
     * @throws DocumentoNaoEncontrado
     */
    public function executar(Tenant $tenant, array $ids, CorDaLinha $cor): void
    {
        $documentos = $this->selecao->carregar($ids, $tenant);

        $this->documentos->emTransacao(static function () use ($documentos, $cor): void {
            foreach ($documentos as $documento) {
                $documento->pintar($cor);
            }
        });
    }
}
