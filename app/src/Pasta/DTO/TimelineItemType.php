<?php

namespace App\Pasta\DTO;

enum TimelineItemType: string
{
    case MENSAGEM = 'mensagem';
    case EVENTO = 'evento';
}
