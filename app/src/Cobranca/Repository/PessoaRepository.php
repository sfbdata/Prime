<?php

declare(strict_types=1);

namespace App\Cobranca\Repository;

use App\Cobranca\Entity\Pessoa;
use App\Entity\Tenant\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Pessoa>
 */
// Não-final: permite substituição por mock nos testes de UseCase.
class PessoaRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Pessoa::class);
    }

    public function salvar(Pessoa $entidade, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entidade);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remover(Pessoa $entidade, bool $flush = false): void
    {
        $this->getEntityManager()->remove($entidade);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * Busca tenant-safe por id — o filtro SQL do Doctrine NÃO se aplica a find() por PK
     * (risco cross-tenant). Sempre passar o tenant explícito.
     */
    public function findOneByIdDoTenant(int $id, Tenant $tenant): ?Pessoa
    {
        return $this->findOneBy(['id' => $id, 'tenant' => $tenant]);
    }

    /**
     * Sugere Pessoas do MESMO tenant cujo CPF ou CNPJ (comparados por dígitos, ignorando
     * formatação) coincidem com os informados. Advisory — nunca atravessa tenant (invariável 24).
     * Recebe cpf/cnpj já normalizados para dígitos (string vazia quando ausente).
     *
     * @return list<Pessoa>
     */
    public function buscarPossiveisDuplicadas(Tenant $tenant, string $cpfDigitos, string $cnpjDigitos): array
    {
        if ($cpfDigitos === '' && $cnpjDigitos === '') {
            return [];
        }

        $sql = <<<'SQL'
            SELECT id FROM cobranca_pessoa
            WHERE tenant_id = :tenant
              AND (
                (:cpf <> '' AND regexp_replace(coalesce(cpf, ''), '[^0-9]', '', 'g') = :cpf)
                OR (:cnpj <> '' AND regexp_replace(coalesce(cnpj, ''), '[^0-9]', '', 'g') = :cnpj)
              )
            SQL;

        $ids = $this->getEntityManager()->getConnection()->executeQuery($sql, [
            'tenant' => $tenant->getId(),
            'cpf' => $cpfDigitos,
            'cnpj' => $cnpjDigitos,
        ])->fetchFirstColumn();

        if ($ids === []) {
            return [];
        }

        // Ids já são tenant-scoped pela query acima; hidrata as entidades correspondentes.
        return $this->findBy(['id' => $ids]);
    }
}
