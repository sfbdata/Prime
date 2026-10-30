<?php

declare(strict_types=1);

namespace App\ChatIa\Exception;

use App\ChatIa\Enum\EstadoDaProposta;

/** A proposta não está mais pendente (já confirmada, cancelada ou vencida): nada executa duas vezes (409). */
final class PropostaEncerradaException extends \DomainException
{
    private function __construct(string $mensagem, private readonly string $codigoDoErro)
    {
        parent::__construct($mensagem);
    }

    public static function vencida(): self
    {
        return new self('A proposta venceu. Peça de novo à I.A.', 'proposta_vencida');
    }

    public static function jaDecidida(EstadoDaProposta $estado): self
    {
        return match ($estado) {
            EstadoDaProposta::Confirmada => new self('Esta proposta já foi confirmada.', 'proposta_confirmada'),
            EstadoDaProposta::Cancelada => new self('Esta proposta foi cancelada.', 'proposta_encerrada'),
            default => self::vencida(),
        };
    }

    public function codigo(): string
    {
        return $this->codigoDoErro;
    }
}
