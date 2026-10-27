<?php

declare(strict_types=1);

namespace App\Central\Repository;

use App\Central\Entity\Conversa;
use App\Central\Entity\Participante;
use App\Central\Entity\TipoDeConversa;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<Participante> */
class ParticipanteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Participante::class);
    }

    /**
     * A participação ATIVA do usuário na conversa — é ELA que dá acesso. Conversa inexistente, de outro escritório,
     * de que o usuário não participa, ou de que saiu (B) dão o mesmo `null`: quem chama responde 404 sem distinguir.
     */
    public function daConversa(int $conversaId, User $usuario, Tenant $tenant): ?Participante
    {
        return $this->createQueryBuilder('p')
            ->addSelect('c')
            ->join('p.conversa', 'c')
            ->andWhere('c.id = :conversa')
            ->andWhere('p.usuario = :usuario')
            ->andWhere('p.saiuEm IS NULL')
            ->andWhere('p.tenant = :tenant')
            ->andWhere('c.tenant = :tenant')
            ->setParameter('conversa', $conversaId)
            ->setParameter('usuario', $usuario)
            ->setParameter('tenant', $tenant)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Os participantes ATIVOS da conversa (não saíram nem foram removidos), com a pessoa carregada, do mais antigo
     * para o mais novo — a base de quem pode ser @mencionado e de quem herda a administração do grupo.
     *
     * @return list<Participante>
     */
    public function daConversaInteira(Conversa $conversa, Tenant $tenant): array
    {
        /** @var list<Participante> $participantes */
        $participantes = $this->createQueryBuilder('p')
            ->addSelect('u')
            ->join('p.usuario', 'u')
            ->andWhere('p.conversa = :conversa')
            ->andWhere('p.saiuEm IS NULL')
            ->andWhere('p.tenant = :tenant')
            ->setParameter('conversa', $conversa)
            ->setParameter('tenant', $tenant)
            ->orderBy('p.entrouEm', 'ASC')
            ->addOrderBy('p.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $participantes;
    }

    /**
     * As participações do usuário no escritório, com a conversa e as duas pessoas carregadas, da conversa
     * mais recente para a mais antiga (a sem mensagem conta pela criação, e não vai para o topo por ser nula).
     * Inclui a conversa IA do próprio usuário; `$semIa` a tira de quem a lista para outro fim que não a caixa dele
     * (destinos do Encaminhar: não se encaminha à IA).
     *
     * @return list<Participante>
     */
    public function doUsuario(User $usuario, Tenant $tenant, bool $semIa = false): array
    {
        $qb = $this->createQueryBuilder('p')
            ->addSelect('c', 'menor', 'maior')
            ->addSelect('COALESCE(c.ultimaMensagemEm, c.criadaEm) AS HIDDEN ordem')
            ->join('p.conversa', 'c')
            ->leftJoin('c.usuarioMenor', 'menor')
            ->leftJoin('c.usuarioMaior', 'maior')
            ->andWhere('p.usuario = :usuario')
            ->andWhere('p.saiuEm IS NULL')
            ->andWhere('p.tenant = :tenant')
            ->andWhere('c.tenant = :tenant')
            ->setParameter('usuario', $usuario)
            ->setParameter('tenant', $tenant)
            ->orderBy('p.fixada', 'DESC')
            ->addOrderBy('ordem', 'DESC')
            ->addOrderBy('c.id', 'DESC');
        if ($semIa) {
            $qb->andWhere('c.tipo <> :ia')->setParameter('ia', TipoDeConversa::Ia);
        }

        /** @var list<Participante> $participacoes */
        $participacoes = $qb->getQuery()->getResult();

        return $participacoes;
    }

    /**
     * Avança o cursor de leitura no BANCO, numa instrução só e só para frente: duas abas marcando ao mesmo tempo
     * não fazem o cursor recuar (a guarda em PHP sobre o valor carregado perderia a corrida). Devolve o cursor
     * que ficou gravado.
     */
    public function avancarLeitura(Participante $participante, int $ateId): int
    {
        $this->getEntityManager()->createQuery(
            'UPDATE ' . Participante::class . ' p SET p.ultimaLidaId = :ate
             WHERE p.id = :id AND p.tenant = :tenant AND p.ultimaLidaId < :ate'
        )
            ->setParameter('ate', $ateId)
            ->setParameter('id', $participante->getId())
            ->setParameter('tenant', $participante->getTenant())
            ->execute();

        return (int) $this->getEntityManager()->createQuery(
            'SELECT p.ultimaLidaId FROM ' . Participante::class . ' p WHERE p.id = :id AND p.tenant = :tenant'
        )
            ->setParameter('id', $participante->getId())
            ->setParameter('tenant', $participante->getTenant())
            ->getSingleScalarResult();
    }

    /**
     * Avança o corte de "Excluir conversa" (limpar para mim) no BANCO, só para frente, pelo mesmo motivo de
     * `avancarLeitura`: dois pedidos ao mesmo tempo não fazem o corte recuar.
     */
    public function avancarLimpeza(Participante $participante, int $ateId): void
    {
        $this->getEntityManager()->createQuery(
            'UPDATE ' . Participante::class . ' p SET p.limpaAteId = :ate
             WHERE p.id = :id AND p.tenant = :tenant AND p.limpaAteId < :ate'
        )
            ->setParameter('ate', $ateId)
            ->setParameter('id', $participante->getId())
            ->setParameter('tenant', $participante->getTenant())
            ->execute();
    }

    /**
     * A participação do usuário na conversa, ATIVA OU NÃO — para readmitir quem saiu na mesma linha (o único
     * `(conversa, usuario)` não deixa criar outra). Só para quem já passou pela guarda da conversa.
     */
    public function deQualquerSituacao(Conversa $conversa, User $usuario, Tenant $tenant): ?Participante
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.conversa = :conversa')
            ->andWhere('p.usuario = :usuario')
            ->andWhere('p.tenant = :tenant')
            ->setParameter('conversa', $conversa)
            ->setParameter('usuario', $usuario)
            ->setParameter('tenant', $tenant)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * O usuário participa ATIVAMENTE da conversa agora? Contagem no banco (não devolve entidade): vale mesmo que a
     * participação já esteja carregada com o estado de antes. Tenant explícito.
     */
    public function participaAtivamente(Conversa $conversa, User $usuario, Tenant $tenant): bool
    {
        return (int) $this->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->andWhere('p.conversa = :conversa')
            ->andWhere('p.usuario = :usuario')
            ->andWhere('p.tenant = :tenant')
            ->andWhere('p.saiuEm IS NULL')
            ->setParameter('conversa', $conversa)
            ->setParameter('usuario', $usuario)
            ->setParameter('tenant', $tenant)
            ->getQuery()
            ->getSingleScalarResult() > 0;
    }

    /**
     * Os ids das conversas de que o usuário participa ATIVAMENTE no escritório (consulta leve, sem carregar entidades).
     *
     * @return array<int, true>
     */
    public function idsDasConversasAtivas(User $usuario, Tenant $tenant): array
    {
        $ids = $this->createQueryBuilder('p')
            ->select('IDENTITY(p.conversa) AS conversa')
            ->join('p.conversa', 'c')
            ->andWhere('p.usuario = :usuario')
            ->andWhere('p.saiuEm IS NULL')
            ->andWhere('p.tenant = :tenant')
            ->andWhere('c.tenant = :tenant')
            ->setParameter('usuario', $usuario)
            ->setParameter('tenant', $tenant)
            ->getQuery()
            ->getSingleColumnResult();

        return array_fill_keys(array_map('intval', $ids), true);
    }

    /**
     * Quantos participantes ATIVOS cada conversa tem (para a lista: "N participantes"). Depende de ids já filtrados
     * por `doUsuario`; não filtra o tipo sozinha.
     *
     * @param list<int> $conversaIds
     *
     * @return array<int, int>
     */
    public function ativosPorConversa(array $conversaIds, Tenant $tenant): array
    {
        if ($conversaIds === []) {
            return [];
        }

        $linhas = $this->createQueryBuilder('p')
            ->select('IDENTITY(p.conversa) AS conversa, COUNT(p.id) AS total')
            ->andWhere('p.conversa IN (:conversas)')
            ->andWhere('p.saiuEm IS NULL')
            ->andWhere('p.tenant = :tenant')
            ->setParameter('conversas', $conversaIds)
            ->setParameter('tenant', $tenant)
            ->groupBy('p.conversa')
            ->getQuery()
            ->getArrayResult();

        $porConversa = [];
        foreach ($linhas as $linha) {
            $porConversa[(int) $linha['conversa']] = (int) $linha['total'];
        }

        return $porConversa;
    }

    /** Desliga o "marcar como não lida" — ler a conversa vale como abrir, mesmo sem o cursor precisar andar. */
    public function desmarcarNaoLida(Participante $participante): void
    {
        $this->getEntityManager()->createQuery(
            'UPDATE ' . Participante::class . ' p SET p.marcadaNaoLida = false
             WHERE p.id = :id AND p.tenant = :tenant AND p.marcadaNaoLida = true'
        )
            ->setParameter('id', $participante->getId())
            ->setParameter('tenant', $participante->getTenant())
            ->execute();
    }

    public function salvar(Participante $participante): void
    {
        $this->getEntityManager()->persist($participante);
    }
}
