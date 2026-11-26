<?php

declare(strict_types=1);

namespace App\Voto\DTO;

use App\Voto\Entity\OcorrenciaDaVotacao;

/** Uma ocorrência registrada pelo eleitor, como a lista do gestor e a ata a mostram. `pauta` é null para a votação toda. */
final readonly class OcorrenciaNaVotacao
{
    public function __construct(
        public \DateTimeImmutable $criadaEm,
        /** "Nome · unidade" (qualificação congelada no registro). */
        public string $qualificacao,
        public ?string $pauta,
        public string $texto,
    ) {
    }

    public static function deOcorrencia(OcorrenciaDaVotacao $o): self
    {
        $nome = trim($o->getNomeDoEleitor());

        return new self(
            $o->getCriadaEm(),
            '' === $nome ? $o->getRotuloDaUnidade() : $nome . ' · ' . $o->getRotuloDaUnidade(),
            $o->getPauta()?->getTitulo(),
            $o->getTexto(),
        );
    }
}
