<?php

declare(strict_types=1);

namespace App\Agenda\Service;

/**
 * Enquanto a sincronia grava o que veio do Google, o ouvinte que pede sincronia depois de cada mudança
 * local fica calado: senão a própria sincronia pediria outra, em laço.
 */
final class SilencioDaSincronia
{
    private bool $calado = false;

    /**
     * @template T
     *
     * @param callable(): T $acao
     *
     * @return T
     */
    public function durante(callable $acao): mixed
    {
        $antes = $this->calado;
        $this->calado = true;
        try {
            return $acao();
        } finally {
            $this->calado = $antes;
        }
    }

    public function calado(): bool
    {
        return $this->calado;
    }
}
