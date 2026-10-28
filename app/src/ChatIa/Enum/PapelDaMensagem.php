<?php

declare(strict_types=1);

namespace App\ChatIa\Enum;

/** Quem falou na conversa do Chat I.A. Os valores são contrato com a tela (`papel` do JSON). */
enum PapelDaMensagem: string
{
    case Usuario = 'usuario';
    case Assistente = 'assistente';
}
