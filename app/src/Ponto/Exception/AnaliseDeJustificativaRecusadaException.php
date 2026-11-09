<?php

declare(strict_types=1);

namespace App\Ponto\Exception;

use App\Ponto\Service\AnaliseDaPropriaJustificativa;
use App\Ponto\Service\HorarioFuturoDaBatida;

/**
 * A análise do gestor não foi gravada (docs/specs/ponto-desenho-0811.md, E3 e corrida da E3). Nada mudou no banco; a
 * mensagem é escrita para o usuário final e vai direto para a tela. O `motivo` deixa quem chama escolher a resposta
 * (a ficha volta com aviso; a aba Equipe da E5 decide a dela).
 */
final class AnaliseDeJustificativaRecusadaException extends \DomainException
{
    /** O colaborador excluiu a justificativa (P41) entre a leitura e a gravação. */
    public const EXCLUIDA = 'excluida';

    /** Outra análise comitou antes, ou a justificativa já não está na situação que a ação pede. */
    public const SITUACAO_MUDOU = 'situacao_mudou';

    /** Não existe neste escritório (id de outro escritório incluído): para quem pede, não existe. */
    public const NAO_ENCONTRADA = 'nao_encontrada';

    /** A própria justificativa, de quem não é primário nem Master (P43 a, AnaliseDaPropriaJustificativa). */
    public const PROPRIA = 'propria';

    /** Esquecimento de uma batida que já existe no dia (R1 de ponto-esquecimento-nao-trava). */
    public const BATIDA_JA_REGISTRADA = 'batida_ja_registrada';

    /** Esquecimento com data e hora acima de agora. */
    public const HORARIO_FUTURO = 'horario_futuro';

    public const MENSAGEM_EXCLUIDA = 'Esta justificativa foi excluída pelo colaborador.';

    private function __construct(public readonly string $motivo, string $mensagem)
    {
        parent::__construct($mensagem);
    }

    public static function excluida(): self
    {
        return new self(self::EXCLUIDA, self::MENSAGEM_EXCLUIDA);
    }

    public static function situacaoMudou(string $mensagem): self
    {
        return new self(self::SITUACAO_MUDOU, $mensagem);
    }

    public static function naoEncontrada(): self
    {
        return new self(self::NAO_ENCONTRADA, 'Justificativa não encontrada.');
    }

    public static function propria(): self
    {
        return new self(self::PROPRIA, AnaliseDaPropriaJustificativa::RECUSA);
    }

    public static function batidaJaRegistrada(string $mensagem): self
    {
        return new self(self::BATIDA_JA_REGISTRADA, $mensagem);
    }

    public static function horarioFuturo(): self
    {
        return new self(self::HORARIO_FUTURO, HorarioFuturoDaBatida::MENSAGEM);
    }
}
