<?php

declare(strict_types=1);

namespace App\Ponto\Exception;

/**
 * O aceite mensal da folha não pôde ser gravado (spec ponto-desenho-0811, E16, P33; decisões 1, 16 e 19). O `motivo`
 * decide a resposta; a mensagem é escrita para quem está na tela. "Descreva a ressalva." é a do desenho (L809); as
 * outras são novas, sem travessão.
 */
final class AssinaturaDaFolhaRecusadaException extends \DomainException
{
    /** A função `ponto-aceite` desligada (Master ou escritório) ou o selo da folha indisponível. */
    public const FUNCAO_DESLIGADA = 'funcao_desligada';

    /** A competência é o mês corrente ou um mês futuro, pelo relógio do servidor. */
    public const MES_ABERTO = 'mes_aberto';

    /** A competência é anterior ao início da contagem da pessoa neste escritório (fora da lista da folha). */
    public const FORA_DA_FOLHA = 'fora_da_folha';

    /** Já há aceite que vale e a folha não mudou depois dele (inclusive o segundo de dois envios ao mesmo tempo). */
    public const JA_ASSINADA = 'ja_assinada';

    /** Ressalva sem texto ou com mais de 1.000 caracteres. */
    public const RESSALVA_INVALIDA = 'ressalva_invalida';

    private const MENSAGENS = [
        self::FUNCAO_DESLIGADA => 'O aceite da folha não está disponível neste escritório.',
        self::MES_ABERTO       => 'Só dá para assinar a folha de um mês já fechado.',
        self::FORA_DA_FOLHA    => 'Este mês não está na sua folha de ponto.',
        self::JA_ASSINADA      => 'Esta folha já foi assinada.',
    ];

    private function __construct(public readonly string $motivo, string $mensagem, ?\Throwable $anterior = null)
    {
        parent::__construct($mensagem, 0, $anterior);
    }

    /** Uma das recusas de {@see self::MENSAGENS}, pelo motivo. */
    public static function por(string $motivo): self
    {
        return new self($motivo, self::MENSAGENS[$motivo] ?? throw new \LogicException(sprintf('Motivo de recusa desconhecido: %s.', $motivo)));
    }

    public static function jaAssinada(?\Throwable $anterior = null): self
    {
        return new self(self::JA_ASSINADA, self::MENSAGENS[self::JA_ASSINADA], $anterior);
    }

    /** A ressalva fora da regra da entidade, com a mensagem dela ("Descreva a ressalva." ou o limite de 1.000). */
    public static function ressalvaInvalida(\DomainException $regra): self
    {
        return new self(self::RESSALVA_INVALIDA, $regra->getMessage(), $regra);
    }
}
