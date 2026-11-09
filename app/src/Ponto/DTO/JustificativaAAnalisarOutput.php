<?php

declare(strict_types=1);

namespace App\Ponto\DTO;

use App\Ponto\Entity\JustificativaPonto;

/**
 * Uma linha de "Justificativas a analisar" da aba Equipe (desenho 16 L217 e L218): a pessoa, a data, o tipo e os campos
 * do subtítulo, com os mesmos nomes da justificativa, para o subtítulo sair do mesmo parcial da aba "Minhas
 * Justificativas" (`ponto/_justificativa_macros.html.twig`).
 */
final readonly class JustificativaAAnalisarOutput
{
    public function __construct(
        public int $id,
        public string $nome,
        public \DateTimeInterface $data,
        public ?string $tipo,
        public ?string $labelTipo,
        public ?string $tipoRegistroEsquecido,
        public ?\DateTimeInterface $horaRegistroEsquecido,
        public bool $abonoParcial,
        public ?\DateTimeInterface $horaInicioAbono,
        public ?\DateTimeInterface $horaFimAbono,
    ) {
    }

    public static function daJustificativa(JustificativaPonto $justificativa): self
    {
        $pessoa = $justificativa->getUser();

        return new self(
            (int) $justificativa->getId(),
            (string) ($pessoa?->getFullName() ?? $pessoa?->getEmail()),
            $justificativa->getData() ?? new \DateTimeImmutable('today'),
            $justificativa->getTipo(),
            $justificativa->getLabelTipo(),
            $justificativa->getTipoRegistroEsquecido(),
            $justificativa->getHoraRegistroEsquecido(),
            $justificativa->isAbonoParcial(),
            $justificativa->getHoraInicioAbono(),
            $justificativa->getHoraFimAbono(),
        );
    }
}
