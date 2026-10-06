<?php

declare(strict_types=1);

namespace App\Processo\Exception;

/**
 * Lançada quando uma nota técnica não satisfaz as regras de edição (apenas o autor, dentro da
 * janela da `JanelaDeEdicaoDeComentario`). É falha de regra de negócio — distinta de negação de
 * acesso (AccessDeniedException) — para o controller responder com mensagem ao usuário sem
 * mascarar erros de autorização.
 */
final class NotaTecnicaNaoEditavelException extends \DomainException
{
}
