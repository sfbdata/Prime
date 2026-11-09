<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\DTO;

/**
 * O que a transação de uma ação pública decidiu. A recusa que precisa ficar gravada (código errado, teto estourado)
 * não pode lançar dentro da transação, senão o ROLLBACK apagaria o erro contado: ela volta aqui e só é lançada depois
 * do COMMIT. As cartas também saem depois do COMMIT.
 */
final readonly class DesfechoDaAcaoPublica
{
    /**
     * @param list<CartaDaAssinatura> $cartas
     */
    private function __construct(
        public array $cartas,
        private ?\Exception $recusa,
    ) {
    }

    /** @param list<CartaDaAssinatura> $cartas */
    public static function feito(array $cartas = []): self
    {
        return new self($cartas, null);
    }

    public static function recusado(\Exception $recusa): self
    {
        return new self([], $recusa);
    }

    /** @throws \Exception a recusa gravada */
    public function lancarSeRecusado(): void
    {
        if ($this->recusa !== null) {
            throw $this->recusa;
        }
    }
}
