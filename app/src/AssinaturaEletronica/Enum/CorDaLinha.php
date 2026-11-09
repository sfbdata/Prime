<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Enum;

/** A cor da linha do documento na lista, por documento, como o protótipo. Vazio = sem cor. */
enum CorDaLinha: string
{
    case Nenhuma = '';
    case Azul = 'azul';
    case Verde = 'verde';
    case Amarelo = 'amarelo';
    case Laranja = 'laranja';
    case Vermelho = 'vermelho';
    case Roxo = 'roxo';
    case Cinza = 'cinza';
}
