<?php

declare(strict_types=1);

namespace App\Conector\Repository;

use App\Conector\Entity\ClienteDoConector;
use App\Conector\Entity\ConsentimentoDoConector;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Consentimentos (as "conexões" de I.A.). Toda busca leva o escritório explícito, sem depender do `TenantFilter`: o
 * `/oauth/authorize` e o Meu Perfil podem rodar sem o filtro ligado.
 *
 * @extends ServiceEntityRepository<ConsentimentoDoConector>
 */
class ConsentimentoDoConectorRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ConsentimentoDoConector::class);
    }

    public function salvar(ConsentimentoDoConector $entidade, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entidade);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /** A conexão ativa da pessoa com este app neste escritório (há no máximo uma). */
    public function buscarAtivo(User $user, Tenant $tenant, ClienteDoConector $cliente): ?ConsentimentoDoConector
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.tenant = :tenant')
            ->andWhere('c.user = :user')
            ->andWhere('c.cliente = :cliente')
            ->andWhere('c.revogadoEm IS NULL')
            ->setParameter('tenant', $tenant)
            ->setParameter('user', $user)
            ->setParameter('cliente', $cliente)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * As conexões ativas da pessoa neste escritório, da mais recente para a mais antiga.
     *
     * @return list<ConsentimentoDoConector>
     */
    public function listarAtivosDaPessoa(User $user, Tenant $tenant): array
    {
        /** @var list<ConsentimentoDoConector> */
        return $this->createQueryBuilder('c')
            ->andWhere('c.tenant = :tenant')
            ->andWhere('c.user = :user')
            ->andWhere('c.revogadoEm IS NULL')
            ->setParameter('tenant', $tenant)
            ->setParameter('user', $user)
            ->orderBy('c.concedidoEm', 'DESC')
            ->addOrderBy('c.id', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * As conexões ativas da pessoa em TODOS os escritórios dela, para o Meu Perfil (PR 8, decisão 1 do plano): a mais
     * recente primeiro. O `TenantFilter` do escritório da sessão é suspenso só durante a consulta, senão a conexão de
     * outro escritório sumiria; o que prende a busca é a pessoa, escrita à mão. App e escritório vêm no mesmo SELECT
     * (fetch join), para a tela não carregar nada depois, já com o filtro de volta.
     *
     * @return list<ConsentimentoDoConector>
     */
    public function listarAtivosDaPessoaEmTodos(User $user): array
    {
        return $this->semFiltroDoEscritorio(function () use ($user): array {
            /** @var list<ConsentimentoDoConector> */
            return $this->createQueryBuilder('c')
                ->addSelect('cliente', 'tenant')
                ->innerJoin('c.cliente', 'cliente')
                ->innerJoin('c.tenant', 'tenant')
                ->andWhere('c.user = :user')
                ->andWhere('c.revogadoEm IS NULL')
                ->setParameter('user', $user)
                ->orderBy('c.concedidoEm', 'DESC')
                ->addOrderBy('c.id', 'DESC')
                ->getQuery()
                ->getResult();
        });
    }

    /**
     * A conexão ativa com este id, só se for da pessoa (guarda de posse antes de revogar): id de outra pessoa ou
     * revogado dá nulo. Escritório qualquer da pessoa, então o `TenantFilter` fica suspenso durante a consulta.
     */
    public function buscarAtivoDaPessoa(int $id, User $user): ?ConsentimentoDoConector
    {
        return $this->semFiltroDoEscritorio(function () use ($id, $user): ?ConsentimentoDoConector {
            /** @var ?ConsentimentoDoConector */
            return $this->createQueryBuilder('c')
                ->addSelect('cliente', 'tenant')
                ->innerJoin('c.cliente', 'cliente')
                ->innerJoin('c.tenant', 'tenant')
                ->andWhere('c.id = :id')
                ->andWhere('c.user = :user')
                ->andWhere('c.revogadoEm IS NULL')
                ->setParameter('id', $id)
                ->setParameter('user', $user)
                ->getQuery()
                ->getOneOrNullResult();
        });
    }

    /**
     * Quantas conexões ativas cada escritório tem, para a tabela do Master (decisão 6 do plano do PR 8: ele vê só a
     * contagem, nunca de quem). Escritório sem conexão ativa não aparece. Quem chama já exigiu o Master.
     *
     * @return array<int, int> id do escritório => quantidade
     */
    public function contarAtivosPorEscritorio(): array
    {
        /** @var list<array{tenantId: int|string, quantidade: int|string}> $linhas */
        $linhas = $this->semFiltroDoEscritorio(fn (): array => $this->createQueryBuilder('c')
            ->select('IDENTITY(c.tenant) AS tenantId', 'COUNT(c.id) AS quantidade')
            ->andWhere('c.revogadoEm IS NULL')
            ->groupBy('c.tenant')
            ->getQuery()
            ->getArrayResult());

        $contagem = [];
        foreach ($linhas as $linha) {
            $contagem[(int) $linha['tenantId']] = (int) $linha['quantidade'];
        }

        return $contagem;
    }

    /**
     * Roda a consulta com o `TenantFilter` suspenso e o devolve como estava, mesmo se a consulta falhar (molde:
     * {@see \App\Inteligencia\Repository\ConfiguracaoDeInteligenciaRepository::findDoTenantParaOMaster}).
     *
     * @template T
     *
     * @param callable(): T $consulta
     *
     * @return T
     */
    private function semFiltroDoEscritorio(callable $consulta): mixed
    {
        $filtros = $this->getEntityManager()->getFilters();
        $estavaLigado = $filtros->isEnabled('tenant');
        if ($estavaLigado) {
            $filtros->suspend('tenant');
        }
        try {
            return $consulta();
        } finally {
            if ($estavaLigado) {
                $filtros->restore('tenant');
            }
        }
    }

    /**
     * Grava o último uso da conexão (13.2), no máximo uma vez por minuto
     * ({@see ConsentimentoDoConector::INTERVALO_DO_ULTIMO_USO}): um UPDATE em DQL, preso ao escritório e só na conexão
     * ativa. Não passa pela unidade de trabalho de propósito: não dá flush (nada pendente de outra parte vai junto) e
     * não entra no `audit_log` (o último uso não é alteração de ninguém). A entidade em memória não muda. Devolve se
     * gravou.
     */
    public function registrarUso(int $consentimentoId, Tenant $escritorio, \DateTimeImmutable $agora): bool
    {
        $limite = $agora->sub(new \DateInterval('PT' . ConsentimentoDoConector::INTERVALO_DO_ULTIMO_USO . 'S'));

        return (int) $this->getEntityManager()->createQuery(
            'UPDATE ' . ConsentimentoDoConector::class . ' c SET c.ultimoUsoEm = :agora'
            . ' WHERE c.id = :id AND c.tenant = :tenant AND c.revogadoEm IS NULL'
            . ' AND (c.ultimoUsoEm IS NULL OR c.ultimoUsoEm <= :limite)'
        )
            ->setParameter('agora', $agora, 'datetime_immutable')
            ->setParameter('id', $consentimentoId)
            ->setParameter('tenant', $escritorio)
            ->setParameter('limite', $limite, 'datetime_immutable')
            ->execute() > 0;
    }
}
