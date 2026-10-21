<?php

declare(strict_types=1);

namespace App\Pasta\DTO;

use App\Pasta\Entity\Pasta;

/**
 * O que o `PastaDaRota` achou numa requisição: a pasta e, quando a rota é de uma filha (documento, seção,
 * mensagem…), a ENTIDADE filha também. A barreira da aba oculta precisa da filha (o contrato de
 * honorários é um `PastaDocumento`); o cadeado e a lápide só da pasta.
 */
final class PastaDaRequisicao
{
    public function __construct(
        public readonly Pasta $pasta,
        public readonly ?object $filha = null,
    ) {
    }
}
