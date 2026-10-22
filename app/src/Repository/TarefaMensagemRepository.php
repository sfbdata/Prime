<?php

namespace App\Repository;

use App\Entity\Tarefa\TarefaMensagem;
use App\Entity\Tenant\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TarefaMensagem>
 */
class TarefaMensagemRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TarefaMensagem::class);
    }

    public function salvar(TarefaMensagem $mensagem, bool $flush = false): void
    {
        $this->getEntityManager()->persist($mensagem);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /** Grava o que já está gerenciado (ciência, certidão, troca de status da meta junto do despacho). */
    public function confirmar(): void
    {
        $this->getEntityManager()->flush();
    }

    /** O código do registro (D1) é único no escritório: o sorteio confere antes de gravar. */
    public function codigoJaUsado(Tenant $tenant, string $codigo): bool
    {
        return $this->count(['tenant' => $tenant, 'codigo' => $codigo]) > 0;
    }

    /** Validação do registro (C13): só acha no escritório da sessão; outro escritório é "não encontrado". */
    public function doEscritorioPorCodigo(Tenant $tenant, string $codigo): ?TarefaMensagem
    {
        return $this->findOneBy(['tenant' => $tenant, 'codigo' => $codigo]);
    }
}
