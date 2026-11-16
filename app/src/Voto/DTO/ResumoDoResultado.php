<?php

declare(strict_types=1);

namespace App\Voto\DTO;

/**
 * O resumo para divulgar da assembleia (imagem e PDF): título, QR do link público, código de conferência e as pautas
 * apuradas. O hash SHA-256 é do conteúdo (título, código e pautas); a data de emissão fica de fora dele.
 */
final readonly class ResumoDoResultado
{
    /** @param list<PautaNoResumo> $pautas */
    public function __construct(
        public int $assembleiaId,
        public string $titulo,
        public string $link,
        public string $codigoDeConferencia,
        public \DateTimeImmutable $emitidoEm,
        public string $hash,
        public array $pautas,
    ) {
    }
}
