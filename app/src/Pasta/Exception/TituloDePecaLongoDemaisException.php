<?php

declare(strict_types=1);

namespace App\Pasta\Exception;

/**
 * O título de uma peça não cabe em `titulo`/`nome_original` (VARCHAR 255).
 *
 * Existe separada para o controller da edição responder 400 SÓ a ela: um `catch` de
 * `InvalidArgumentException` genérico pegaria também erro do Doctrine no `flush`
 * (`ORMInvalidArgumentException`) e o devolveria ao usuário como pedido inválido, com o arquivo
 * já regravado. Estende `InvalidArgumentException` para a criação, que já trata essa família
 * como 400, continuar igual.
 */
final class TituloDePecaLongoDemaisException extends \InvalidArgumentException
{
    public function __construct()
    {
        parent::__construct('O título da peça é longo demais (máximo de 250 caracteres).');
    }
}
