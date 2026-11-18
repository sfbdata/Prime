<?php

declare(strict_types=1);

namespace App\Pasta\Service;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Repository\UserTenantRepository;
use App\Service\Tenant\TenantContext;
use App\Tenant\Service\RegraDosPrimarios;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Events;
use Doctrine\ORM\QueryBuilder;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Ponto único do "ACESSO RESTRITO ÀS PASTAS SOB RESPONSABILIDADE" (spec `docs/specs/acesso-restrito-e-compartilhar.md`,
 * §4.2 e §5.1). Quem tem o perfil restrito só alcança as pastas em que é o responsável. A pasta sem responsável fica
 * fora. Master nunca é restrito; o perfil de sistema também não (a entidade recusa).
 *
 * Os dois usos:
 *  - por id: `foraDoEscopo()`, que o `NivelNoCadeado` transforma em `Oculto` (antes do cadeado), e daí o
 *    `canAccessResource`, a barreira das rotas de pasta, a meta e o processo (K3) recusam;
 *  - em lote: `restringir()` no QueryBuilder (a pasta fora do escopo não aparece nem por número e cliente) e
 *    `idsForaDoEscopo()` para as consultas que trabalham com a lista de ocultas (metas, agenda, processos).
 *
 * Memória por requisição: uma consulta de ids por pessoa e escritório. Pasta gravada no mesmo processo (criada, com
 * responsável trocado) esquece a memória.
 */
#[AsEntityListener(event: Events::postPersist, method: 'reset', entity: Pasta::class)]
#[AsEntityListener(event: Events::postUpdate, method: 'reset', entity: Pasta::class)]
final class EscopoDePastas implements ResetInterface
{
    /** @var array<string, list<int>> pastas sob a responsabilidade da pessoa, por "user:tenant" */
    private array $sobResponsabilidade = [];

    /** @var array<string, list<int>> as demais pastas do escritório, por "user:tenant" */
    private array $foraDoEscopo = [];

    /** Sufixo dos parâmetros: dois critérios no mesmo QueryBuilder não colidem. */
    private int $criterios = 0;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserTenantRepository $userTenantRepository,
        private readonly RegraDosPrimarios $regraDosPrimarios,
        private readonly TenantContext $tenantContext,
    ) {
    }

    /** O vínculo ativo tem o perfil restrito (Master nunca). */
    public function ehRestrito(User $pessoa, UserTenant $vinculoAtivo): bool
    {
        return $vinculoAtivo->getTenantRole()?->temAcessoRestrito() === true && !$this->regraDosPrimarios->ehMaster($pessoa);
    }

    /** O mesmo `ehRestrito()`, para quem só tem a pessoa e o escritório. */
    public function ehRestritoNoEscritorio(User $pessoa, Tenant $tenant): bool
    {
        $vinculo = $this->userTenantRepository->findAtivoPorUserETenant($pessoa, $tenant);

        return $vinculo !== null && $this->ehRestrito($pessoa, $vinculo);
    }

    /** A pasta está fora do alcance da pessoa restrita. Para quem não é restrito, nunca. */
    public function foraDoEscopo(User $pessoa, UserTenant $vinculoAtivo, int $pastaId): bool
    {
        return $this->ehRestrito($pessoa, $vinculoAtivo)
            && !in_array($pastaId, $this->idsSobResponsabilidade($pessoa, $vinculoAtivo->getTenant()), true);
    }

    /** O mesmo `foraDoEscopo()` para uma pasta em mãos. */
    public function pastaForaDoEscopo(User $pessoa, Pasta $pasta): bool
    {
        $tenant = $pasta->getTenant();
        if ($tenant === null || $pasta->getId() === null) {
            return false;
        }
        $vinculo = $this->userTenantRepository->findAtivoPorUserETenant($pessoa, $tenant);

        return $vinculo !== null && $this->foraDoEscopo($pessoa, $vinculo, (int) $pasta->getId());
    }

    /**
     * As pastas que a pessoa restrita alcança; `null` para quem não é restrito (sem restrição de escopo).
     *
     * @return list<int>|null
     */
    public function idsNoEscopo(User $pessoa, Tenant $tenant): ?array
    {
        return $this->ehRestritoNoEscritorio($pessoa, $tenant) ? $this->idsSobResponsabilidade($pessoa, $tenant) : null;
    }

    /**
     * As pastas do escritório que a pessoa restrita NÃO alcança (vazio para quem não é restrito). Para as consultas
     * que já trabalham com a lista de pastas ocultas do cadeado.
     *
     * @return list<int>
     */
    public function idsForaDoEscopo(User $pessoa, Tenant $tenant): array
    {
        if (!$this->ehRestritoNoEscritorio($pessoa, $tenant)) {
            return [];
        }

        return $this->foraDoEscopo[$this->chave($pessoa, $tenant)] ??= $this->idsDoEscritorio($pessoa, $tenant, false);
    }

    /**
     * O QueryBuilder passa a devolver só as pastas do escopo de quem busca, no escritório da sessão. Para quem não
     * é restrito, nada é acrescentado.
     */
    public function restringir(QueryBuilder $qb, string $aliasPasta, User $pessoa): void
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        if ($tenant === null || !$this->ehRestritoNoEscritorio($pessoa, $tenant)) {
            return;
        }

        $parametro = 'escopo_pessoa_' . ++$this->criterios;
        $qb->andWhere(sprintf('%s.responsavel = :%s', $aliasPasta, $parametro))->setParameter($parametro, $pessoa);
    }

    public function reset(): void
    {
        $this->sobResponsabilidade = [];
        $this->foraDoEscopo = [];
    }

    /** @return list<int> */
    private function idsSobResponsabilidade(User $pessoa, Tenant $tenant): array
    {
        return $this->sobResponsabilidade[$this->chave($pessoa, $tenant)] ??= $this->idsDoEscritorio($pessoa, $tenant, true);
    }

    /** @return list<int> as pastas do escritório em que a pessoa é (`true`) ou não é (`false`) a responsável */
    private function idsDoEscritorio(User $pessoa, Tenant $tenant, bool $daPessoa): array
    {
        $qb = $this->em->createQueryBuilder()
            ->select('p.id')
            ->from(Pasta::class, 'p')
            ->andWhere('p.tenant = :tenant')
            ->setParameter('tenant', $tenant)
            ->setParameter('pessoa', $pessoa);
        $qb->andWhere($daPessoa ? 'p.responsavel = :pessoa' : '(p.responsavel IS NULL OR p.responsavel <> :pessoa)');

        return array_map('intval', array_column($qb->getQuery()->getScalarResult(), 'id'));
    }

    private function chave(User $pessoa, Tenant $tenant): string
    {
        return $pessoa->getId() . ':' . $tenant->getId();
    }
}
