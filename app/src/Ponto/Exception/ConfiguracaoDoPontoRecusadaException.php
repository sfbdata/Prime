<?php

declare(strict_types=1);

namespace App\Ponto\Exception;

/**
 * A aba Configurações da /ponto (spec `docs/specs/ponto-desenho-0811.md`, E6) recusou gravar ou remover uma sede ou um
 * feriado. Leva o status que o controller devolve (404 para o que não é do escritório da sessão, 422 para o dado
 * inválido) e, no 422, o erro de cada campo, com a mensagem escrita para quem configura o ponto.
 */
final class ConfiguracaoDoPontoRecusadaException extends \DomainException
{
    /** @param array<string, string> $erros campo => mensagem */
    private function __construct(string $mensagem, private readonly int $status, private readonly array $erros)
    {
        parent::__construct($mensagem);
    }

    public static function naoEncontrada(string $mensagem): self
    {
        return new self($mensagem, 404, []);
    }

    /** @param array<string, string> $erros campo => mensagem */
    public static function invalida(array $erros): self
    {
        return new self(implode(' ', array_unique(array_values($erros))), 422, $erros);
    }

    public function status(): int
    {
        return $this->status;
    }

    /** @return array<string, string> */
    public function erros(): array
    {
        return $this->erros;
    }
}
