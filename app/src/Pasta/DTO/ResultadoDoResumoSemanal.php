<?php

declare(strict_types=1);

namespace App\Pasta\DTO;

/**
 * O que o resumo semanal ao cliente fez num escritório (ou faria, na simulação): a semana, quantas
 * pastas tiveram resumo e para quantos e-mails.
 */
final readonly class ResultadoDoResumoSemanal
{
    public function __construct(
        public \DateTimeImmutable $inicio,
        public \DateTimeImmutable $fim,
        public int $pastas,
        public int $emails,
    ) {
    }

    public function semana(): string
    {
        return $this->inicio->format('d/m') . ' a ' . $this->fim->format('d/m');
    }
}
