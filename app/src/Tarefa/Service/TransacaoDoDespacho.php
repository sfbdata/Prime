<?php

declare(strict_types=1);

namespace App\Tarefa\Service;

use App\Shared\Armazenamento\ChaveDeArquivo;

/**
 * A transação do despacho da meta, que pode gravar arquivo novo (o anexo juntado à pasta).
 *
 * Contrato da `TransacaoComArquivoNovo` (skill `armazenamento-de-arquivos`): os passos rodam dentro, o `flush` e o
 * COMMIT vêm depois, a exceção original sobe, e o arquivo novo só sai do storage quando o COMMIT comprovadamente não
 * aconteceu. Interface própria para o UseCase ser testado sem o EntityManager.
 */
interface TransacaoDoDespacho
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
