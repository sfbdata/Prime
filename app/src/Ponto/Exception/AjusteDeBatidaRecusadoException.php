<?php

declare(strict_types=1);

namespace App\Ponto\Exception;

use App\Ponto\Service\HorarioFuturoDaBatida;

/**
 * O ajuste de batida não foi gravado (E10, plano-e10-ponto §5). Nada mudou no banco nem na trilha; a mensagem é escrita
 * para o usuário final e vai direto para a tela. O `motivo` deixa quem chama escolher a resposta (a ficha volta com
 * aviso; a própria batida de quem não é primário nem Master responde 403, P43).
 */
final class AjusteDeBatidaRecusadoException extends \DomainException
{
    /** A batida ganhou outro ajuste entre a abertura da folha e a gravação (a cabeça esperada não é mais a cabeça). */
    public const MUDOU = 'mudou';

    /** Não existe nesta folha (de outro escritório, de outra pessoa, ou alvo inválido): para quem pede, não existe. */
    public const NAO_ENCONTRADA = 'nao_encontrada';

    /** Data e hora acima de agora ({@see HorarioFuturoDaBatida}). */
    public const FUTURO = 'futuro';

    /** A alteração repete o tipo, a data e a hora que a batida já tem. */
    public const NADA_MUDOU = 'nada_mudou';

    /** Motivo vazio (só espaços). */
    public const SEM_MOTIVO = 'sem_motivo';

    /** Motivo acima de 2000 caracteres (o limite do CHECK `chk_ponto_ajuste_motivo`). */
    public const MOTIVO_LONGO = 'motivo_longo';

    /** Restaurar a desconsideração feita pela análise da justificativa (decisão A do plano). */
    public const LIGADA_A_JUSTIFICATIVA = 'ligada_a_justificativa';

    /** A própria batida, de quem não é primário nem Master (P43): o controller responde 403. */
    public const DA_PROPRIA_BATIDA = 'da_propria_batida';

    /**
     * Ajustar ou desconsiderar pela ficha a batida com justificativa ainda em análise (E10, decisão C5): quem decide é a
     * análise. Inclui a batida de trabalho externo (E12).
     */
    public const EM_ANALISE = 'em_analise';

    public const MENSAGEM_MUDOU = 'Esta batida mudou desde que a folha foi aberta. Confira a folha e tente de novo.';

    public const MENSAGEM_NAO_ENCONTRADA = 'Batida não encontrada nesta folha.';

    public const MENSAGEM_NADA_MUDOU = 'O ajuste não muda nada: a batida já tem este tipo, esta data e esta hora.';

    public const MENSAGEM_SEM_MOTIVO = 'Informe o motivo da correção.';

    public const MENSAGEM_MOTIVO_LONGO = 'O motivo da correção passa de 2000 caracteres. Resuma o texto.';

    public const MENSAGEM_LIGADA_A_JUSTIFICATIVA = 'Esta batida saiu da conta pela análise da justificativa e só volta com um novo abono.';

    public const MENSAGEM_DA_PROPRIA_BATIDA = 'Só o usuário primário do escritório ou o Master pode corrigir a própria batida.';

    public const MENSAGEM_EM_ANALISE = 'Esta batida tem uma justificativa em análise. Abone ou recuse a justificativa antes de corrigir a batida.';

    private function __construct(public readonly string $motivo, string $mensagem)
    {
        parent::__construct($mensagem);
    }

    public static function mudou(): self
    {
        return new self(self::MUDOU, self::MENSAGEM_MUDOU);
    }

    public static function naoEncontrada(): self
    {
        return new self(self::NAO_ENCONTRADA, self::MENSAGEM_NAO_ENCONTRADA);
    }

    public static function futuro(): self
    {
        return new self(self::FUTURO, HorarioFuturoDaBatida::MENSAGEM);
    }

    public static function nadaMudou(): self
    {
        return new self(self::NADA_MUDOU, self::MENSAGEM_NADA_MUDOU);
    }

    public static function semMotivo(): self
    {
        return new self(self::SEM_MOTIVO, self::MENSAGEM_SEM_MOTIVO);
    }

    public static function motivoLongo(): self
    {
        return new self(self::MOTIVO_LONGO, self::MENSAGEM_MOTIVO_LONGO);
    }

    public static function ligadaAJustificativa(): self
    {
        return new self(self::LIGADA_A_JUSTIFICATIVA, self::MENSAGEM_LIGADA_A_JUSTIFICATIVA);
    }

    public static function daPropriaBatida(): self
    {
        return new self(self::DA_PROPRIA_BATIDA, self::MENSAGEM_DA_PROPRIA_BATIDA);
    }

    public static function emAnalise(): self
    {
        return new self(self::EM_ANALISE, self::MENSAGEM_EM_ANALISE);
    }
}
