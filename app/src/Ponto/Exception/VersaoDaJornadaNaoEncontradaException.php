<?php

declare(strict_types=1);

namespace App\Ponto\Exception;

/** A versão pedida não é da pessoa nem do escritório da URL (ou não existe): o controller responde 404. */
final class VersaoDaJornadaNaoEncontradaException extends RecusaDoPonto
{
    public const RECUSA_NAO_ENCONTRADA = 'Versão da jornada não encontrada.';

    public const CHAVE_DA_RECUSA = [
        self::RECUSA_NAO_ENCONTRADA => 'ponto_servidor.recusa.versao_jornada.nao_encontrada',
    ];

    public function __construct()
    {
        parent::__construct(self::RECUSA_NAO_ENCONTRADA);
    }
}
