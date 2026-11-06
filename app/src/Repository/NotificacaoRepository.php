<?php

namespace App\Repository;

use App\Entity\Auth\User;
use App\Entity\Notificacao;
use App\Entity\Tarefa\Tarefa;
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
    /** Os tipos de aviso do Trabalho Externo começam assim (`Notificacao::TIPO_TRABALHO_EXTERNO_*`). */
    private const PREFIXO_DO_TRABALHO_EXTERNO = 'trabalho_externo_';

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Notificacao::class);
    }

    /** O aviso mais recente deste tipo para a pessoa sobre a meta (o intervalo do Lembrar, L10). */
    public function ultimaDoTipoNaTarefa(Tarefa $tarefa, User $usuario, string $tipo): ?Notificacao
    {
        return $this->findOneBy(['tarefa' => $tarefa, 'usuario' => $usuario, 'tipo' => $tipo], ['criadaEm' => 'DESC']);
    }

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
        $this->semLinksDasPastas($qb, $pastasOcultas, 'avisoUrl');
    }

    /**
     * Auditoria 1.17: a mesma pergunta da Timeline da pasta ({@see findDoUsuarioLigadasAPasta()}), refeita na
     * leitura, porque o aviso só conferiu a visibilidade quando nasceu e guarda a prévia do texto.
     * - semLeitura: pastas que a pessoa não pode mais ver (permissão, sem cadeado). Corta pelo LINK `/pasta/{id}`,
     *   não pela meta: o responsável pela meta continua vendo a meta sem ver a pasta (`AcessoAMeta`).
     * - semFinanceiro: pastas com a aba Financeiro oculta para a pessoa. Corta o aviso com âncora `#financeiro`.
     * A resposta ao pedido de acesso (`pasta_acesso_decidido`) sempre sai: é para quem está do lado de fora.
     *
     * @param array{semLeitura?: list<int>, semFinanceiro?: list<int>} $cortes
     */
    private function semAvisosSemLeitura(QueryBuilder $qb, array $cortes): void
    {
        $semLeitura    = $cortes['semLeitura'] ?? [];
        $semFinanceiro = $cortes['semFinanceiro'] ?? [];
        if ($semLeitura !== []) {
            $qb->setParameter('avisoDecididoLeitura', Notificacao::TIPO_PASTA_ACESSO_DECIDIDO);
            $this->semLinksDasPastas($qb, $semLeitura, 'avisoSemLeitura', 'avisoDecididoLeitura');
        }
        foreach ($semFinanceiro as $i => $pastaId) {
            $qb->andWhere(sprintf('n.url IS NULL OR (n.url NOT LIKE :avisoFinAncora%1$d AND n.url NOT LIKE :avisoFinQuery%1$d)', $i))
                ->setParameter('avisoFinAncora' . $i, '/pasta/' . $pastaId . '#financeiro%')
                ->setParameter('avisoFinQuery' . $i, '/pasta/' . $pastaId . '?%#financeiro%');
        }
    }

    /**
     * Tira os avisos cujo link é a pasta (`/pasta/{id}`, com barra, âncora ou query), menos a resposta ao pedido.
     *
     * @param list<int> $pastas
     */
    private function semLinksDasPastas(QueryBuilder $qb, array $pastas, string $prefixo, string $parametroDoDecidido = 'avisoDecidido'): void
    {
        foreach ($pastas as $i => $pastaId) {
            $qb->andWhere(sprintf(
                'n.tipo = :%2$s OR n.url IS NULL OR (n.url <> :%1$s%3$d AND n.url NOT LIKE :%1$sBarra%3$d AND n.url NOT LIKE :%1$sAncora%3$d AND n.url NOT LIKE :%1$sQuery%3$d)',
                $prefixo,
                $parametroDoDecidido,
                $i,
            ))
                ->setParameter($prefixo . $i, '/pasta/' . $pastaId)
                ->setParameter($prefixo . 'Barra' . $i, '/pasta/' . $pastaId . '/%')
                ->setParameter($prefixo . 'Ancora' . $i, '/pasta/' . $pastaId . '#%')
                ->setParameter($prefixo . 'Query' . $i, '/pasta/' . $pastaId . '?%');
        }
    }

    /**
     * Os ids das pastas que aparecem no link (`/pasta/{id}`, com barra, âncora ou query) dos avisos da pessoa
     * neste escritório, para {@see \App\Pasta\Service\LeituraDasPastasDosAvisos}. Tenant explícito.
     *
     * @return list<int>
     */
    public function idsDasPastasNosLinksDoUsuario(User $usuario, Tenant $tenant): array
    {
        $ids = $this->getEntityManager()->getConnection()->fetchFirstColumn(
            "SELECT DISTINCT CAST(substring(url from '^/pasta/([0-9]+)(?:[/?#]|$)') AS INTEGER)
               FROM notificacao
              WHERE usuario_id = :usuario AND tenant_id = :tenant AND url ~ '^/pasta/[0-9]+([/?#]|$)'",
            ['usuario' => $usuario->getId(), 'tenant' => $tenant->getId()],
        );

        return array_map('intval', $ids);
    }

    /**
     * O escritório do sino é EXPLÍCITO (achado 1.15): sem escritório na sessão, o TenantFilter está desligado e a
     * leitura só pelo usuário juntava os avisos de todos os escritórios da pessoa.
     * - Tenant: só os avisos desse escritório (o da sessão).
     * - lista de ids: sem escritório na sessão, quem não é Master vê só os avisos do Trabalho Externo dos
     *   escritórios em que tem vínculo ativo ou convite vigente (lista vazia: nenhum aviso). Esses avisos não
     *   levam meta nem link de pasta, então o cadeado não tem o que cortar neles.
     * - nulo: sem corte, só para o Master sem escritório na sessão (como sempre foi).
     *
     * @param Tenant|list<int>|null $escritorio
     */
    private function doEscritorio(QueryBuilder $qb, Tenant|array|null $escritorio): void
    {
        if ($escritorio instanceof Tenant) {
            $qb->andWhere('n.tenant = :escritorioDoSino')->setParameter('escritorioDoSino', $escritorio);

            return;
        }
        if ($escritorio === null) {
            return;
        }
        if ($escritorio === []) {
            $qb->andWhere('1 = 0');

            return;
        }
        $qb->andWhere('IDENTITY(n.tenant) IN (:escritoriosDoSino)')
            ->andWhere('n.tipo LIKE :tiposDoTrabalhoExterno')
            ->setParameter('escritoriosDoSino', $escritorio)
            ->setParameter('tiposDoTrabalhoExterno', addcslashes(self::PREFIXO_DO_TRABALHO_EXTERNO, '%_\\') . '%');
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
     *
     * @param Tenant|list<int>|null $escritorio ver {@see doEscritorio()}
     * @param list<int> $pastasOcultas ids das pastas ocultas pelo cadeado para o usuário
     * @param array{semLeitura?: list<int>, semFinanceiro?: list<int>} $cortesDaLeitura ver {@see semAvisosSemLeitura()}
     */
    public function findNaoLidasByUsuario(User $usuario, Tenant|array|null $escritorio, ?string $categoria = null, int $limit = 10, array $pastasOcultas = [], ?array $soTipos = null, array $cortesDaLeitura = []): array
    {
        $qb = $this->createQueryBuilder('n')
            ->where('n.usuario = :usuario')
            ->andWhere('n.lida = false')
            ->setParameter('usuario', $usuario)
            ->orderBy('n.criadaEm', 'DESC')
            ->setMaxResults($limit);
        $this->doEscritorio($qb, $escritorio);
        $this->aplicarCategoria($qb, $categoria);
        $this->semAvisosDasPastasOcultas($qb, $pastasOcultas);
        $this->semAvisosSemLeitura($qb, $cortesDaLeitura);
        $this->soDosTipos($qb, $soTipos);

        return $qb->getQuery()->getResult();
    }

    /**
     * Conta notificações não lidas do usuário (opcionalmente de uma categoria)
     *
     * @param Tenant|list<int>|null $escritorio ver {@see doEscritorio()}
     * @param list<int> $pastasOcultas ids das pastas ocultas pelo cadeado para o usuário
     * @param array{semLeitura?: list<int>, semFinanceiro?: list<int>} $cortesDaLeitura ver {@see semAvisosSemLeitura()}
     */
    public function countNaoLidasByUsuario(User $usuario, Tenant|array|null $escritorio, ?string $categoria = null, array $pastasOcultas = [], ?array $soTipos = null, array $cortesDaLeitura = []): int
    {
        $qb = $this->createQueryBuilder('n')
            ->select('COUNT(n.id)')
            ->where('n.usuario = :usuario')
            ->andWhere('n.lida = false')
            ->setParameter('usuario', $usuario);
        $this->doEscritorio($qb, $escritorio);
        $this->aplicarCategoria($qb, $categoria);
        $this->semAvisosDasPastasOcultas($qb, $pastasOcultas);
        $this->semAvisosSemLeitura($qb, $cortesDaLeitura);
        $this->soDosTipos($qb, $soTipos);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * As três contagens do sino (total, aba "Minhas" e aba "Gestão") numa consulta só, com os mesmos filtros de
     * {@see countNaoLidasByUsuario()}: escritório, cadeado, cortes da leitura e silêncio do sininho. A categoria sai
     * do tipo, como em {@see aplicarCategoria()}: Gestão é o tipo na lista {@see Notificacao::TIPOS_GESTAO}; Minhas,
     * o tipo fora dela.
     *
     * @param Tenant|list<int>|null $escritorio ver {@see doEscritorio()}
     * @param list<int> $pastasOcultas ids das pastas ocultas pelo cadeado para o usuário
     * @param list<string>|null $soTipos ver {@see soDosTipos()}
     * @param array{semLeitura?: list<int>, semFinanceiro?: list<int>} $cortesDaLeitura ver {@see semAvisosSemLeitura()}
     *
     * @return array{total: int, pessoal: int, gestao: int}
     */
    public function contarNaoLidasPorCategoria(User $usuario, Tenant|array|null $escritorio, array $pastasOcultas = [], ?array $soTipos = null, array $cortesDaLeitura = []): array
    {
        $qb = $this->createQueryBuilder('n')
            ->select('COUNT(n.id) AS total')
            ->addSelect('SUM(CASE WHEN n.tipo NOT IN (:tiposGestaoDoSino) THEN 1 ELSE 0 END) AS pessoal')
            ->addSelect('SUM(CASE WHEN n.tipo IN (:tiposGestaoDoSino) THEN 1 ELSE 0 END) AS gestao')
            ->where('n.usuario = :usuario')
            ->andWhere('n.lida = false')
            ->setParameter('usuario', $usuario)
            ->setParameter('tiposGestaoDoSino', Notificacao::TIPOS_GESTAO);
        $this->doEscritorio($qb, $escritorio);
        $this->semAvisosDasPastasOcultas($qb, $pastasOcultas);
        $this->semAvisosSemLeitura($qb, $cortesDaLeitura);
        $this->soDosTipos($qb, $soTipos);

        /** @var array{total: int|string|null, pessoal: int|string|null, gestao: int|string|null} $linha */
        $linha = $qb->getQuery()->getSingleResult();

        // Sem nenhuma linha, o SUM devolve nulo: vira zero, como o COUNT.
        return [
            'total'   => (int) $linha['total'],
            'pessoal' => (int) $linha['pessoal'],
            'gestao'  => (int) $linha['gestao'],
        ];
    }

    /**
     * Retorna uma página de notificações do usuário (opcionalmente de uma categoria),
     * ordenadas da mais recente para a mais antiga.
     *
     * @return Notificacao[]
     *
     * @param Tenant|list<int>|null $escritorio ver {@see doEscritorio()}
     * @param list<int> $pastasOcultas ids das pastas ocultas pelo cadeado para o usuário
     * @param array{semLeitura?: list<int>, semFinanceiro?: list<int>} $cortesDaLeitura ver {@see semAvisosSemLeitura()}
     */
    public function findPaginadasByUsuario(User $usuario, Tenant|array|null $escritorio, ?string $categoria, int $page, int $perPage, array $pastasOcultas = [], array $cortesDaLeitura = []): array
    {
        $qb = $this->createQueryBuilder('n')
            ->where('n.usuario = :usuario')
            ->setParameter('usuario', $usuario)
            ->orderBy('n.criadaEm', 'DESC')
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage);
        $this->doEscritorio($qb, $escritorio);
        $this->aplicarCategoria($qb, $categoria);
        $this->semAvisosDasPastasOcultas($qb, $pastasOcultas);
        $this->semAvisosSemLeitura($qb, $cortesDaLeitura);

        return $qb->getQuery()->getResult();
    }

    /**
     * Conta o total de notificações do usuário (opcionalmente de uma categoria),
     * para o cálculo de páginas.
     *
     * @param Tenant|list<int>|null $escritorio ver {@see doEscritorio()}
     * @param list<int> $pastasOcultas ids das pastas ocultas pelo cadeado para o usuário
     * @param array{semLeitura?: list<int>, semFinanceiro?: list<int>} $cortesDaLeitura ver {@see semAvisosSemLeitura()}
     */
    public function countByUsuario(User $usuario, Tenant|array|null $escritorio, ?string $categoria = null, array $pastasOcultas = [], array $cortesDaLeitura = []): int
    {
        $qb = $this->createQueryBuilder('n')
            ->select('COUNT(n.id)')
            ->where('n.usuario = :usuario')
            ->setParameter('usuario', $usuario);
        $this->doEscritorio($qb, $escritorio);
        $this->aplicarCategoria($qb, $categoria);
        $this->semAvisosDasPastasOcultas($qb, $pastasOcultas);
        $this->semAvisosSemLeitura($qb, $cortesDaLeitura);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * Indica se o usuário possui alguma notificação de gestão (para liberar a aba)
     *
     * @param Tenant|list<int>|null $escritorio ver {@see doEscritorio()}
     */
    public function temNotificacaoGestao(User $usuario, Tenant|array|null $escritorio): bool
    {
        $qb = $this->createQueryBuilder('n')
            ->select('COUNT(n.id)')
            ->where('n.usuario = :usuario')
            ->andWhere('n.tipo IN (:tiposGestao)')
            ->setParameter('usuario', $usuario)
            ->setParameter('tiposGestao', Notificacao::TIPOS_GESTAO);
        $this->doEscritorio($qb, $escritorio);

        return (int) $qb->getQuery()->getSingleScalarResult() > 0;
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
     * Quem, neste escritório, tem aviso NÃO LIDO do tipo informado com o link começando por
     * `$inicioDoLink` (ex.: `/pasta/41#`, que não casa `/pasta/412#`). É a deduplicação do
     * "Acompanhar alterações" da pasta: um aviso não lido por pasta e por pessoa. Escritório
     * explícito: não depende do TenantFilter.
     *
     * @return array<int, true> id do usuário → true
     */
    public function idsDosUsuariosComNaoLidaNoLink(Tenant $tenant, string $tipo, string $inicioDoLink): array
    {
        $ids = $this->createQueryBuilder('n')
            ->select('DISTINCT IDENTITY(n.usuario) AS usuario_id')
            ->where('n.tenant = :tenant')
            ->andWhere('n.tipo = :tipo')
            ->andWhere('n.lida = false')
            ->andWhere('n.url LIKE :inicio')
            ->setParameter('tenant', $tenant)
            ->setParameter('tipo', $tipo)
            ->setParameter('inicio', addcslashes($inicioDoLink, '%_\\') . '%')
            ->getQuery()
            ->getSingleColumnResult();

        return array_fill_keys(array_map('intval', $ids), true);
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

    /**
     * Existe aviso deste tipo, com este link, no escritório, criado a partir de `$desde`? É o teto do "Pedir acesso"
     * aos Perfis (um por pessoa por dia): o link do aviso identifica a pessoa que pediu. Tenant explícito, além do
     * TenantFilter.
     */
    public function existeDoTipoComUrlNoEscritorioDesde(Tenant $tenant, string $tipo, string $url, \DateTimeImmutable $desde): bool
    {
        return (int) $this->createQueryBuilder('n')
            ->select('COUNT(n.id)')
            ->andWhere('n.tenant = :tenant')
            ->andWhere('n.tipo = :tipo')
            ->andWhere('n.url = :url')
            ->andWhere('n.criadaEm >= :desde')
            ->setParameter('tenant', $tenant)
            ->setParameter('tipo', $tipo)
            ->setParameter('url', $url)
            ->setParameter('desde', $desde)
            ->getQuery()
            ->getSingleScalarResult() > 0;
    }
}
