<?php

declare(strict_types=1);

namespace App\Inteligencia\Exception;

/**
 * No worker, o armazenamento não respondeu ao ler um arquivo do "Ler N documentos" (`FalhaDeArmazenamento`: I/O,
 * permissão, disco). Não diz nada sobre o documento, então é TRANSITÓRIA: o handler devolve a análise à fila e o
 * Messenger tenta de novo, como na falha de rede do provedor; esgotadas as tentativas, a análise falha.
 *
 * A mensagem leva só o id do documento e a classe da falha, nunca o nome, a chave nem o conteúdo.
 */
final class ArmazenamentoIndisponivelException extends \RuntimeException
{
    public static function aoLer(int $documentoId, \Throwable $falha): self
    {
        return new self(sprintf('documento %d: falha ao ler o armazenamento (%s)', $documentoId, (new \ReflectionClass($falha))->getShortName()));
    }
}
