<?php

declare(strict_types=1);

namespace App\Produto\Repository;

use App\Entity\Tenant\Tenant;
use App\Produto\Entity\Contratacao;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Contratacao>
 */
// Não-final: os testes de UseCase dos próximos PRs o substituem por mock.
class ContratacaoRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Contratacao::class);
    }

    public function salvar(Contratacao $contratacao, bool $flush = false): void
    {
        $this->getEntityManager()->persist($contratacao);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * Todas as linhas de UM escritório, ativas e encerradas. O tenant vem escrito à mão, então o TenantFilter é
     * suspenso só durante a consulta: a resposta é a mesma com ou sem escritório na sessão (a guarda do produto roda
     * antes do filtro em algumas rotas, e o Master consulta escritório que não é o da sessão). Quem chama escolhe o
     * tenant; nunca vem do request.
     *
     * @return list<Contratacao>
     */
    public function todasDoTenant(Tenant $tenant): array
    {
        $filtros = $this->getEntityManager()->getFilters();
        $estavaLigado = $filtros->isEnabled('tenant');
        if ($estavaLigado) {
            $filtros->suspend('tenant');
        }
        try {
            return $this->findBy(['tenant' => $tenant], ['inicio' => 'ASC', 'id' => 'ASC']);
        } finally {
            if ($estavaLigado) {
                $filtros->restore('tenant');
            }
        }
    }
}
