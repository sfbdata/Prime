<?php

declare(strict_types=1);

namespace App\Central\Exception;

/**
 * Participante do grupo pedindo o que só o administrador do grupo faz (adicionar, remover). É participante — saber
 * que o grupo existe não é vazamento —, por isso 403 e não 404. O corpo leva `codigo = sem_permissao_no_grupo` para a
 * tela não confundir com o 403 de sessão encerrada.
 */
final class SemPermissaoNoGrupoException extends \DomainException
{
    public function __construct(string $mensagem = 'Só o administrador do grupo pode fazer isso.')
    {
        parent::__construct($mensagem);
    }
}
