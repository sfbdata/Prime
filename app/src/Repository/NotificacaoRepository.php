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
    /**
     * Sininho em silêncio (preferência "Notificações no sistema" do Meu Perfil): só os tipos da lista contam e aparecem
     * no sino. Nulo = todos.
     *
     * @param list<string>|null $soTipos
     */
    private function soDosTipos(QueryBuilder $qb, ?array $soTipos): void
    {
        if ($soTipos !== null) {
            $qb->andWhere('n.tipo IN (:soTipos)')->setParameter('soTipos', $soTipos);
        }
    }

    /**
     * Cadeado (spec pasta-cadeado): aviso ligado a pasta oculta para a pessoa (pela meta ou pela URL
     * `/pasta/{id}`) não sai nem conta, mesmo gravado antes do cadeado. A resposta ao pedido de acesso
     * (`pasta_acesso_decidido`) sempre sai: é para quem está do lado de fora.
     *
     * @param list<int> $pastasOcultas
     */
    private function semAvisosDasPastasOcultas(QueryBuilder $qb, array $pastasOcultas): void
    {
        if ($pastasOcultas === []) {
            return;
        }

        $qb->leftJoin('n.tarefa', 'n_meta')
            ->andWhere('n_meta.id IS NULL OR IDENTITY(n_meta.pasta) NOT IN (:pastasOcultasDoAviso) OR n.tipo = :avisoDecidido')
            ->setParameter('pastasOcultasDoAviso', $pastasOcultas)
            ->setParameter('avisoDecidido', Notificacao::TIPO_PASTA_ACESSO_DECIDIDO);
        foreach ($pastasOcultas as $i => $pastaId) {
            $qb->andWhere(sprintf(
                'n.tipo = :avisoDecidido OR n.url IS NULL OR (n.url <> :avisoUrl%1$d AND n.url NOT LIKE :avisoUrlBarra%1$d AND n.url NOT LIKE :avisoUrlAncora%1$d AND n.url NOT LIKE :avisoUrlQuery%1$d)',
                $i,
            ))
                ->setParameter('avisoUrl' . $i, '/pasta/' . $pastaId)
                ->setParameter('avisoUrlBarra' . $i, '/pasta/' . $pastaId . '/%')
                ->setParameter('avisoUrlAncora' . $i, '/pasta/' . $pastaId . '#%')
                ->setParameter('avisoUrlQuery' . $i, '/pasta/' . $pastaId . '?%');
        }
    }

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
     *
     * @param list<int> $pastasOcultas ids das pastas ocultas pelo cadeado para o usuário
     */
    public function findNaoLidasByUsuario(User $usuario, ?string $categoria = null, int $limit = 10, array $pastasOcultas = [], ?array $soTipos = null): array
    {
        $qb = $this->createQueryBuilder('n')
            ->where('n.usuario = :usuario')
            ->andWhere('n.lida = false')
            ->setParameter('usuario', $usuario)
            ->orderBy('n.criadaEm', 'DESC')
            ->setMaxResults($limit);
        $this->aplicarCategoria($qb, $categoria);
        $this->semAvisosDasPastasOcultas($qb, $pastasOcultas);
        $this->soDosTipos($qb, $soTipos);

        return $qb->getQuery()->getResult();
    }

    /**
     * Conta notificações não lidas do usuário (opcionalmente de uma categoria)
     *
     * @param list<int> $pastasOcultas ids das pastas ocultas pelo cadeado para o usuário
     */
    public function countNaoLidasByUsuario(User $usuario, ?string $categoria = null, array $pastasOcultas = [], ?array $soTipos = null): int
    {
        $qb = $this->createQueryBuilder('n')
            ->select('COUNT(n.id)')
            ->where('n.usuario = :usuario')
            ->andWhere('n.lida = false')
            ->setParameter('usuario', $usuario);
        $this->aplicarCategoria($qb, $categoria);
        $this->semAvisosDasPastasOcultas($qb, $pastasOcultas);
        $this->soDosTipos($qb, $soTipos);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * Retorna uma página de notificações do usuário (opcionalmente de uma categoria),
     * ordenadas da mais recente para a mais antiga.
     *
     * @return Notificacao[]
     *
     * @param list<int> $pastasOcultas ids das pastas ocultas pelo cadeado para o usuário
     */
    public function findPaginadasByUsuario(User $usuario, ?string $categoria, int $page, int $perPage, array $pastasOcultas = []): array
    {
        $qb = $this->createQueryBuilder('n')
            ->where('n.usuario = :usuario')
            ->setParameter('usuario', $usuario)
            ->orderBy('n.criadaEm', 'DESC')
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage);
        $this->aplicarCategoria($qb, $categoria);
        $this->semAvisosDasPastasOcultas($qb, $pastasOcultas);

        return $qb->getQuery()->getResult();
    }

    /**
     * Conta o total de notificações do usuário (opcionalmente de uma categoria),
     * para o cálculo de páginas.
     *
     * @param list<int> $pastasOcultas ids das pastas ocultas pelo cadeado para o usuário
     */
    public function countByUsuario(User $usuario, ?string $categoria = null, array $pastasOcultas = []): int
    {
        $qb = $this->createQueryBuilder('n')
            ->select('COUNT(n.id)')
            ->where('n.usuario = :usuario')
            ->setParameter('usuario', $usuario);
        $this->aplicarCategoria($qb, $categoria);
        $this->semAvisosDasPastasOcultas($qb, $pastasOcultas);

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
     * Os links das notificações NÃO lidas do usuário, de um tipo, no escritório — a Central lê deles em que conversas
     * há menção por ver (o link é `/central?conversa=N`). Tenant explícito, além do TenantFilter.
     *
     * @return list<string>
     */
    public function urlsNaoLidasDoTipo(User $usuario, Tenant $tenant, string $tipo): array
    {
        /** @var list<string> $urls */
        $urls = $this->createQueryBuilder('n')
            ->select('n.url')
            ->where('n.usuario = :usuario')
            ->andWhere('n.tenant = :tenant')
            ->andWhere('n.tipo = :tipo')
            ->andWhere('n.lida = false')
            ->andWhere('n.url IS NOT NULL')
            ->setParameter('usuario', $usuario)
            ->setParameter('tenant', $tenant)
            ->setParameter('tipo', $tipo)
            ->getQuery()
            ->getSingleColumnResult();

        return $urls;
    }

    /**
     * Marca como lidas as notificações NÃO lidas do usuário, de um tipo, que apontam para um link exato (a
     * conversa da Central lida lê as menções dela). Tenant explícito: o UPDATE em massa escapa o TenantFilter.
     *
     * @return int quantidade marcada
     */
    public function marcarComoLidasPelaUrl(User $usuario, Tenant $tenant, string $tipo, string $url): int
    {
        return $this->createQueryBuilder('n')
            ->update()
            ->set('n.lida', 'true')
            ->set('n.lidaEm', ':agora')
            ->where('n.usuario = :usuario')
            ->andWhere('n.tenant = :tenant')
            ->andWhere('n.tipo = :tipo')
            ->andWhere('n.url = :url')
            ->andWhere('n.lida = false')
            ->setParameter('usuario', $usuario)
            ->setParameter('tenant', $tenant)
            ->setParameter('tipo', $tipo)
            ->setParameter('url', $url)
            ->setParameter('agora', new \DateTimeImmutable())
            ->getQuery()
            ->execute();
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

    /**
     * Não lidas do sino de VÁRIAS pessoas, só DESTE escritório (Central, Painel "Equipe · em aberto"). Só o número:
     * título e texto nunca saem. Tenant explícito: o TenantFilter não é a única barreira.
     *
     * @param list<int> $usuarioIds
     *
     * @return array<int, int> id do usuário → quantidade (só quem tem alguma)
     */
    public function contarNaoLidasPorUsuarios(Tenant $tenant, array $usuarioIds): array
    {
        if ($usuarioIds === []) {
            return [];
        }

        $linhas = $this->createQueryBuilder('n')
            ->select('IDENTITY(n.usuario) AS usuario', 'COUNT(n.id) AS total')
            ->where('n.tenant = :tenant')
            ->andWhere('n.usuario IN (:ids)')
            ->andWhere('n.lida = false')
            // Os avisos da própria Central (menção, entrou no grupo) já contam como mensagem não lida: não somar 2 vezes.
            ->andWhere('n.tipo NOT IN (:daCentral)')
            ->groupBy('n.usuario')
            ->setParameter('daCentral', [Notificacao::TIPO_CENTRAL_MENCAO, Notificacao::TIPO_CENTRAL_GRUPO_ADICIONADO])
            ->setParameter('tenant', $tenant)
            ->setParameter('ids', $usuarioIds)
            ->getQuery()
            ->getArrayResult();

        $porUsuario = [];
        foreach ($linhas as $linha) {
            $porUsuario[(int) $linha['usuario']] = (int) $linha['total'];
        }

        return $porUsuario;
    }

    /**
     * Existe aviso deste tipo no escritório criado a partir de `$desde`? É o teto do envio manual do
     * relatório de pastas sem processo (um a cada 30 minutos por escritório).
     */
    public function existeDoTipoNoEscritorioDesde(Tenant $tenant, string $tipo, \DateTimeImmutable $desde): bool
    {
        return (int) $this->createQueryBuilder('n')
            ->select('COUNT(n.id)')
            ->andWhere('n.tenant = :tenant')
            ->andWhere('n.tipo = :tipo')
            ->andWhere('n.criadaEm >= :desde')
            ->setParameter('tenant', $tenant)
            ->setParameter('tipo', $tipo)
            ->setParameter('desde', $desde)
            ->getQuery()
            ->getSingleScalarResult() > 0;
    }
}
