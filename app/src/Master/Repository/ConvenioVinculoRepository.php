<?php

declare(strict_types=1);

namespace App\Master\Repository;

use App\Master\DTO\EscritorioParaConvenio;
use App\Master\Entity\ConvenioVinculo;
use App\Master\Service\LocalizacaoDaPessoa;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Os vínculos dos escritórios com os convênios (`convenio_vinculo`, spec docs/specs/convenios.md, §3.2).
 *
 * {@see ConvenioVinculo} NÃO é TenantAware (D12): toda consulta daqui filtra por tenant explícito, escrito à mão, e
 * este é o único lugar que a consulta (`ConvenioVinculoSoNoRepositorioArquiteturaTest`). Em lote, para o painel do
 * Master ler todas as contas numa consulta só, como `BonusDeEspacoRepository::vigentesDosTenants`.
 *
 * @extends ServiceEntityRepository<ConvenioVinculo>
 */
class ConvenioVinculoRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ConvenioVinculo::class);
    }

    /**
     * Os vínculos abertos (`fim` nulo) dos escritórios, com o convênio já carregado.
     *
     * @param list<int> $tenantIds
     *
     * @return array<int, list<ConvenioVinculo>> tenantId => vínculos abertos
     */
    public function abertosDosTenants(array $tenantIds): array
    {
        if ($tenantIds === []) {
            return [];
        }
        /** @var list<ConvenioVinculo> $lista */
        $lista = $this->createQueryBuilder('v')
            ->addSelect('c')
            ->join('v.convenio', 'c')
            ->andWhere('IDENTITY(v.tenant) IN (:tenants)')
            ->andWhere('v.fim IS NULL')
            ->setParameter('tenants', $tenantIds)
            ->orderBy('v.id', 'ASC')
            ->getQuery()
            ->getResult();

        $porTenant = [];
        foreach ($lista as $vinculo) {
            $porTenant[(int) $vinculo->getTenant()->getId()][] = $vinculo;
        }

        return $porTenant;
    }

    /**
     * Os escritórios, entre os pedidos, que já têm algum vínculo gravado, aberto ou fechado. Quem não tem nenhum ainda
     * não passou pela sincronização (PR 3): para ele o convênio OAB vale implícito (decisão de 24/11, §3.4).
     *
     * @param list<int> $tenantIds
     *
     * @return list<int>
     */
    public function tenantsComVinculo(array $tenantIds): array
    {
        if ($tenantIds === []) {
            return [];
        }
        $ids = $this->createQueryBuilder('v')
            ->select('DISTINCT IDENTITY(v.tenant) AS tenant')
            ->andWhere('IDENTITY(v.tenant) IN (:tenants)')
            ->setParameter('tenants', $tenantIds)
            ->getQuery()
            ->getSingleColumnResult();

        return array_map('intval', $ids);
    }

    /**
     * Os vínculos abertos dos convênios pedidos (aviso prévio e fim pelo Master, §6.2), com o escritório.
     *
     * @param list<int> $convenioIds
     *
     * @return list<ConvenioVinculo>
     */
    public function abertosDosConvenios(array $convenioIds): array
    {
        if ($convenioIds === []) {
            return [];
        }

        /** @var list<ConvenioVinculo> $lista */
        $lista = $this->createQueryBuilder('v')
            ->addSelect('t')
            ->join('v.tenant', 't')
            ->andWhere('IDENTITY(v.convenio) IN (:convenios)')
            ->andWhere('v.fim IS NULL')
            ->setParameter('convenios', $convenioIds)
            ->orderBy('v.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $lista;
    }

    /**
     * Os escritórios com vínculo aberto, por convênio (a lista de convênios do Master, §7.2).
     *
     * @return array<int, list<int>> convenioId => tenantIds
     */
    public function tenantsAbertosPorConvenio(): array
    {
        $linhas = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT convenio_id, tenant_id FROM convenio_vinculo WHERE fim IS NULL ORDER BY convenio_id, tenant_id',
        );
        $porConvenio = [];
        foreach ($linhas as $linha) {
            $porConvenio[(int) $linha['convenio_id']][] = (int) $linha['tenant_id'];
        }

        return $porConvenio;
    }

    /** Quantas vezes o código do convênio já foi usado (vínculos de origem `codigo`, abertos e fechados). */
    public function usosDoCodigo(int $convenioId): int
    {
        return (int) $this->getEntityManager()->getConnection()->fetchOne(
            "SELECT COUNT(*) FROM convenio_vinculo WHERE convenio_id = ? AND origem = 'codigo'",
            [$convenioId],
        );
    }

    public function salvar(ConvenioVinculo $vinculo, bool $flush = false): void
    {
        $this->getEntityManager()->persist($vinculo);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * Os escritórios com o que a sincronização dos vínculos precisa (spec docs/specs/convenios.md, §5.1), numa consulta.
     * A OAB válida é a do criador do escritório: uma inscrição em `inscricao_oab` que não está divergente ou, para quem
     * ainda não tem linha lá (fluxos antigos gravam só no usuário), o espelho `user.oab_*`.
     *
     * @param list<int>|null $tenantIds null: todos
     *
     * @return list<EscritorioParaConvenio>
     */
    public function escritoriosParaConvenio(?array $tenantIds = null): array
    {
        if ($tenantIds === []) {
            return [];
        }
        $sql = <<<'SQL'
            SELECT t.id, t.estado, t.excluido_em IS NOT NULL AS em_quarentena,
                   (EXISTS (SELECT 1 FROM inscricao_oab i WHERE i.user_id = t.criado_por_id AND i.status <> 'divergente')
                    OR (NOT EXISTS (SELECT 1 FROM inscricao_oab i WHERE i.user_id = t.criado_por_id)
                        AND COALESCE(TRIM(u.oab_numero), '') <> ''
                        AND COALESCE(u.oab_status, 'nao_verificada') <> 'divergente')) AS oab_valida
              FROM tenant t
              LEFT JOIN "user" u ON u.id = t.criado_por_id
            SQL;
        $parametros = [];
        $tipos = [];
        if ($tenantIds !== null) {
            $sql .= ' WHERE t.id IN (:tenants)';
            $parametros['tenants'] = $tenantIds;
            $tipos['tenants'] = ArrayParameterType::INTEGER;
        }
        $linhas = $this->getEntityManager()->getConnection()->fetchAllAssociative($sql . ' ORDER BY t.id', $parametros, $tipos);

        return array_map(static fn (array $l): EscritorioParaConvenio => new EscritorioParaConvenio(
            (int) $l['id'],
            LocalizacaoDaPessoa::uf($l['estado']),
            (bool) $l['em_quarentena'],
            (bool) $l['oab_valida'],
        ), $linhas);
    }
}
