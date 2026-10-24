<?php

declare(strict_types=1);

namespace App\Shared\EspelhoRemoto\Reconciliacao;

/** De onde a reconciliação tira os arquivos que o banco diz que existem. */
interface FonteDeReferencias
{
    /** @return iterable<ReferenciaDeArquivo> */
    public function referencias(): iterable;
}
