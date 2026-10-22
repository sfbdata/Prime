<?php

declare(strict_types=1);

namespace App\Tarefa\DTO;

/**
 * Um despacho na linha do tempo da meta (desenho 04B de 22/10, L71 e L161), além do que o `TimelineItemDTO` já
 * traz (texto, autor, anexo). Os rótulos (`natureza`) são chaves do catálogo; a tela aplica `|trans`.
 *
 * `codigo` nulo é mensagem de antes da Fase 2a: sem linha de local e data nem linha de registro (D1).
 */
final class DespachoNaLinhaDoTempoOutput
{
    public function __construct(
        public readonly int $numero,
        public readonly bool $maisRecente,
        public readonly ?string $natureza,
        public readonly ?string $codigo,
        public readonly string $autor,
        public readonly ?string $cargo,
        public readonly \DateTimeImmutable $registradoEm,
        public readonly ?string $cidade,
        public readonly ?\DateTimeImmutable $prazo,
        public readonly bool $prazoVencido,
        public readonly ?string $ciente,
        public readonly ?\DateTimeImmutable $cienteEm,
        public readonly ?string $certidao,
        public readonly ?string $certificadoPor,
        public readonly ?\DateTimeImmutable $certificadoEm,
        public readonly bool $podeCertificar,
    ) {
    }
}
