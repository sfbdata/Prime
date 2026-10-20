<?php

declare(strict_types=1);

namespace App\Pasta\Exception;

/**
 * Recusa de domínio do "ocultar aba" (spec pasta-ocultar-aba §2). Duas causas, com respostas HTTP
 * diferentes no controller: quem não configura (403, texto do desenho L.4411) e pasta de outro
 * escritório (404, sem revelar que existe).
 */
final class AbaOcultaRecusadaException extends \DomainException
{
    public const SO_PRIMARIO = 'Só o usuário primário pode ocultar abas';
    public const PASTA_DE_OUTRO_ESCRITORIO = 'Pasta não encontrada.';

    private function __construct(string $mensagem, private readonly bool $pastaDeOutroEscritorio)
    {
        parent::__construct($mensagem);
    }

    public static function soPrimario(): self
    {
        return new self(self::SO_PRIMARIO, false);
    }

    public static function pastaDeOutroEscritorio(): self
    {
        return new self(self::PASTA_DE_OUTRO_ESCRITORIO, true);
    }

    public function ehPastaDeOutroEscritorio(): bool
    {
        return $this->pastaDeOutroEscritorio;
    }
}
