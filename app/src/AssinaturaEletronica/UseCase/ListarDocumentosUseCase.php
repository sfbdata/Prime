<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\UseCase;

use App\AssinaturaEletronica\Repository\DocumentoParaAssinarRepository;
use App\AssinaturaEletronica\Service\ApresentacaoDoDocumento;
use App\Entity\Tenant\Tenant;

/**
 * A lista da tela: todos os documentos do escritório, Lixeira incluída (`naLixeira`), e as pastas que aparecem neles
 * (sem repetição, na ordem da lista, como o desenho).
 */
final readonly class ListarDocumentosUseCase
{
    public function __construct(
        private DocumentoParaAssinarRepository $documentos,
        private ApresentacaoDoDocumento $apresentacao,
    ) {
    }

    /** @return array{documentos: list<array<string, mixed>>, pastas: list<string>} */
    public function executar(Tenant $tenant): array
    {
        $documentos = $this->documentos->listarDoEscritorio($tenant);

        $pastas = [];
        foreach ($documentos as $documento) {
            $pasta = $documento->getPastaRotulo();
            if ($pasta !== null && !\in_array($pasta, $pastas, true)) {
                $pastas[] = $pasta;
            }
        }

        return [
            'documentos' => array_map($this->apresentacao->resumo(...), $documentos),
            'pastas' => $pastas,
        ];
    }
}
