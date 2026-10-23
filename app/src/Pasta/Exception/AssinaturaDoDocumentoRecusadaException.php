<?php

declare(strict_types=1);

namespace App\Pasta\Exception;

/**
 * Marcar ou desmarcar o selo "Assinado" não cabe no estado atual do documento: já assinado, não
 * assinado, ou assinatura digital (que não se desmarca pela tela). O controller responde 422.
 */
final class AssinaturaDoDocumentoRecusadaException extends \DomainException
{
}
