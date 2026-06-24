<?php

declare(strict_types=1);

namespace App\Pasta\Exception;

/**
 * Lançada quando uma observação da aba Detalhes não satisfaz as regras de
 * edição (apenas o autor, dentro da janela de 24h). É uma falha de regra de
 * negócio — distinta de negação de acesso (AccessDeniedException) — para que o
 * controller a trate com mensagem ao usuário sem mascarar erros de autorização.
 */
final class ObservacaoDetalhesNaoEditavelException extends \DomainException
{
}
