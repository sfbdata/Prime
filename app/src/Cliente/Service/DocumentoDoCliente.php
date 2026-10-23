<?php

declare(strict_types=1);

namespace App\Cliente\Service;

/**
 * Documento (CPF/CNPJ) do cliente na forma comparável: só os dígitos. O banco guarda com e sem máscara
 * ("006.462.251-70" e "00646225170"); comparar a string crua deixaria passar o mesmo documento escrito de outro jeito.
 *
 * Mesmo critério do `ClientesDuplicadosDaPasta::digitosDe` (#107), que vai delegar a este ponto (contrato com a 9b).
 * Sem chave nem HMAC: a F-A da Inteligência cadastral não compara documento na tela.
 */
final class DocumentoDoCliente
{
    private function __construct()
    {
    }

    public static function digitos(string $documento): string
    {
        return (string) preg_replace('/\D/', '', $documento);
    }
}
