<?php

declare(strict_types=1);

namespace App\Tests\AssinaturaEletronica\Support;

use App\AssinaturaEletronica\Service\Cadeia\ResultadoDaCadeia;
use App\AssinaturaEletronica\Service\Cadeia\ValidadorDeCadeia;

/**
 * Dublê do validador de cadeia para quem não pode chamá-lo (a pré-conferência do PDF assinado, bloco 5C): diz se está
 * configurado e conta as chamadas a {@see conferir()}, que falha o teste se acontecer de verdade.
 *
 * No funcional, substitui o `HttpValidadorDeCadeia` no container (o alias da interface é resolvido na compilação),
 * antes da primeira requisição.
 */
final class ValidadorDeCadeiaContado implements ValidadorDeCadeia
{
    public int $conferencias = 0;

    public function __construct(
        private readonly bool $configurado = true,
    ) {
    }

    public function configurado(): bool
    {
        return $this->configurado;
    }

    public function conferir(string $pdf, string $ancora): ResultadoDaCadeia
    {
        ++$this->conferencias;

        throw new \LogicException('O validador de cadeia não pode ser chamado aqui.');
    }
}
