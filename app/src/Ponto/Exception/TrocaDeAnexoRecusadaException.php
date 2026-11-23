<?php

declare(strict_types=1);

namespace App\Ponto\Exception;

use App\Ponto\Service\NomesDasSituacoesDaJustificativa;

/**
 * O atestado de um lote de justificativas não pode ser trocado porque algum dia do lote já foi
 * analisado pelo gestor (docs/specs/ponto-troca-de-atestado-analisado.md, R1). A mensagem é escrita
 * para o usuário final: o controller a traduz pela chave() e a leva ao flash.
 */
final class TrocaDeAnexoRecusadaException extends RecusaDoPonto
{
    public const RECUSA_LOTE_ANALISADO = NomesDasSituacoesDaJustificativa::VIGENTES['anexo.travado'];

    /** @var array<string, string> */
    public const CHAVE_DA_RECUSA = [
        self::RECUSA_LOTE_ANALISADO => NomesDasSituacoesDaJustificativa::CHAVE_DA_TELA['anexo.travado'],
    ];
}
