<?php

namespace App\Ponto\Repository;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Ponto\Entity\JustificativaPonto;
use App\Ponto\Enum\TipoJustificativa;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<JustificativaPonto>
 */
class JustificativaPontoRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, JustificativaPonto::class);
    }

    /**
     * Data da justificativa ABONADA mais antiga do colaborador no escritório. Junto com a primeira
     * batida, define quando a folha começa a contar — um abono deferido também é registro de ponto.
     *
     * `$aPartirDe` limita a busca por baixo: serve para procurar o abono mais antigo DENTRO de uma
     * janela, sem que um abono retroativo antigo puxe o início da contagem para trás.
     * Retorna null quando não há nenhuma no recorte.
     */
    public function findDataPrimeiraAbonada(User $user, Tenant $tenant, ?\DateTimeInterface $aPartirDe = null): ?\DateTimeImmutable
    {
        // Filtro de tenant explícito além do TenantFilter: é dado de ponto (risco ALTO).
        $qb = $this->createQueryBuilder('j')
            ->select('MIN(j.data)')
            ->andWhere('j.user = :user')
            ->andWhere('j.tenant = :tenant')
            ->andWhere('j.status = :status')
            ->setParameter('user', $user)
            ->setParameter('tenant', $tenant)
            ->setParameter('status', 'abonado');

        if ($aPartirDe !== null) {
            $qb->andWhere('j.data >= :aPartirDe')->setParameter('aPartirDe', $aPartirDe);
        }

        $primeira = $qb->getQuery()->getSingleScalarResult();

        if ($primeira === null) {
            return null;
        }

        return new \DateTimeImmutable((string) $primeira);
    }

    /**
     * Retorna todas as justificativas do usuário no mês/ano, ordenadas por data desc.
     *
     * @return JustificativaPonto[]
     */
    public function findByUserAndCompetencia(User $user, int $ano, int $mes): array
    {
        $inicio = new \DateTimeImmutable(sprintf('%04d-%02d-01', $ano, $mes));
        $fim    = $inicio->modify('last day of this month')->setTime(23, 59, 59);

        return $this->createQueryBuilder('j')
            ->where('j.user = :user')
            ->andWhere('j.data BETWEEN :inicio AND :fim')
            ->setParameter('user', $user)
            ->setParameter('inicio', $inicio)
            ->setParameter('fim', $fim)
            ->orderBy('j.data', 'DESC')
            // Desempate obrigatório: nada impede duas justificativas na MESMA data (não há constraint
            // única em user+data, e 34 dias em produção têm de 2 a 3 — o caso comum é esquecer duas
            // batidas no mesmo dia). Sem esta segunda chave o SGBD não garante ordem entre elas, e a
            // escolha do dia mudaria de um carregamento para o outro.
            ->addOrderBy('j.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Retorna as justificativas do mês indexadas por 'Y-m-d' (para uso no FolhaPontoBuilder).
     *
     * @return array<string, JustificativaPonto>
     */
    public function findByUserAndCompetenciaIndexed(User $user, int $ano, int $mes): array
    {
        return $this->indexarPorDia($this->findByUserAndCompetencia($user, $ano, $mes));
    }

    /**
     * Escolhe UMA justificativa por dia, por mérito e de forma determinística.
     *
     * O cálculo da folha só honra uma justificativa por dia, mas o sistema aceita várias — e aceita
     * de propósito: o caso comum em produção é esquecer duas batidas no mesmo dia e lançar uma
     * justificativa para cada, com o horário de cada uma. Quando as concorrentes se comportam igual
     * (dois esquecimentos, por exemplo) tanto faz quem vence; o problema é o dia que tem uma que
     * abona e outra que não.
     *
     * Precedência: **abonada que perdoa o déficit** > abonada que não perdoa (categoria técnica e
     * falta não justificada) > pendente/rejeitada. Empate dentro do mesmo nível: vence a ÚLTIMA da
     * ordem recebida — que é a de maior id, porque `findByUserAndCompetencia` ordena por id
     * crescente. Quem chamar com uma lista fora dessa ordem recebe outro empate; a ordenação da
     * consulta é parte da regra, não enfeite.
     *
     * A regra de negócio: se o admin deferiu um atestado médico naquele dia, houve ausência
     * justificada — um "esquecimento de registro" lançado ao lado não apaga isso. Escolher pelo
     * que abona também é o que o sistema fazia antes de `abonaSaldo()` existir (qualquer abonada
     * zerava o negativo), então nenhum dia passa a ser cobrado a mais por causa do desempate.
     *
     * Público e puro para poder ser testado sem banco.
     *
     * @param JustificativaPonto[] $justificativas
     * @return array<string, JustificativaPonto>
     */
    public function indexarPorDia(array $justificativas): array
    {
        $indexed = [];
        foreach ($justificativas as $j) {
            $key = $j->getData()->format('Y-m-d');
            $atual = $indexed[$key] ?? null;

            if ($atual === null || $this->precedencia($j) >= $this->precedencia($atual)) {
                $indexed[$key] = $j;
            }
        }

        return $indexed;
    }

    /**
     * Peso da justificativa na disputa pelo dia. Maior vence; `>=` no chamador faz a de maior id
     * ganhar o empate, já que a consulta vem ordenada por id crescente.
     */
    private function precedencia(JustificativaPonto $justificativa): int
    {
        if ($justificativa->getStatus() !== 'abonado') {
            return 0;
        }

        $tipo = $justificativa->getTipo() !== null
            ? TipoJustificativa::tryFrom($justificativa->getTipo())
            : null;

        // Tipo nulo ou slug desconhecido abona — mesmo default do FolhaPontoBuilder, para que os
        // dois lugares não discordem sobre o que uma justificativa sem tipo faz.
        return ($tipo === null || $tipo->abonaSaldo()) ? 2 : 1;
    }

    /**
     * Retorna todas as justificativas do usuário, ordenadas por data desc.
     *
     * @return JustificativaPonto[]
     */
    public function findByTenantUser(User $targetUser): array
    {
        return $this->createQueryBuilder('j')
            ->where('j.user = :user')
            ->setParameter('user', $targetUser)
            ->orderBy('j.data', 'DESC')
            ->addOrderBy('j.batchId', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Verifica se já existe uma justificativa pendente ou abonada para o usuário nessa data.
     */
    public function findOneByUserAndData(User $user, \DateTimeInterface $data): ?JustificativaPonto
    {
        return $this->createQueryBuilder('j')
            ->where('j.user = :user')
            ->andWhere('j.data = :data')
            ->andWhere("j.status IN ('pendente', 'abonado')")
            ->setParameter('user', $user)
            ->setParameter('data', $data)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
