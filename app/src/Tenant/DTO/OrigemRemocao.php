<?php
declare(strict_types=1);

namespace App\Tenant\DTO;

/** Distingue as duas portas da remoção: o painel do admin e a saída por conta própria. */
enum OrigemRemocao: string
{
    case Painel = 'painel';
    case Saida  = 'saida';
}
