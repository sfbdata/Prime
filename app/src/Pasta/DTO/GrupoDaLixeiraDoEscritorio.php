<?php

declare(strict_types=1);

namespace App\Pasta\DTO;

/**
 * Uma pasta na lixeira do escritório (`GET /expediente/lixeira`): quem é a pasta (número e nome,
 * como a busca rápida a mostra), quanto dela está na lixeira, quando e por quem foi excluído.
 *
 * As contagens são as da lixeira da própria pasta (`LixeiraDaPastaOutput::totalArquivos` e
 * `totalPastas`): o que saiu junto com uma subpasta conta, e o contrato de honorários com o
 * Financeiro oculto para quem lê não conta. Só escalar; as URLs e o token de lote ficam com o
 * controller.
 */
final class GrupoDaLixeiraDoEscritorio
{
    /**
     * @param list<string> $excluidoPor nomes distintos de quem excluiu, em ordem alfabética
     */
    public function __construct(
        public readonly int $pastaId,
        public readonly string $nup,
        public readonly ?string $nome,
        public readonly bool $pastaExcluida,
        public readonly int $documentos,
        public readonly int $subpastas,
        public readonly \DateTimeImmutable $maisRecente,
        public readonly \DateTimeImmutable $maisAntiga,
        public readonly array $excluidoPor,
        public readonly bool $autorSemRegistro,
    ) {
    }

    /** @return array<string, mixed> */
    public function paraJson(): array
    {
        return [
            'pastaId'          => $this->pastaId,
            'nup'              => $this->nup,
            'nome'             => $this->nome,
            'pastaExcluida'    => $this->pastaExcluida,
            'documentos'       => $this->documentos,
            'subpastas'        => $this->subpastas,
            'maisRecente'      => $this->maisRecente->format(\DateTimeInterface::ATOM),
            'maisAntiga'       => $this->maisAntiga->format(\DateTimeInterface::ATOM),
            'excluidoPor'      => $this->excluidoPor,
            'autorSemRegistro' => $this->autorSemRegistro,
        ];
    }
}
