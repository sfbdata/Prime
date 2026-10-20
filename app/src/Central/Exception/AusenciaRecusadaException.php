<?php

declare(strict_types=1);

namespace App\Central\Exception;

/**
 * O pedido de mudar o limite de ausência não vale: quem pede não é primário (403), o valor não é da lista (422) ou
 * outro primário gravou a primeira escolha do escritório no mesmo instante (409).
 */
final class AusenciaRecusadaException extends \DomainException
{
    public const SEM_PERMISSAO = 'sem_permissao';
    public const FORA_DA_LISTA = 'fora_da_lista';
    public const CONCORRENTE = 'concorrente';

    private function __construct(string $mensagem, public readonly string $motivo)
    {
        parent::__construct($mensagem);
    }

    public static function semPermissao(): self
    {
        return new self('Só o usuário primário do escritório escolhe este tempo.', self::SEM_PERMISSAO);
    }

    public static function foraDaLista(): self
    {
        return new self('Escolha um dos tempos da lista.', self::FORA_DA_LISTA);
    }

    public static function concorrente(): self
    {
        return new self('Outra pessoa mudou este tempo agora mesmo. Recarregue a página e confira.', self::CONCORRENTE);
    }
}
