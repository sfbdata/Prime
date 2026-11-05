<?php

declare(strict_types=1);

namespace App\Sync\Exception;

/**
 * O "Importar do Google Drive" não pode seguir com o item pedido. A mensagem é para o usuário e vai ao modal
 * como está: pasta fora da pasta do escritório, item que não existe ou tipo do Google que não exporta.
 */
final class ImportacaoDoDriveRecusadaException extends \RuntimeException
{
    public const FORA_DA_RAIZ = 'Escolha uma pasta dentro da pasta do escritório no Google Drive.';
    public const NAO_ENCONTRADO = 'Não encontrei esse item no Google Drive do escritório.';
    public const NAO_E_PASTA = 'O link não é de uma pasta do Google Drive.';

    public static function foraDaRaiz(): self
    {
        return new self(self::FORA_DA_RAIZ);
    }

    public static function naoEncontrado(): self
    {
        return new self(self::NAO_ENCONTRADO);
    }

    public static function naoEPasta(): self
    {
        return new self(self::NAO_E_PASTA);
    }

    public static function tipoNaoAceito(string $motivo): self
    {
        return new self($motivo);
    }
}
