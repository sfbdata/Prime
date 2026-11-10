<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Service\ConferenciaDePdf;

/**
 * Interna do motor: o que o `confere` do protótipo devolve para um SignerInfo. `motivo` explica por que não deu para
 * conferir (quando `resumoConfere` ou `chaveConfere` é null). `vinculo` já leva em conta a chave (M24).
 */
final readonly class ConferenciaDoCms
{
    public function __construct(
        public ?string $algoritmoDoResumo,
        public ?bool $resumoConfere,
        public ?bool $chaveConfere,
        public ?CertificadoDoSignatario $certificado,
        public ?\DateTimeImmutable $horaAssinada,
        public ?string $motivo,
        public VinculoDoCertificado $vinculo = VinculoDoCertificado::NaoConferido,
    ) {
    }
}
