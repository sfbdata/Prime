<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Service\Cadeia;

/**
 * O validador enquanto o cliente HTTP da etapa 6 (bloco 6B) não entra: nunca configurado. O envio recusa gov.br e ICP
 * (E5-2) e, se alguém pedir uma conferência, ela fica indisponível em vez de aceitar sem cadeia.
 */
final class ValidadorDeCadeiaNaoConfigurado implements ValidadorDeCadeia
{
    public function configurado(): bool
    {
        return false;
    }

    public function conferir(string $pdf, string $ancora): ResultadoDaCadeia
    {
        throw new CadeiaIndisponivel('O validador de cadeia não está configurado.');
    }
}
