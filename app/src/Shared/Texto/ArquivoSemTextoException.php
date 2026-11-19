<?php

declare(strict_types=1);

namespace App\Shared\Texto;

/**
 * O arquivo não deu texto para ler: forjado, de outro tipo, grande demais depois de aberto ou vazio. A mensagem é fixa,
 * em português, e nunca leva o nome nem o conteúdo do arquivo; quem chama decide se ela chega à pessoa.
 */
final class ArquivoSemTextoException extends \DomainException
{
}
