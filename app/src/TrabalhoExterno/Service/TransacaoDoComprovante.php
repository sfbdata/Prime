<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\Service;

use App\Shared\Armazenamento\ChaveDeArquivo;

/**
 * A transação do comprovante de pagamento, que grava arquivo novo. Mesmo contrato da {@see TransacaoDoDespacho}
 * (skill `armazenamento-de-arquivos`): os passos rodam dentro, o `flush` e o COMMIT vêm depois, a exceção original
 * sobe, e o arquivo novo só sai do storage quando o COMMIT comprovadamente não aconteceu. Interface própria para o
 * caso de uso ser testado sem o EntityManager.
 */
interface TransacaoDoComprovante
{
    /**
     * @template T
     *
     * @param \Closure(): T                    $passos
     * @param \Closure(): list<ChaveDeArquivo> $arquivosNovos consultado só na falha
     *
     * @return T
     */
    public function executar(\Closure $passos, \Closure $arquivosNovos): mixed;
}
