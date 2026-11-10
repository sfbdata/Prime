<?php

declare(strict_types=1);

namespace App\Voto\Exception;

/**
 * Operação fora do estado permitido na assembleia de condomínio, na pauta ou na unidade (publicar o que já foi publicado,
 * abrir pauta de assembleia em rascunho, mexer nas unidades depois da abertura...). A mensagem pode ir para a tela.
 */
final class OperacaoInvalidaNaAssembleiaException extends \DomainException
{
}
