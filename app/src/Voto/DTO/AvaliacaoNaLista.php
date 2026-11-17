<?php

declare(strict_types=1);

namespace App\Voto\DTO;

use App\Voto\Entity\OcorrenciaDaUnidade;
use App\Voto\Enum\AvaliacaoDaOcorrencia;

/**
 * Uma avaliação na aba "Avaliações" do conselho (desenho 18, H273/H469): quem é a unidade, a ocorrência, quem a
 * registrou e, decidida, quem decidiu e a justificativa.
 */
final readonly class AvaliacaoNaLista
{
    public function __construct(
        public int $id,
        /** "Responsável · unidade" (ou só a unidade, sem responsável), como o desenho (nome e identificação). */
        public string $nome,
        public string $texto,
        public \DateTimeImmutable $registradaEm,
        public string $registradaPor,
        public AvaliacaoDaOcorrencia $avaliacao,
        public ?\DateTimeImmutable $decididaEm,
        public ?string $decididaPor,
        public ?string $justificativa,
    ) {
    }

    /** Só ocorrência com avaliação vira item da lista (a da pessoa apta e a da importação não têm). */
    public static function deOcorrencia(OcorrenciaDaUnidade $o): self
    {
        $unidade = $o->getUnidade();
        $rotulo = '' === $unidade->getBloco() ? $unidade->getIdentificacao() : $unidade->getIdentificacao() . ' · ' . $unidade->getBloco();
        $responsavel = trim((string) $unidade->getResponsavel());

        return new self(
            id: (int) $o->getId(),
            nome: '' === $responsavel ? $rotulo : $responsavel . ' · ' . $rotulo,
            texto: $o->getTexto(),
            registradaEm: $o->getRegistradaEm(),
            registradaPor: $o->getRegistradaPorNome(),
            avaliacao: $o->getAvaliacao() ?? throw new \LogicException('Só ocorrência com avaliação chega aqui.'),
            decididaEm: $o->getDecididaEm(),
            decididaPor: $o->getDecididaPorNome(),
            justificativa: $o->getJustificativa(),
        );
    }

    public function pendente(): bool
    {
        return AvaliacaoDaOcorrencia::Pendente === $this->avaliacao;
    }
}
