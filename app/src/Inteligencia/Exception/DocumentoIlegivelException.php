<?php

declare(strict_types=1);

namespace App\Inteligencia\Exception;

/**
 * No worker, um documento marcado para o "Ler N documentos" não pode ir ao provedor: saiu da pasta ou do
 * escritório, foi para a lixeira, ficou oculto para quem pediu, sumiu do armazenamento, o conteúdo não é PDF
 * nem imagem ou passou do limite. O handler marca a análise como `falhou` com a mensagem, SEM chamar o
 * provedor e sem relançar.
 *
 * A mensagem leva só o id do documento e o motivo, nunca o nome do arquivo nem o conteúdo: ela é gravada
 * como motivo da análise e vai ao log.
 */
final class DocumentoIlegivelException extends \RuntimeException
{
    public static function porque(int $documentoId, string $motivo): self
    {
        return new self(sprintf('documento %d não pode ser lido (%s)', $documentoId, $motivo));
    }
}
