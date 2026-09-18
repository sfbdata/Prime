<?php

declare(strict_types=1);

namespace App\Ponto\Exception;

/**
 * O atestado de um lote de justificativas não pode ser trocado porque algum dia do lote já foi
 * analisado pelo gestor (docs/specs/ponto-troca-de-atestado-analisado.md, R1). A mensagem é escrita
 * para o usuário final: o controller a repassa direto para o flash.
 */
final class TrocaDeAnexoRecusadaException extends \DomainException
{
}
