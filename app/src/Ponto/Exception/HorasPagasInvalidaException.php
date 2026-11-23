<?php

declare(strict_types=1);

namespace App\Ponto\Exception;

/**
 * Erro de regra de negócio do lançamento de horas pagas. A mensagem é escrita para o usuário final:
 * o controller a traduz pela chave() e a leva para o flash (chave e parâmetros em {@see RecusaDoPonto}).
 */
final class HorasPagasInvalidaException extends RecusaDoPonto
{
    public const RECUSA_OUTRO_ESCRITORIO = 'Lançamento não pertence a este escritório.';
    public const RECUSA_OPERACAO_INVALIDA = 'Operação inválida.';
    public const RECUSA_QUANTIDADE_ZERO = 'Informe uma quantidade de horas maior que zero.';
    public const RECUSA_QUANTIDADE_MAXIMA = 'A quantidade de horas não pode passar de %d.';
    public const RECUSA_MOTIVO_OBRIGATORIO = 'Informe o motivo do lançamento.';
    public const RECUSA_MOTIVO_CURTO = 'O motivo precisa ter ao menos %d caracteres.';
    public const RECUSA_COMPETENCIA_FUTURA = 'A competência não pode ser futura.';
    public const RECUSA_COLABORADOR_OUTRO_ESCRITORIO = 'Colaborador não pertence a este escritório.';

    public const CHAVE_DA_RECUSA = [
        self::RECUSA_OUTRO_ESCRITORIO => 'ponto_servidor.recusa.horas_pagas.outro_escritorio',
        self::RECUSA_OPERACAO_INVALIDA => 'ponto_servidor.recusa.horas_pagas.operacao_invalida',
        self::RECUSA_QUANTIDADE_ZERO => 'ponto_servidor.recusa.horas_pagas.quantidade_zero',
        self::RECUSA_QUANTIDADE_MAXIMA => 'ponto_servidor.recusa.horas_pagas.quantidade_maxima',
        self::RECUSA_MOTIVO_OBRIGATORIO => 'ponto_servidor.recusa.horas_pagas.motivo_obrigatorio',
        self::RECUSA_MOTIVO_CURTO => 'ponto_servidor.recusa.horas_pagas.motivo_curto',
        self::RECUSA_COMPETENCIA_FUTURA => 'ponto_servidor.recusa.horas_pagas.competencia_futura',
        self::RECUSA_COLABORADOR_OUTRO_ESCRITORIO => 'ponto_servidor.recusa.horas_pagas.colaborador_outro_escritorio',
    ];
}
