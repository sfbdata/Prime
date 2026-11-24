<?php

declare(strict_types=1);

namespace App\Produto\Exception;

use Symfony\Component\HttpFoundation\Response;

/**
 * O dono não pode ligar ou desligar esta ferramenta agora (spec docs/specs/multiproduto-contratacao-e-voto.md, §9). A
 * mensagem é curta e vai direto para a tela; `status` é o código HTTP que o controller devolve.
 */
final class FerramentaRecusadaException extends \DomainException
{
    private function __construct(string $mensagem, public readonly int $status)
    {
        parent::__construct($mensagem);
    }

    /** Regra 1: só o dono do escritório. Vem antes de qualquer outra checagem. */
    public static function soODono(): self
    {
        return new self('Só o dono do escritório pode ligar ou desligar ferramentas.', Response::HTTP_FORBIDDEN);
    }

    /** O `completo` (ou qualquer valor fora das ferramentas) não se liga nem desliga pelo Meu Perfil. */
    public static function naoLigavel(): self
    {
        return new self('Ferramenta não encontrada.', Response::HTTP_NOT_FOUND);
    }

    /** D16: o Master desligou o produto; só ele religa. */
    public static function travadaPeloMaster(): self
    {
        return new self('Esta ferramenta foi desligada pelo BlueJus. Fale com o suporte.', Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    /** Regra 7: o Voto com assembleia aberta não desliga. */
    public static function assembleiaAberta(): self
    {
        return new self('O Voto tem uma assembleia em andamento. Desligue depois que ela acabar.', Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    /** D7: o escritório fica com pelo menos uma ferramenta ligada. */
    public static function ultimaFerramenta(): self
    {
        return new self('Esta é a última ferramenta ligada do escritório. Ligue outra antes de desligar esta.', Response::HTTP_UNPROCESSABLE_ENTITY);
    }
}
