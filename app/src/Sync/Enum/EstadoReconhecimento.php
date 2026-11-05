<?php

declare(strict_types=1);

namespace App\Sync\Enum;

/** Estado de uma dúvida do reconhecimento do Drive ({@see \App\Sync\Entity\ReconhecimentoPendente}). */
enum EstadoReconhecimento: string
{
    /** Esperando o administrador decidir. Enquanto isso, o envio não cria pasta nova para as candidatas. */
    case Pendente = 'pendente';

    /** O administrador ligou a pasta do Drive a uma pasta do sistema. */
    case Confirmado = 'confirmado';

    /** O administrador mandou ignorar: as candidatas seguem o envio normal. */
    case Ignorado = 'ignorado';
}
