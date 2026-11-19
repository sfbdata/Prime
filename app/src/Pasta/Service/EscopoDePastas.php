<?php

declare(strict_types=1);

namespace App\Pasta\Service;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaCompartilhamento;
use App\Pasta\Entity\PastaProcesso;
use App\Pasta\Repository\PastaCompartilhamentoRepository;
use App\Processo\Entity\Processo;
use App\Repository\UserTenantRepository;
use App\Service\Tenant\TenantContext;
use App\Tenant\Service\RegraDosPrimarios;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Events;
use Doctrine\ORM\QueryBuilder;
use Psr\Clock\ClockInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Ponto único do "ACESSO RESTRITO ÀS PASTAS SOB RESPONSABILIDADE" (spec `docs/specs/acesso-restrito-e-compartilhar.md`,
 * §4.2, §4.4 e §5.1). Quem tem o perfil restrito só alcança as pastas em que é o responsável e as compartilhadas com
 * ele pela equipe (compartilhamento interno ativo). A pasta sem responsável fica fora. Master nunca é restrito; o
 * perfil de sistema também não (a entidade recusa).
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
#[AsEntityListener(event: Events::postPersist, method: 'reset', entity: PastaCompartilhamento::class)]
#[AsEntityListener(event: Events::postUpdate, method: 'reset', entity: PastaCompartilhamento::class)]
final class EscopoDePastas implements ResetInterface
{
    /** @var array<string, list<int>> pastas sob a responsabilidade da pessoa, por "user:tenant" */
    private array $sobResponsabilidade = [];

    /** @var array<string, list<int>> as demais pastas do escritório, por "user:tenant" */
    private array $foraDoEscopo = [];

    /** @var array<string, list<int>> clientes das pastas da pessoa (principal ou vinculado), por "user:tenant" */
    private array $clientesNoEscopo = [];

    /** @var array<string, list<int>> processos do escritório sem nenhuma pasta da pessoa, por "user:tenant" */
    private array $processosForaDoEscopo = [];

    /** Sufixo dos parâmetros: dois critérios no mesmo QueryBuilder não colidem. */
    private int $criterios = 0;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserTenantRepository $userTenantRepository,
        private readonly RegraDosPrimarios $regraDosPrimarios,
        private readonly TenantContext $tenantContext,
        private readonly PastaCompartilhamentoRepository $compartilhamentos,
        private readonly ClockInterface $relogio,
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

        $sufixo = (string) ++$this->criterios;
        $qb->andWhere($this->criterioDoEscopo($aliasPasta, 'escopo_pessoa_' . $sufixo, 'escopo_hoje_' . $sufixo, $sufixo))
            ->setParameter('escopo_pessoa_' . $sufixo, $pessoa)
            ->setParameter('escopo_hoje_' . $sufixo, $this->hoje(), 'date_immutable');
    }

    /**
     * Os clientes que a pessoa restrita alcança: os das pastas sob a responsabilidade dela (principal ou vinculado).
     * `null` para quem não é restrito.
     *
     * @return list<int>|null
     */
    public function idsDosClientesNoEscopo(User $pessoa, Tenant $tenant): ?array
    {
        $pastas = $this->idsNoEscopo($pessoa, $tenant);
        if ($pastas === null) {
            return null;
        }

        return $this->clientesNoEscopo[$this->chave($pessoa, $tenant)] ??= $this->clientesDasPastas($pastas);
    }

    /** O cliente está fora do alcance da pessoa restrita. Para quem não é restrito, nunca. */
    public function clienteForaDoEscopo(User $pessoa, Tenant $tenant, int $clienteId): bool
    {
        $ids = $this->idsDosClientesNoEscopo($pessoa, $tenant);

        return $ids !== null && !in_array($clienteId, $ids, true);
    }

    /**
     * Os processos do escritório que a pessoa restrita NÃO alcança: os que não têm nenhuma pasta sob a
     * responsabilidade dela, inclusive o processo sem pasta. Vazio para quem não é restrito.
     *
     * @return list<int>
     */
    public function idsDosProcessosForaDoEscopo(User $pessoa, Tenant $tenant): array
    {
        if (!$this->ehRestritoNoEscritorio($pessoa, $tenant)) {
            return [];
        }

        return $this->processosForaDoEscopo[$this->chave($pessoa, $tenant)] ??= $this->processosSemPastaDaPessoa($pessoa, $tenant);
    }

    public function processoForaDoEscopo(User $pessoa, Tenant $tenant, int $processoId): bool
    {
        return in_array($processoId, $this->idsDosProcessosForaDoEscopo($pessoa, $tenant), true);
    }

    /**
     * A pessoa restrita alcança a pasta só pelo compartilhamento (não é a responsável): as ações dentro dela também
     * dependem das caixas do compartilhamento (§4.4, "permissões do perfil e as do compartilhamento"). `null` para
     * quem não é restrito, para o responsável e para quem não tem compartilhamento ativo.
     */
    public function compartilhamentoQueDaAcesso(User $pessoa, Pasta $pasta): ?PastaCompartilhamento
    {
        $tenant = $pasta->getTenant();
        if ($tenant === null || $pasta->getId() === null || !$this->ehRestritoNoEscritorio($pessoa, $tenant)) {
            return null;
        }

        return $this->compartilhamentos->ativoParaQuemNaoEhResponsavel((int) $pasta->getId(), $pessoa, $this->hoje());
    }

    /** A pessoa restrita vê a pasta pelo compartilhamento sem "Abrir e movimentar": o teto dela é `Visualizar`. */
    public function compartilhamentoSemMovimentar(User $pessoa, UserTenant $vinculoAtivo, int $pastaId): bool
    {
        if (!$this->ehRestrito($pessoa, $vinculoAtivo)) {
            return false;
        }
        $compartilhamento = $this->compartilhamentos->ativoParaQuemNaoEhResponsavel($pastaId, $pessoa, $this->hoje());

        return $compartilhamento !== null && !$compartilhamento->permissoes()->movimentar;
    }

    public function reset(): void
    {
        $this->sobResponsabilidade = [];
        $this->foraDoEscopo = [];
        $this->clientesNoEscopo = [];
        $this->processosForaDoEscopo = [];
    }

    /**
     * @param list<int> $pastas
     *
     * @return list<int>
     */
    private function clientesDasPastas(array $pastas): array
    {
        if ($pastas === []) {
            return [];
        }
        $principais = $this->em->createQueryBuilder()
            ->select('DISTINCT IDENTITY(p.clientePrincipal) AS id')->from(Pasta::class, 'p')
            ->andWhere('p.id IN (:pastas)')->andWhere('p.clientePrincipal IS NOT NULL')
            ->setParameter('pastas', $pastas)->getQuery()->getScalarResult();
        $vinculados = $this->em->createQueryBuilder()
            ->select('DISTINCT c.id AS id')->from(Pasta::class, 'p')->join('p.clientes', 'c')
            ->andWhere('p.id IN (:pastas)')
            ->setParameter('pastas', $pastas)->getQuery()->getScalarResult();

        return array_values(array_unique(array_map('intval', array_column([...$principais, ...$vinculados], 'id'))));
    }

    /** @return list<int> */
    private function processosSemPastaDaPessoa(User $pessoa, Tenant $tenant): array
    {
        $daPessoa = $this->em->createQueryBuilder()
            ->select('IDENTITY(pp.processo)')->from(PastaProcesso::class, 'pp')->join('pp.pasta', 'pe')
            ->andWhere($this->criterioDoEscopo('pe', 'pessoa', 'hoje', 'proc'))->andWhere('pe.tenant = :tenant');
        $linhas = $this->em->createQueryBuilder()
            ->select('pr.id')->from(Processo::class, 'pr')
            ->andWhere('pr.tenant = :tenant')
            ->andWhere('pr.id NOT IN (' . $daPessoa->getDQL() . ')')
            ->setParameter('tenant', $tenant)->setParameter('pessoa', $pessoa)
            ->setParameter('hoje', $this->hoje(), 'date_immutable')
            ->getQuery()->getScalarResult();

        return array_map('intval', array_column($linhas, 'id'));
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
            ->setParameter('pessoa', $pessoa)
            ->setParameter('hoje', $this->hoje(), 'date_immutable');
        $qb->andWhere($daPessoa ? $this->criterioDoEscopo('p', 'pessoa', 'hoje', 'ids') : $this->criterioForaDoEscopo('p', 'pessoa', 'hoje', 'ids'));

        return array_map('intval', array_column($qb->getQuery()->getScalarResult(), 'id'));
    }

    /**
     * O critério DQL do escopo: a pasta é da pessoa (responsável) ou está compartilhada com ela e ativa. Os nomes dos
     * parâmetros vêm de quem chama, que os preenche.
     */
    private function criterioDoEscopo(string $aliasPasta, string $pessoa, string $hoje, string $sufixo): string
    {
        $daPessoa = sprintf('%s.responsavel = :%s', $aliasPasta, $pessoa);

        return sprintf('(%s OR %s.id IN (%s))', $daPessoa, $aliasPasta, PastaCompartilhamentoRepository::dqlDasPastasCompartilhadasCom($sufixo, $pessoa, $hoje));
    }

    /** O contrário do `criterioDoEscopo()`, sem perder a pasta sem responsável (NULL não entra num NOT). */
    private function criterioForaDoEscopo(string $aliasPasta, string $pessoa, string $hoje, string $sufixo): string
    {
        $deOutro = sprintf('(%1$s.responsavel IS NULL OR %1$s.responsavel <> :%2$s)', $aliasPasta, $pessoa);

        return sprintf('(%s AND %s.id NOT IN (%s))', $deOutro, $aliasPasta, PastaCompartilhamentoRepository::dqlDasPastasCompartilhadasCom($sufixo, $pessoa, $hoje));
    }

    private function hoje(): \DateTimeImmutable
    {
        return $this->relogio->now()->setTime(0, 0);
    }

    private function chave(User $pessoa, Tenant $tenant): string
    {
        return $pessoa->getId() . ':' . $tenant->getId();
    }
}
