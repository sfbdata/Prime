<?php

declare(strict_types=1);

namespace App\Processo\Exception;

/**
 * Lançada quando uma nota técnica não satisfaz as regras de exclusão (apenas o autor, dentro da
 * janela da `JanelaDeEdicaoDeComentario`). Mesma natureza da exceção de edição.
 */
final class NotaTecnicaNaoExcluivelException extends \DomainException
{
}
