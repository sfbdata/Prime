<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Exception;

/**
 * "Excluir definitivamente" com documento enviado na seleção e sem a confirmação do usuário: nada foi apagado. Vira 409
 * com `{precisa_confirmar: true, enviados}` para a tela abrir o aviso das evidências.
 */
final class ExclusaoPedeConfirmacao extends \DomainException
{
    public const CHAVE = 'assinatura_eletronica.erro.excluir_enviado_pede_confirmacao';

    public function __construct(
        public readonly int $enviados,
    ) {
        parent::__construct(self::CHAVE);
    }
}
