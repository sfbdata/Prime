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
    public const MOTIVO_JA_ABERTO = 'ja_aberto';
    public const MOTIVO_LIBERACAO = 'liberacao';

    public const PESSOA_INDISPONIVEL = 'Sua conta no BlueJus não está disponível.';
    public const ACESSO_INDISPONIVEL = 'Seu acesso a este escritório não está mais disponível.';
    public const TERMOS_NAO_ACEITOS = 'Para continuar, aceite os Termos de Uso no BlueJus.';
    public const FILTRO_NAO_LIGOU = 'Não foi possível abrir o escritório com segurança. Tente de novo em instantes.';
    public const JA_ABERTO = 'Esta requisição já está aberta em um escritório.';
    public const CONECTOR_NAO_LIBERADO = 'O conector de I.A. não está liberado para este escritório.';

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

    /** O portão já abriu esta requisição: abrir de novo recusa, sem mexer no que a primeira abertura fixou. */
    public static function jaAberto(): self
    {
        return new self(self::JA_ABERTO, self::MOTIVO_JA_ABERTO);
    }

    /**
     * A liberação do conector nos três níveis (decisão 14.a) não está completa: chave geral `conector_ia` desligada
     * pelo Master, escritório não liberado pelo Master, ou conector desligado ou sem o aceite vigente no escritório. A
     * mensagem é uma só para os três: o app de I.A. não precisa saber qual nível faltou.
     */
    public static function liberacao(): self
    {
        return new self(self::CONECTOR_NAO_LIBERADO, self::MOTIVO_LIBERACAO);
    }

    public static function filtro(?\Throwable $causa = null): self
    {
        return new self(self::FILTRO_NAO_LIGOU, self::MOTIVO_FILTRO, $causa);
    }
}
