<?php

declare(strict_types=1);

namespace App\Central\Repository;

use App\Central\Entity\Conversa;
use App\Central\Entity\Mensagem;
use App\Central\Entity\Participante;
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
     * antiga que a tela já tem (rolar para cima); nulo = as mais recentes.
     *
     * @return list<Mensagem>
     */
    public function historico(Conversa $conversa, Tenant $tenant, ?int $antesDe, int $limite): array
    {
        $qb = $this->createQueryBuilder('m')
            ->addSelect('a', 'r', 'ra')
            ->join('m.autor', 'a')
            ->leftJoin('m.respostaA', 'r')
            ->leftJoin('r.autor', 'ra')
            ->andWhere('m.conversa = :conversa')
            ->andWhere('m.tenant = :tenant')
            ->setParameter('conversa', $conversa)
            ->setParameter('tenant', $tenant)
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
     * A última mensagem de cada conversa informada (para a prévia da lista), indexada pelo id da conversa.
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
             JOIN ' . Participante::class . ' p WITH p.conversa = m.conversa AND p.usuario = :usuario AND p.tenant = :tenant
                AND p.saiuEm IS NULL' . ($semSilenciadas ? ' AND p.silenciada = false' : '') . '
             WHERE m.tenant = :tenant AND m.autor <> :usuario AND m.sistema = false AND m.id > p.ultimaLidaId
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
     * Mensagens novas nas conversas de que o usuário participa ATIVAMENTE, em ordem — o que o polling entrega.
     * Conversa de que ele não participa (ou de que saiu) nunca entra, mesmo no mesmo escritório.
     *
     * "Nova" = id acima do cursor **ou** criada a partir de `$janela` (B, spec §6): o id é reservado no INSERT e pode
     * ser confirmado fora de ordem (a 100 depois da 101); só o cursor perderia a 100. A janela repete as recentes e a
     * tela descarta as que já viu.
     *
     * @return list<Mensagem>
     */
    public function novidades(User $usuario, Tenant $tenant, int $desde, int $limite, ?\DateTimeImmutable $janela = null): array
    {
        /** @var list<Mensagem> $mensagens */
        $mensagens = $this->getEntityManager()->createQuery(
            'SELECT m, a, r, ra FROM ' . Mensagem::class . ' m
             JOIN m.autor a
             LEFT JOIN m.respostaA r
             LEFT JOIN r.autor ra
             JOIN ' . Participante::class . ' p WITH p.conversa = m.conversa AND p.usuario = :usuario AND p.tenant = :tenant
                AND p.saiuEm IS NULL
             WHERE m.tenant = :tenant AND (m.id > :desde OR m.criadaEm >= :janela)
             ORDER BY m.id ASC'
        )
            ->setParameter('usuario', $usuario)
            ->setParameter('tenant', $tenant)
            ->setParameter('desde', $desde)
            ->setParameter('janela', $janela ?? new \DateTimeImmutable('9999-12-31'))
            ->setMaxResults($limite)
            ->getResult();

        return $mensagens;
    }

    /** Id da mensagem mais recente nas conversas do usuário (0 se nenhuma) — o cursor inicial do polling. */
    public function ultimoIdVisivelPara(User $usuario, Tenant $tenant): int
    {
        return (int) $this->getEntityManager()->createQuery(
            'SELECT MAX(m.id) FROM ' . Mensagem::class . ' m
             JOIN ' . Participante::class . ' p WITH p.conversa = m.conversa AND p.usuario = :usuario AND p.tenant = :tenant
                AND p.saiuEm IS NULL
             WHERE m.tenant = :tenant'
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
