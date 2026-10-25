<?php

declare(strict_types=1);

namespace App\Inteligencia\Exception;

/**
 * A versão ajustada de um documento jurídico foi recusada (422): tipo fora da lista, conteúdo que não bate com a
 * extensão, arquivo grande demais, vazio ou questão que não pede o documento. A mensagem é a que o Master lê.
 */
final class DocumentoJuridicoRecusadoException extends \DomainException
{
}
