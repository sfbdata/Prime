<?php

declare(strict_types=1);

namespace App\Conector\Exception;

/**
 * O PortaoDoConector recusou a pessoa no escritório do token (spec mcp-remoto-oauth 4 e 4.1). A mensagem é fixa e pode
 * ir ao cliente de I.A.; o {@see $motivo} é para quem trata a recusa (resposta HTTP, log sem conteúdo) sem comparar
 * texto.
 */
final class AcessoDoConectorRecusado extends \RuntimeException
{
    public const MOTIVO_PESSOA = 'pessoa';
    public const MOTIVO_ESCRITORIO = 'escritorio';
    public const MOTIVO_TERMOS = 'termos';
    public const MOTIVO_FILTRO = 'filtro';

    public const PESSOA_INDISPONIVEL = 'Sua conta no BlueJus não está disponível.';
    public const ACESSO_INDISPONIVEL = 'Seu acesso a este escritório não está mais disponível.';
    public const TERMOS_NAO_ACEITOS = 'Para continuar, aceite os Termos de Uso no BlueJus.';
    public const FILTRO_NAO_LIGOU = 'Não foi possível abrir o escritório com segurança. Tente de novo em instantes.';

    private function __construct(string $mensagem, public readonly string $motivo, ?\Throwable $causa = null)
    {
        parent::__construct($mensagem, 0, $causa);
    }

    public static function pessoa(): self
    {
        return new self(self::PESSOA_INDISPONIVEL, self::MOTIVO_PESSOA);
    }

    /** Barreira do escritório: a mensagem é a da primeira barreira que falhou (BarreirasDoEscritorio). */
    public static function escritorio(string $mensagem = self::ACESSO_INDISPONIVEL): self
    {
        return new self($mensagem, self::MOTIVO_ESCRITORIO);
    }

    public static function termos(): self
    {
        return new self(self::TERMOS_NAO_ACEITOS, self::MOTIVO_TERMOS);
    }

    public static function filtro(?\Throwable $causa = null): self
    {
        return new self(self::FILTRO_NAO_LIGOU, self::MOTIVO_FILTRO, $causa);
    }
}
