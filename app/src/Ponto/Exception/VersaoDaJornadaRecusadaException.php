<?php

declare(strict_types=1);

namespace App\Ponto\Exception;

/**
 * Regra das versões da jornada recusou a gravação (data repetida, única versão). A mensagem é escrita para quem
 * administra o ponto: o controller a devolve com 422, no idioma da requisição (chave e parâmetros em
 * {@see RecusaDoPonto}).
 */
final class VersaoDaJornadaRecusadaException extends RecusaDoPonto
{
    public const RECUSA_VERSAO_UNICA = 'A jornada tem uma versão só. Para tirar a jornada personalizada, use "Limpar escala".';
    public const RECUSA_DATA_REPETIDA = 'Já existe uma versão da jornada que começa em %s.';

    public const CHAVE_DA_RECUSA = [
        self::RECUSA_VERSAO_UNICA => 'ponto_servidor.recusa.versao_jornada.versao_unica',
        self::RECUSA_DATA_REPETIDA => 'ponto_servidor.recusa.versao_jornada.data_repetida',
    ];
}
