<?php

namespace App\Repository;

use App\Entity\Auth\User;
use App\Entity\Notificacao;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Notificacao>
 */
class NotificacaoRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Notificacao::class);
    }

    /**
     * Aplica o filtro de categoria (derivada do tipo) ao QueryBuilder.
     * null = todas as categorias.
     */
    private function aplicarCategoria(QueryBuilder $qb, ?string $categoria): void
    {
        if ($categoria === Notificacao::CATEGORIA_GESTAO) {
            $qb->andWhere('n.tipo IN (:tiposGestao)')
                ->setParameter('tiposGestao', Notificacao::TIPOS_GESTAO);
        } elseif ($categoria === Notificacao::CATEGORIA_PESSOAL) {
            $qb->andWhere('n.tipo NOT IN (:tiposGestao)')
                ->setParameter('tiposGestao', Notificacao::TIPOS_GESTAO);
        }
    }

    /**
     * Retorna notificações não lidas do usuário (opcionalmente de uma categoria)
     *
     * @return Notificacao[]
     */
    public function findNaoLidasByUsuario(User $usuario, ?string $categoria = null, int $limit = 10): array
    {
        $qb = $this->createQueryBuilder('n')
            ->where('n.usuario = :usuario')
            ->andWhere('n.lida = false')
            ->setParameter('usuario', $usuario)
            ->orderBy('n.criadaEm', 'DESC')
            ->setMaxResults($limit);
        $this->aplicarCategoria($qb, $categoria);

        return $qb->getQuery()->getResult();
    }

    /**
     * Conta notificações não lidas do usuário (opcionalmente de uma categoria)
     */
    public function countNaoLidasByUsuario(User $usuario, ?string $categoria = null): int
    {
        $qb = $this->createQueryBuilder('n')
            ->select('COUNT(n.id)')
            ->where('n.usuario = :usuario')
            ->andWhere('n.lida = false')
            ->setParameter('usuario', $usuario);
        $this->aplicarCategoria($qb, $categoria);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * Retorna uma página de notificações do usuário (opcionalmente de uma categoria),
     * ordenadas da mais recente para a mais antiga.
     *
     * @return Notificacao[]
     */
    public function findPaginadasByUsuario(User $usuario, ?string $categoria, int $page, int $perPage): array
    {
        $qb = $this->createQueryBuilder('n')
            ->where('n.usuario = :usuario')
            ->setParameter('usuario', $usuario)
            ->orderBy('n.criadaEm', 'DESC')
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage);
        $this->aplicarCategoria($qb, $categoria);

        return $qb->getQuery()->getResult();
    }

    /**
     * Conta o total de notificações do usuário (opcionalmente de uma categoria),
     * para o cálculo de páginas.
     */
    public function countByUsuario(User $usuario, ?string $categoria = null): int
    {
        $qb = $this->createQueryBuilder('n')
            ->select('COUNT(n.id)')
            ->where('n.usuario = :usuario')
            ->setParameter('usuario', $usuario);
        $this->aplicarCategoria($qb, $categoria);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * Indica se o usuário possui alguma notificação de gestão (para liberar a aba)
     */
    public function temNotificacaoGestao(User $usuario): bool
    {
        $count = (int) $this->createQueryBuilder('n')
            ->select('COUNT(n.id)')
            ->where('n.usuario = :usuario')
            ->andWhere('n.tipo IN (:tiposGestao)')
            ->setParameter('usuario', $usuario)
            ->setParameter('tiposGestao', Notificacao::TIPOS_GESTAO)
            ->getQuery()
            ->getSingleScalarResult();

        return $count > 0;
    }

    /**
     * Marca todas as notificações do usuário (no escritório) como lidas (opcionalmente de
     * uma categoria). O escopo por tenant é EXPLÍCITO: este UPDATE em massa em DQL não passa
     * pelo TenantFilter, então sem o andWhere "marcar todas" logado em B zeraria as de A.
     */
    public function marcarTodasComoLidas(User $usuario, Tenant $tenant, ?string $categoria = null): int
    {
        $qb = $this->createQueryBuilder('n')
            ->update()
            ->set('n.lida', 'true')
            ->set('n.lidaEm', ':agora')
            ->where('n.usuario = :usuario')
            ->andWhere('n.tenant = :tenant')
            ->andWhere('n.lida = false')
            ->setParameter('usuario', $usuario)
            ->setParameter('tenant', $tenant)
            ->setParameter('agora', new \DateTimeImmutable());
        $this->aplicarCategoria($qb, $categoria);

        return $qb->getQuery()->execute();
    }

    /**
     * Exclui as notificações informadas que pertençam ao usuário no escritório informado.
     * O filtro por usuário garante que IDs de outro usuário sejam ignorados; o filtro por
     * tenant (explícito, pois o DELETE em massa escapa o TenantFilter) impede excluir, por
     * id, notificações do mesmo usuário em outro escritório.
     *
     * @param int[] $ids
     * @return int quantidade efetivamente excluída
     */
    public function excluirDoUsuario(User $usuario, Tenant $tenant, array $ids): int
    {
        if ($ids === []) {
            return 0;
        }

        return $this->createQueryBuilder('n')
            ->delete()
            ->where('n.usuario = :usuario')
            ->andWhere('n.tenant = :tenant')
            ->andWhere('n.id IN (:ids)')
            ->setParameter('usuario', $usuario)
            ->setParameter('tenant', $tenant)
            ->setParameter('ids', $ids)
            ->getQuery()
            ->execute();
    }

    /**
     * Apaga os avisos cujo link termina EXATAMENTE na âncora informada (`#pasta-msg-12`,
     * `#nota-tecnica-7`), dos tipos informados, no escritório informado (NOT-01): quando a
     * mensagem do Registro ou a nota técnica é excluída, a menção/resposta que apontava para ela
     * não pode continuar no sino (nem na Timeline de quem recebeu) com a prévia do texto apagado.
     *
     * "Termina em": `#pasta-msg-12` não casa `#pasta-msg-123` (nem `#pasta-msg-112`), porque a
     * âncora inteira, com o `#`, tem de ser o fim do link. O escritório é EXPLÍCITO — DELETE em
     * massa em DQL não passa pelo TenantFilter —, e o tipo restringe ao aviso que essa âncora
     * gera, para não alcançar link de outra natureza que por acaso termine igual.
     *
     * @param list<string> $tipos
     *
     * @return int quantos avisos foram apagados
     */
    public function excluirPelaAncoraDoLink(Tenant $tenant, array $tipos, string $ancora): int
    {
        if ($tipos === [] || !str_starts_with($ancora, '#') || strlen($ancora) < 2) {
            return 0;
        }

        return $this->createQueryBuilder('n')
            ->delete()
            ->where('n.tenant = :tenant')
            ->andWhere('n.tipo IN (:tipos)')
            ->andWhere('n.url LIKE :sufixo')
            ->setParameter('tenant', $tenant)
            ->setParameter('tipos', $tipos)
            ->setParameter('sufixo', '%' . addcslashes($ancora, '%_\\'))
            ->getQuery()
            ->execute();
    }

    /**
     * As N notificações mais novas DO USUÁRIO, no escritório, ligadas à pasta (Timeline
     * inteligente, PAS-047i): a meta da notificação é desta pasta, ou o link leva à `pasta_show`
     * DELA (`$caminhoDaPasta` exato, ou seguido de `#`/`?` — `/pasta/12` não casa `/pasta/123`).
     *
     * Usuário e escritório são EXPLÍCITOS (não dependem do TenantFilter estar ligado): a mesma
     * pessoa em dois escritórios não vê na pasta de A o aviso gravado em B. Sem `$incluirFinanceiro`,
     * sai o aviso cujo link aponta para a aba Financeiro (`#financeiro…`) — a mesma pergunta que
     * esconde pagamentos e observações financeiras de quem não vê o financeiro.
     *
     * A meta entra só no WHERE (nada de hidratar a tarefa): o evento lê só colunas da própria
     * notificação. Mesmo teto das outras fontes da timeline, para o top-N da fusão ser exato.
     *
     * @return Notificacao[]
     */
    public function findDoUsuarioLigadasAPasta(
        User $usuario,
        Tenant $tenant,
        Pasta $pasta,
        string $caminhoDaPasta,
        bool $incluirFinanceiro,
        int $limite,
    ): array {
        $caminho = addcslashes($caminhoDaPasta, '%_\\');

        $qb = $this->createQueryBuilder('n')
            ->leftJoin('n.tarefa', 't')
            ->where('n.usuario = :usuario')
            ->andWhere('n.tenant = :tenant')
            ->andWhere('(t.pasta = :pasta OR n.url = :caminho OR n.url LIKE :caminhoAncora OR n.url LIKE :caminhoQuery)')
            ->setParameter('usuario', $usuario)
            ->setParameter('tenant', $tenant)
            ->setParameter('pasta', $pasta)
            ->setParameter('caminho', $caminhoDaPasta)
            ->setParameter('caminhoAncora', $caminho . '#%')
            ->setParameter('caminhoQuery', $caminho . '?%')
            ->orderBy('n.criadaEm', 'DESC')
            ->addOrderBy('n.id', 'DESC')
            ->setMaxResults($limite);

        if (!$incluirFinanceiro) {
            $qb->andWhere('(n.url IS NULL OR (n.url NOT LIKE :ancoraFinanceiro AND n.url NOT LIKE :queryFinanceiro))')
                ->setParameter('ancoraFinanceiro', $caminho . '#financeiro%')
                ->setParameter('queryFinanceiro', $caminho . '?%#financeiro%');
        }

        return $qb->getQuery()->getResult();
    }
}
