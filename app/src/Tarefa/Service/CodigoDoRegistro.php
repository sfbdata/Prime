<?php

declare(strict_types=1);

namespace App\Tarefa\Service;

use App\Entity\Tarefa\TarefaMensagem;
use App\Repository\TarefaMensagemRepository;
use App\Tarefa\Exception\DespachoRecusadoException;

/**
 * Grava o código do registro (D1, C13) num ato já gravado: sorteia e grava; colidiu no índice único, sorteia de
 * novo, até 3 vezes. Sem código livre, lança, e a transação de quem chama volta inteira. A mesma regra do
 * `DespacharNaMetaUseCase`, para a passagem de etapa e a devolução.
 */
final class CodigoDoRegistro
{
    private const TENTATIVAS = 3;

    public function __construct(
        private readonly TarefaMensagemRepository $mensagens,
        private readonly SorteioDeCodigoDoDespacho $sorteio,
    ) {
    }

    public function gravar(TarefaMensagem $ato): void
    {
        for ($tentativa = 0; $tentativa < self::TENTATIVAS; ++$tentativa) {
            if ($this->mensagens->gravarCodigoSeLivre($ato, $this->sorteio->sortear())) {
                return;
            }
        }

        throw DespachoRecusadoException::semCodigoLivre();
    }
}
