<?php

declare(strict_types=1);

namespace App\Tests\Conector\Support;

use App\Conector\Repository\EscritorioDoConectorRepository;
use App\Conector\Service\LiberacaoDoConector;
use App\Master\Service\RecursosDoSistema;
use App\Produto\Repository\ContratacaoRepository;
use App\Produto\Service\ChaveDoMultiproduto;
use App\Produto\Service\ProdutosDoEscritorio;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\NativeClock;

/**
 * Monta a LiberacaoDoConector com a chave do multiproduto DESLIGADA (o padrão de produção): os testes que só olham a
 * liberação do Conector continuam iguais. Quem testa a exigência do Expediente (D11) monta à mão com outro modo
 * (LiberacaoDoConectorTest).
 */
final class LiberacaoDoConectorDeTeste
{
    public static function montar(RecursosDoSistema $recursos, EscritorioDoConectorRepository $escritorios): LiberacaoDoConector
    {
        return new LiberacaoDoConector(
            $recursos,
            $escritorios,
            new ChaveDoMultiproduto('desligado'),
            // Com a chave desligada a liberação nunca pergunta pelos produtos: o repositório não é tocado (e os testes
            // de unidade não têm banco para montá-lo).
            new ProdutosDoEscritorio((new \ReflectionClass(ContratacaoRepository::class))->newInstanceWithoutConstructor(), new NativeClock()),
            new NullLogger(),
        );
    }
}
