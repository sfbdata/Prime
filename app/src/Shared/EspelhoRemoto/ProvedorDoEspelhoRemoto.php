<?php

declare(strict_types=1);

namespace App\Shared\EspelhoRemoto;

/**
 * Quem entrega o espelho aos comandos (o worker, a reconciliação, a situação). Em produção é a
 * {@see FabricaDoEspelhoRemoto}; a interface existe para os testes dos comandos usarem um espelho em
 * memória sem rede.
 */
interface ProvedorDoEspelhoRemoto
{
    /** Os quatro valores `ESPELHO_R2_*` estão preenchidos? Não revela valor nenhum. */
    public function estaConfigurado(): bool;

    /** @throws EspelhoRemotoNaoConfigurado */
    public function criar(): EspelhoRemoto;
}
