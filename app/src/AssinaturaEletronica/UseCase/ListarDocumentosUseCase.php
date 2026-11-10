<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\UseCase;

use App\AssinaturaEletronica\DTO\ResumoDoDocumento;
use App\AssinaturaEletronica\Repository\DocumentoParaAssinarRepository;
use App\AssinaturaEletronica\Repository\EventoDaTrilhaRepository;
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
        private EventoDaTrilhaRepository $eventos,
        private ApresentacaoDoDocumento $apresentacao,
    ) {
    }

    /** @return array{documentos: list<array<string, mixed>>, pastas: list<string>} */
    public function executar(Tenant $tenant): array
    {
        $documentos = $this->documentos->resumosDoEscritorio($tenant);

        $pastas = [];
        foreach ($documentos as $documento) {
            if ($documento->pasta !== null && !\in_array($documento->pasta, $pastas, true)) {
                $pastas[] = $documento->pasta;
            }
        }

        return [
            'documentos' => $this->resumos($documentos),
            'pastas' => $pastas,
        ];
    }

    /**
     * Cada `Resumo` com o `emPapel` da função provisória (o Concluído que o escritório confirmou em papel).
     *
     * @param list<ResumoDoDocumento> $documentos
     *
     * @return list<array<string, mixed>>
     */
    private function resumos(array $documentos): array
    {
        $emPapel = $this->eventos->idsAssinadosEmPapel(array_map(static fn (ResumoDoDocumento $d): int => $d->id, $documentos));

        return array_map(
            fn (ResumoDoDocumento $d): array => $this->apresentacao->resumo($d) + ['emPapel' => \in_array($d->id, $emPapel, true)],
            $documentos,
        );
    }
}
