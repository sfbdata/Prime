<?php

declare(strict_types=1);

namespace App\Cliente\Exception;

/**
 * O documento pedido para vincular ao cadastro não é um "Encontrado" deste cliente: não existe, é de outro escritório,
 * está na lixeira, não é de identificação nem comprovante, não é de uma pasta do cliente ou o usuário não vê a pasta.
 * Todos dão o mesmo 404: distinguir confirmaria que o id existe.
 */
final class DocumentoDaPastaNaoEncontradoException extends \DomainException
{
    public function __construct()
    {
        parent::__construct('Documento não encontrado.');
    }
}
