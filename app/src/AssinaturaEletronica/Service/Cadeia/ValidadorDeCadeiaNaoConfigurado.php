<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Service\Cadeia;

/**
 * Um validador que nunca está configurado: o envio recusa gov.br e ICP (E5-2) e a conferência fica indisponível em vez
 * de aceitar sem cadeia. Fora do container desde o bloco 6B (o alias aponta o {@see HttpValidadorDeCadeia}, que fica
 * assim com as envs vazias); serve de dublê nos testes de quem consulta {@see ValidadorDeCadeia::configurado()}.
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
