<?php

declare(strict_types=1);

namespace App\Pasta\Exception;

/**
 * O número digitado à mão (editar a pasta, ou a proposta de pasta do Chat I.A) já é de outra pasta viva do
 * escritório. A conferência roda sob a mesma trava da numeração automática e da restauração
 * (`GerarNumeroDePasta::recusaDoNumero`), então duas pessoas não terminam com o mesmo número. A pasta na lixeira
 * não ocupa número. O acervo e a descoberta pelo Drive continuam aceitando número repetido (10A/10B do legado).
 *
 * Estende `\InvalidArgumentException` para cair no mesmo `catch` que já mostra o aviso na tela de editar.
 */
final class NumeroDePastaEmUsoException extends \InvalidArgumentException
{
    public function __construct(public readonly string $nup)
    {
        parent::__construct(sprintf('O número %s já é de outra pasta do escritório. Escolha outro número.', $nup));
    }
}
