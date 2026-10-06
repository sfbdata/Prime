<?php

declare(strict_types=1);

namespace App\Pasta\DTO;

use App\Pasta\Entity\PastaDocumento;

/**
 * Um documento já existente com o MESMO conteúdo do que acabou de ser enviado — o que o aviso
 * "este arquivo já existe em…" mostra. Só o que a tela precisa: onde está (pasta) e como se chama.
 *
 * `pastaNup` é o número da pasta como a tela a identifica; sem número (pasta importada antes da
 * numeração) vai `#id`, a mesma convenção de `PastaVinculadaOutput`.
 */
final class DocumentoDuplicadoOutput
{
    public function __construct(
        public readonly int $documentoId,
        public readonly int $pastaId,
        public readonly string $pastaNup,
        public readonly string $titulo,
    ) {
    }

    public static function de(PastaDocumento $documento): self
    {
        $pasta   = $documento->getPasta();
        $pastaId = (int) $pasta?->getId();
        $nup     = trim((string) $pasta?->getNup());

        return new self(
            documentoId: (int) $documento->getId(),
            pastaId: $pastaId,
            pastaNup: $nup !== '' ? $nup : '#' . $pastaId,
            titulo: $documento->getTitulo(),
        );
    }

    /** O bloco `duplicadoDe[]` do JSON do upload — contrato com o JS da aba Documentos. */
    public function paraJson(): array
    {
        return [
            'pastaId'  => $this->pastaId,
            'pastaNup' => $this->pastaNup,
            'titulo'   => $this->titulo,
        ];
    }
}
