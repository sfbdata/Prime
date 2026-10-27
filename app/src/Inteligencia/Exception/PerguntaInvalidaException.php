<?php

declare(strict_types=1);

namespace App\Inteligencia\Exception;

/**
 * A pergunta da aba "Perguntar" não cabe na regra (spec inteligencia-drawer-da-pasta, F3): vazia
 * depois de aparada ou com mais de 2000 caracteres. O controller responde 422 com a mensagem; nada é
 * persistido nem conta no limitador.
 */
final class PerguntaInvalidaException extends \DomainException
{
    public const MOTIVO = 'pergunta_invalida';

    public static function vazia(): self
    {
        return new self('Escreva a pergunta antes de enviar.');
    }

    public static function longaDemais(int $maximo): self
    {
        return new self(sprintf('A pergunta passa de %d caracteres. Encurte e envie de novo.', $maximo));
    }

    public static function pastaInvalida(): self
    {
        return new self('Pasta inválida.');
    }

    public function motivo(): string
    {
        return self::MOTIVO;
    }
}
