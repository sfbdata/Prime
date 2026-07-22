<?php

declare(strict_types=1);

namespace App\Cobranca\Exception;

/**
 * Tentativa de editar ou apagar um evento do histórico que não pode ser mexido (ajuste 2026-07):
 * evento que não é anotação, anotação de outra pessoa, ou anotação cuja janela de 48h já passou.
 *
 * A mensagem é a MESMA nos três casos, de propósito: dizer "essa anotação é de outro usuário" contaria
 * a quem tentou algo sobre um registro que não é dele. Para o autor legítimo dentro do prazo a UI nem
 * mostra os botões — chegar aqui significa POST forjado ou janela vencida com a tela velha aberta.
 */
final class AnotacaoNaoEditavelException extends \DomainException
{
    public function __construct()
    {
        parent::__construct(
            'Esta anotação não pode mais ser alterada. Só o autor pode corrigi-la, e apenas nas '
            . 'primeiras 48 horas. Registre uma nova anotação com a correção.',
        );
    }
}
