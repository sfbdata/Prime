<?php

declare(strict_types=1);

namespace App\Inteligencia\Service;

/**
 * De onde o adaptador tira o modelo a cada chamada (spec ia-anthropic-ligar §10.1: o modelo sai do `.env` e vai para
 * o banco, escolhido pelo Master). Pode lançar: quem chama ({@see ProvedorAnthropic}) cai no modelo do ambiente.
 */
interface FonteDoModeloDeIa
{
    public function modelo(): string;
}
