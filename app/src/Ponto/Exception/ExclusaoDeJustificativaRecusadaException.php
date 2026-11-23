<?php

declare(strict_types=1);

namespace App\Ponto\Exception;

/**
 * A justificativa não pode ser excluída pelo colaborador (P41 do desenho do Ponto). O `motivo`
 * decide a resposta HTTP; a mensagem é escrita para o usuário final e vai direto para a tela.
 */
final class ExclusaoDeJustificativaRecusadaException extends RecusaDoPonto
{
    /** Não existe neste escritório (ou sumiu sob a trava): para quem pede, é 404. */
    public const NAO_ENCONTRADA = 'nao_encontrada';

    /** Existe no escritório, mas é de outra pessoa: 403. */
    public const DE_OUTRA_PESSOA = 'de_outra_pessoa';

    /** O gestor já analisou: 422, a exclusão não vale mais. */
    public const JA_ANALISADA = 'ja_analisada';

    /** E12: é a justificativa de uma batida de trabalho externo; só o gestor decide sobre ela. 422. */
    public const DA_BATIDA_EXTERNA = 'da_batida_externa';

    /**
     * Em análise, mas um ajuste de batida aponta para ela (a inclusão do esquecimento que um abono gravou, a
     * desconsideração que a volta para análise gravou; E10): a linha fica, para o ajuste não perder a origem. 422.
     */
    public const MEXEU_NA_FOLHA = 'mexeu_na_folha';

    public const RECUSA_NAO_ENCONTRADA = 'Justificativa não encontrada.';
    public const RECUSA_DE_OUTRA_PESSOA = 'Só quem enviou a justificativa pode excluí-la.';
    public const RECUSA_JA_ANALISADA = 'Esta justificativa não está mais em análise e não pode ser excluída.';
    public const RECUSA_DA_BATIDA_EXTERNA = 'A justificativa da batida de trabalho externo não pode ser excluída. Ela segue para a análise do gestor.';
    public const RECUSA_MEXEU_NA_FOLHA = 'Esta justificativa já mexeu na folha do ponto, por isso não pode ser excluída. Ela continua em análise com o gestor.';

    public const CHAVE_DA_RECUSA = [
        self::RECUSA_NAO_ENCONTRADA => 'ponto_servidor.recusa.exclusao_justificativa.nao_encontrada',
        self::RECUSA_DE_OUTRA_PESSOA => 'ponto_servidor.recusa.exclusao_justificativa.de_outra_pessoa',
        self::RECUSA_JA_ANALISADA => 'ponto_servidor.recusa.exclusao_justificativa.ja_analisada',
        self::RECUSA_DA_BATIDA_EXTERNA => 'ponto_servidor.recusa.exclusao_justificativa.da_batida_externa',
        self::RECUSA_MEXEU_NA_FOLHA => 'ponto_servidor.recusa.exclusao_justificativa.mexeu_na_folha',
    ];

    private function __construct(public readonly string $motivo, string $recusa)
    {
        parent::__construct($recusa);
    }

    public static function naoEncontrada(): self
    {
        return new self(self::NAO_ENCONTRADA, self::RECUSA_NAO_ENCONTRADA);
    }

    public static function deOutraPessoa(): self
    {
        return new self(self::DE_OUTRA_PESSOA, self::RECUSA_DE_OUTRA_PESSOA);
    }

    public static function jaAnalisada(): self
    {
        return new self(self::JA_ANALISADA, self::RECUSA_JA_ANALISADA);
    }

    public static function daBatidaExterna(): self
    {
        return new self(self::DA_BATIDA_EXTERNA, self::RECUSA_DA_BATIDA_EXTERNA);
    }

    public static function mexeuNaFolha(): self
    {
        return new self(self::MEXEU_NA_FOLHA, self::RECUSA_MEXEU_NA_FOLHA);
    }
}
