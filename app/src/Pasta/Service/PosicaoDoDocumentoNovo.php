<?php

declare(strict_types=1);

namespace App\Pasta\Service;

use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaDocumento;
use App\Pasta\Repository\PastaDocumentoRepository;

/**
 * Põe o documento recém-criado no fim da ordem manual da pasta. Sem isso ele nasce com ordem 0 e, depois de uma
 * reordenação (todos com 1, 2, 3...), apareceria no topo ao recarregar, enquanto a tela o acrescentou no fim.
 */
final class PosicaoDoDocumentoNovo
{
    public function __construct(private readonly PastaDocumentoRepository $documentos)
    {
    }

    public function aoFim(PastaDocumento $documento, Pasta $pasta, Tenant $tenant): void
    {
        $documento->setOrdem($this->documentos->proximaOrdem($pasta, $tenant));
    }
}
