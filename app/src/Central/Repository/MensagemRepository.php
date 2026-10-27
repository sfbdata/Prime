<?php

declare(strict_types=1);

namespace App\Central\Repository;

use App\Central\Entity\Conversa;
use App\Central\Entity\Mensagem;
use App\Central\Entity\Participante;
use App\Central\Entity\TipoDeConversa;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Mensagem> */
class MensagemRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Mensagem::class);
    }

    /**
     * Uma página do histórico, devolvida da mais antiga para a mais nova. `$antesDe` é o id da mensagem mais
     * antiga que a tela já tem (rolar para cima); nulo = as mais recentes. `$limpaAte` = o que a pessoa limpou
     * ("Excluir" a conversa): dali para trás não volta.
     *
     * @return list<Mensagem>
     */
    public function historico(Conversa $conversa, Tenant $tenant, ?int $antesDe, int $limite, int $limpaAte = 0): array
    {
        $qb = $this->createQueryBuilder('m')
            ->addSelect('a', 'r', 'ra')
            ->join('m.autor', 'a')
            ->leftJoin('m.respostaA', 'r')
            ->leftJoin('r.autor', 'ra')
            ->andWhere('m.conversa = :conversa')
            ->andWhere('m.tenant = :tenant')
            ->andWhere('m.id > :limpaAte')
            ->setParameter('conversa', $conversa)
            ->setParameter('tenant', $tenant)
            ->setParameter('limpaAte', $limpaAte)
            ->orderBy('m.id', 'DESC')
            ->setMaxResults($limite);

        if ($antesDe !== null) {
            $qb->andWhere('m.id < :antesDe')->setParameter('antesDe', $antesDe);
        }

        /** @var list<Mensagem> $mensagens */
        $mensagens = $qb->getQuery()->getResult();

        return array_reverse($mensagens);
    }

    /**
     * A última mensagem de cada conversa informada (para a prévia da lista), indexada pelo id da conversa. Depende de
     * ids já filtrados por `ParticipanteRepository::doUsuario`; não filtra o tipo sozinha.
     *
     * @param list<int> $conversaIds
     *
     * @return array<int, Mensagem>
     */
    public function ultimaDeCada(array $conversaIds, Tenant $tenant): array
    {
        if ($conversaIds === []) {
            return [];
        }

        $ultimas = $this->getEntityManager()->createQuery(
            'SELECT m, a, r, ra FROM ' . Mensagem::class . ' m JOIN m.autor a LEFT JOIN m.respostaA r LEFT JOIN r.autor ra
             WHERE m.tenant = :tenant AND m.id IN (
                SELECT MAX(m2.id) FROM ' . Mensagem::class . ' m2
                WHERE m2.tenant = :tenant AND m2.conversa IN (:conversas)
                GROUP BY m2.conversa
             )'
        )
            ->setParameter('tenant', $tenant)
            ->setParameter('conversas', $conversaIds)
            ->getResult();

        $porConversa = [];
        foreach ($ultimas as $mensagem) {
            $porConversa[(int) $mensagem->getConversa()->getId()] = $mensagem;
        }

        return $porConversa;
    }

    /**
     * Não lidas do usuário por conversa: mensagens de OUTRA pessoa acima do cursor de leitura dele, nas conversas
     * de que participa ATIVAMENTE. A própria mensagem nunca conta (por isso enviar não precisa mexer no cursor), nem
     * a de sistema do grupo (B). `$semSilenciadas` = para o total do cabeçalho: conversa silenciada fica de fora.
     *
     * @return array<int, int> id da conversa → quantidade (só as que têm alguma)
     */
    public function naoLidasPorConversa(User $usuario, Tenant $tenant, bool $semSilenciadas = false): array
    {
        $linhas = $this->getEntityManager()->createQuery(
            'SELECT IDENTITY(m.conversa) AS conversa, COUNT(m.id) AS total
             FROM ' . Mensagem::class . ' m
             JOIN m.conversa c
             JOIN ' . Participante::class . ' p WITH p.conversa = m.conversa AND p.usuario = :usuario AND p.tenant = :tenant
                AND p.saiuEm IS NULL' . ($semSilenciadas ? ' AND p.silenciada = false' : '') . '
             WHERE m.tenant = :tenant AND c.tenant = :tenant
                AND (m.autor <> :usuario OR m.deIa = true) AND m.sistema = false AND m.id > p.ultimaLidaId
             GROUP BY m.conversa'
        )
            ->setParameter('usuario', $usuario)
            ->setParameter('tenant', $tenant)
            ->getArrayResult();

        $porConversa = [];
        foreach ($linhas as $linha) {
            $porConversa[(int) $linha['conversa']] = (int) $linha['total'];
        }

        return $porConversa;
    }

    /**
     * Não lidas de VÁRIAS pessoas de uma vez (Painel, "Equipe · em aberto"): a mesma regra de
     * `naoLidasPorConversa` (de outra pessoa, não de sistema, acima do cursor de cada uma, participação ativa),
     * somada por pessoa. Silenciadas contam, como no desenho (L5641). Tenant explícito na mensagem e na participação.
     * `$semIa`: a "Equipe · em aberto" é visível a todos, e o uso do BlueJus IA de cada um é pessoal: quem mostra esse
     * número a outras pessoas passa `true` e as não lidas da conversa IA ficam fora.
     *
     * @param list<int> $usuarioIds
     *
     * @return array<int, int> id do usuário → quantidade (só quem tem alguma)
     */
    public function naoLidasPorUsuario(Tenant $tenant, array $usuarioIds, bool $semIa = false): array
    {
        if ($usuarioIds === []) {
            return [];
        }

        $query = $this->getEntityManager()->createQuery(
            'SELECT IDENTITY(p.usuario) AS usuario, COUNT(m.id) AS total
             FROM ' . Mensagem::class . ' m
             JOIN m.conversa c
             JOIN ' . Participante::class . ' p WITH p.conversa = m.conversa AND p.tenant = :tenant
                AND p.usuario IN (:ids) AND p.saiuEm IS NULL
             WHERE m.tenant = :tenant AND c.tenant = :tenant' . ($semIa ? ' AND c.tipo <> :ia' : '') . '
                AND (m.autor <> p.usuario OR m.deIa = true) AND m.sistema = false AND m.id > p.ultimaLidaId
             GROUP BY p.usuario'
        )
            ->setParameter('tenant', $tenant)
            ->setParameter('ids', $usuarioIds);
        if ($semIa) {
            $query->setParameter('ia', TipoDeConversa::Ia);
        }
        $linhas = $query->getArrayResult();

        $porUsuario = [];
        foreach ($linhas as $linha) {
            $porUsuario[(int) $linha['usuario']] = (int) $linha['total'];
        }

        return $porUsuario;
    }

    /**
     * Mensagens novas (id acima do cursor) nas conversas de que o usuário participa ATIVAMENTE, em ordem — o que o
     * polling entrega. Conversa de que ele não participa (ou de que saiu) nunca entra, mesmo no mesmo escritório.
     *
     * @return list<Mensagem>
     */
    public function novidades(User $usuario, Tenant $tenant, int $desde, int $limite): array
    {
        return $this->doPolling($usuario, $tenant, 'm.id > :desde', ['desde' => $desde], $limite);
    }

    /**
     * As mensagens recentes (criadas a partir de `$janela`) que ficam ABAIXO do cursor — a janela de sobreposição do
     * polling (B, spec §6). O id é reservado no INSERT e pode ser confirmado fora de ordem (a 100 depois da 101): só o
     * cursor perderia a 100. Consulta à parte das novidades de propósito: a janela nunca ocupa o teto das novas nem
     * faz a tela pedir de novo sem fim; a tela descarta as que já viu.
     *
     * @return list<Mensagem>
     */
    public function recentesAbaixoDoCursor(User $usuario, Tenant $tenant, int $desde, \DateTimeImmutable $janela, int $limite): array
    {
        return $this->doPolling($usuario, $tenant, 'm.id <= :desde AND m.criadaEm >= :janela', ['desde' => $desde, 'janela' => $janela], $limite);
    }

    /**
     * @param array<string, mixed> $parametros
     *
     * @return list<Mensagem>
     */
    private function doPolling(User $usuario, Tenant $tenant, string $condicao, array $parametros, int $limite): array
    {
        $query = $this->getEntityManager()->createQuery(
            'SELECT m, a, r, ra FROM ' . Mensagem::class . ' m
             JOIN m.autor a
             LEFT JOIN m.respostaA r
             LEFT JOIN r.autor ra
             JOIN m.conversa c
             JOIN ' . Participante::class . ' p WITH p.conversa = m.conversa AND p.usuario = :usuario AND p.tenant = :tenant
                AND p.saiuEm IS NULL
             WHERE m.tenant = :tenant AND c.tenant = :tenant AND m.id > p.limpaAteId AND ' . $condicao . '
             ORDER BY m.id ASC'
        )
            ->setParameter('usuario', $usuario)
            ->setParameter('tenant', $tenant)
            ->setMaxResults($limite);
        foreach ($parametros as $nome => $valor) {
            $query->setParameter($nome, $valor);
        }

        /** @var list<Mensagem> $mensagens */
        $mensagens = $query->getResult();

        return $mensagens;
    }

    /** Id da mensagem mais recente nas conversas do usuário (0 se nenhuma) — o cursor inicial do polling. */
    public function ultimoIdVisivelPara(User $usuario, Tenant $tenant): int
    {
        return (int) $this->getEntityManager()->createQuery(
            'SELECT MAX(m.id) FROM ' . Mensagem::class . ' m
             JOIN m.conversa c
             JOIN ' . Participante::class . ' p WITH p.conversa = m.conversa AND p.usuario = :usuario AND p.tenant = :tenant
                AND p.saiuEm IS NULL
             WHERE m.tenant = :tenant AND c.tenant = :tenant'
        )
            ->setParameter('usuario', $usuario)
            ->setParameter('tenant', $tenant)
            ->getSingleScalarResult();
    }

    /** Id da mensagem mais recente da conversa (0 se vazia) — o teto do cursor de leitura. */
    public function ultimoIdDa(Conversa $conversa, Tenant $tenant): int
    {
        return (int) $this->createQueryBuilder('m')
            ->select('MAX(m.id)')
            ->andWhere('m.conversa = :conversa')
            ->andWhere('m.tenant = :tenant')
            ->setParameter('conversa', $conversa)
            ->setParameter('tenant', $tenant)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * A mensagem `$id` SE for desta conversa e deste escritório — a única que pode ser respondida nela. De outra
     * conversa ou de outro escritório dá o mesmo `null`: quem chama não distingue.
     */
    public function daConversa(int $id, Conversa $conversa, Tenant $tenant): ?Mensagem
    {
        return $this->createQueryBuilder('m')
            ->addSelect('a')
            ->join('m.autor', 'a')
            ->andWhere('m.id = :id')
            ->andWhere('m.conversa = :conversa')
            ->andWhere('m.tenant = :tenant')
            ->setParameter('id', $id)
            ->setParameter('conversa', $conversa)
            ->setParameter('tenant', $tenant)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function salvar(Mensagem $mensagem): void
    {
        $this->getEntityManager()->persist($mensagem);
    }
}
