<?php

declare(strict_types=1);

namespace App\Pasta\EventListener;

use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaMensagem;
use App\Pasta\Entity\PastaObservacaoDetalhes;
use App\Pasta\Entity\PastaObservacaoFinanceira;
use App\Pasta\Service\PrazosDosRegistros;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Events;

/**
 * Registro criado, editado ou excluído (Registro dos expedientes, Detalhes, Financeiro) => os prazos lidos daquela
 * pasta são relidos (E12c). Um ponto só, em vez de lembrar em cada UseCase de escrita: anota as pastas no onFlush e,
 * depois que o flush do registro terminou, relê e dá um segundo flush (com trava contra reentrada).
 */
#[AsDoctrineListener(event: Events::onFlush)]
#[AsDoctrineListener(event: Events::postFlush)]
final class PrazosDosRegistrosListener
{
    private const REGISTROS = [PastaMensagem::class, PastaObservacaoDetalhes::class, PastaObservacaoFinanceira::class];

    /** @var array<int, true> */
    private array $pendentes = [];
    private bool $relendo = false;

    public function __construct(
        private readonly PrazosDosRegistros $prazos,
    ) {
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        if ($this->relendo) {
            return;
        }
        $uow = $args->getObjectManager()->getUnitOfWork();
        foreach ([$uow->getScheduledEntityInsertions(), $uow->getScheduledEntityUpdates(), $uow->getScheduledEntityDeletions()] as $lista) {
            foreach ($lista as $entidade) {
                if (in_array($entidade::class, self::REGISTROS, true) || in_array(get_parent_class($entidade), self::REGISTROS, true)) {
                    $pasta = $entidade->getPasta();
                    if ($pasta !== null && $pasta->getId() !== null) {
                        $this->pendentes[$pasta->getId()] = true;
                    }
                }
            }
        }
    }

    public function postFlush(PostFlushEventArgs $args): void
    {
        if ($this->relendo || $this->pendentes === []) {
            return;
        }
        $em = $args->getObjectManager();
        $ids = array_keys($this->pendentes);
        $this->pendentes = [];
        $this->relendo = true;
        try {
            foreach ($ids as $id) {
                $pasta = $em->find(Pasta::class, $id);
                if ($pasta !== null) {
                    $this->prazos->sincronizar($pasta);
                }
            }
            $em->flush();
        } finally {
            $this->relendo = false;
        }
    }
}
