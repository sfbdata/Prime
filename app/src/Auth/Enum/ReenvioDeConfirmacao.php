<?php

declare(strict_types=1);

namespace App\Auth\Enum;

/** Resultado do ReenviarConfirmacaoDeEmailUseCase: o controller transforma em flash. */
enum ReenvioDeConfirmacao
{
    /** Link novo gravado e e-mail enviado. */
    case Enviado;
    /** A conta já está confirmada: nada é gravado nem enviado. */
    case JaConfirmado;
    /** Link novo gravado, mas o e-mail não saiu (fica no log); quem pediu pode tentar de novo. */
    case FalhaNoEnvio;
}
