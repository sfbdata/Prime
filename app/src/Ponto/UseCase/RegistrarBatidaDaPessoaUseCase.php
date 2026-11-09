<?php

declare(strict_types=1);

namespace App\Ponto\UseCase;

use App\Ponto\Entity\ComprovanteDoPonto;
use App\Ponto\Entity\RegistroPonto;
use App\Ponto\Service\EmissaoDoComprovante;
use App\Ponto\Service\NumeroSequencialDoRegistro;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Grava a batida que a própria pessoa registra na /ponto (E15, D2): numa transação só, o NSR do escritório, a batida
 * e, com a função e o selo prontos, o comprovante. Qualquer falha desfaz os três juntos, então o NSR não fica com
 * buraco. As recusas (cerca, repouso, interjornada, chave, choque) ficam antes, no controller, como hoje.
 *
 * Não recebem NSR as batidas que não são marcação da pessoa: a lançada pelo gestor na ficha e a criada pelo abono de
 * esquecimento (viram ajuste na E10).
 */
final class RegistrarBatidaDaPessoaUseCase
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly NumeroSequencialDoRegistro $nsr,
        private readonly EmissaoDoComprovante $emissao,
    ) {
    }

    /** Devolve o comprovante emitido, ou null quando a função ou o selo não estão prontos. */
    public function executar(RegistroPonto $batida): ?ComprovanteDoPonto
    {
        $escritorio = $batida->getTenant() ?? throw new \LogicException('Batida sem escritório.');

        return $this->em->wrapInTransaction(function () use ($batida, $escritorio): ?ComprovanteDoPonto {
            $batida->setNsr($this->nsr->proximo($escritorio));
            $this->em->persist($batida);
            $comprovante = $this->emissao->emitir($batida);
            $this->em->flush();

            return $comprovante;
        });
    }
}
