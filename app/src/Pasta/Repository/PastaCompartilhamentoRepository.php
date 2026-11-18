<?php

declare(strict_types=1);

namespace App\Pasta\Repository;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\EstadoDoCompartilhamento;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaCompartilhamento;
use App\ServiceDesk\Repository\SemFiltroDeTenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Compartilhamentos de pasta (spec acesso-restrito-e-compartilhar §4.4). "Ativo" = aceito e dentro da validade na data
 * passada (`$hoje`): o vencido fica inativo na hora, sem job. Toda consulta é escopada ao escritório, menos as da caixa
 * "Recebidas" (`recebidasPor`, `recebidaPor`, `pendentesPara`): essas são da pessoa e atravessam as contas de propósito.
 *
 * O escopo e as permissões dentro do escritório (`dqlDasPastasCompartilhadasCom`, `ativoPara`,
 * `ativoParaQuemNaoEhResponsavel`, `idsDasPastasCompartilhadasCom`) só olham o compartilhamento INTERNO: o de outra
 * conta não abre nada no escritório da pasta (as rotas da pasta recebida são da fatia 9, à parte).
 *
 * @extends ServiceEntityRepository<PastaCompartilhamento>
 */
class PastaCompartilhamentoRepository extends ServiceEntityRepository
{
    /** A caixa "Recebidas" atravessa as contas de propósito; quem chama filtra pela pessoa (`destino`), nunca pelo escritório. */
    use SemFiltroDeTenant;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PastaCompartilhamento::class);
    }

    public function salvar(PastaCompartilhamento $compartilhamento, bool $flush = false): void
    {
        $this->getEntityManager()->persist($compartilhamento);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /** O DQL das pastas compartilhadas e ativas com `:{$pessoa}` (subconsulta para o escopo do restrito). */
    public static function dqlDasPastasCompartilhadasCom(string $sufixo, string $pessoa, string $hoje): string
    {
        $c = 'pc_' . $sufixo;

        return sprintf(
            "SELECT IDENTITY(%1\$s.pasta) FROM %2\$s %1\$s WHERE %1\$s.destino = :%3\$s AND %1\$s.interno = true AND %1\$s.status = '%4\$s' AND (%1\$s.validoAte IS NULL OR %1\$s.validoAte >= :%5\$s)",
            $c,
            PastaCompartilhamento::class,
            $pessoa,
            EstadoDoCompartilhamento::Aceita->value,
            $hoje,
        );
    }

    /** O compartilhamento interno ativo da pasta com a pessoa, se houver. */
    public function ativoPara(int $pastaId, User $destino, \DateTimeImmutable $hoje): ?PastaCompartilhamento
    {
        return $this->ativosInternos($hoje)
            ->andWhere('c.pasta = :pasta')->andWhere('c.destino = :destino')
            ->setParameter('pasta', $pastaId)->setParameter('destino', $destino)
            ->setMaxResults(1)->getQuery()->getOneOrNullResult();
    }

    /** O mesmo `ativoPara()`, só quando a pessoa não é a responsável da pasta (o acesso vem do compartilhamento). */
    public function ativoParaQuemNaoEhResponsavel(int $pastaId, User $destino, \DateTimeImmutable $hoje): ?PastaCompartilhamento
    {
        return $this->ativosInternos($hoje)
            ->join('c.pasta', 'p')
            ->andWhere('c.pasta = :pasta')->andWhere('c.destino = :destino')
            ->andWhere('(p.responsavel IS NULL OR p.responsavel <> :destino)')
            ->setParameter('pasta', $pastaId)->setParameter('destino', $destino)
            ->setMaxResults(1)->getQuery()->getOneOrNullResult();
    }

    /** O que ocupa a vaga do par pasta + destino (pendente ou aceito, mesmo vencido): o índice único parcial. */
    public function ocupandoAVaga(Pasta $pasta, User $destino): ?PastaCompartilhamento
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.pasta = :pasta')->andWhere('c.destino = :destino')->andWhere('c.status IN (:vaga)')
            ->setParameter('pasta', $pasta)->setParameter('destino', $destino)
            ->setParameter('vaga', [EstadoDoCompartilhamento::Pendente->value, EstadoDoCompartilhamento::Aceita->value])
            ->setMaxResults(1)->getQuery()->getOneOrNullResult();
    }

    /**
     * Quem colabora na pasta agora (L4), do mais antigo ao mais novo.
     *
     * @return list<PastaCompartilhamento>
     */
    public function ativosDaPasta(Pasta $pasta, \DateTimeImmutable $hoje): array
    {
        return $this->ativos($hoje)
            ->andWhere('c.pasta = :pasta')->setParameter('pasta', $pasta)
            ->orderBy('c.criadoEm')->addOrderBy('c.id')
            ->getQuery()->getResult();
    }

    /**
     * Todos da pasta menos os recusados (o "Quem já colabora nesta pasta" do desenho mostra também o expirado e o
     * encerrado), do mais novo ao mais antigo.
     *
     * @return list<PastaCompartilhamento>
     */
    public function historicoDaPasta(Pasta $pasta): array
    {
        return $this->createQueryBuilder('c')
            ->addSelect('d')->join('c.destino', 'd')
            ->andWhere('c.pasta = :pasta')->andWhere('c.status <> :recusada')
            ->setParameter('pasta', $pasta)->setParameter('recusada', EstadoDoCompartilhamento::Recusada->value)
            ->orderBy('c.criadoEm', 'DESC')->addOrderBy('c.id', 'DESC')
            ->getQuery()->getResult();
    }

    /**
     * Quantos colaboram em cada pasta (o ícone da linha, L1). Pasta sem colaborador fica de fora.
     *
     * @param list<int> $pastaIds
     *
     * @return array<int, int> pastaId => quantidade
     */
    public function contagemPorPasta(Tenant $tenant, array $pastaIds, \DateTimeImmutable $hoje): array
    {
        if ($pastaIds === []) {
            return [];
        }
        $linhas = $this->ativos($hoje)
            ->select('IDENTITY(c.pasta) AS pasta', 'COUNT(c.id) AS n')
            ->andWhere('c.tenant = :tenant')->andWhere('c.pasta IN (:pastas)')
            ->setParameter('tenant', $tenant)->setParameter('pastas', $pastaIds)
            ->groupBy('c.pasta')->getQuery()->getScalarResult();
        $contagem = [];
        foreach ($linhas as $linha) {
            $contagem[(int) $linha['pasta']] = (int) $linha['n'];
        }

        return $contagem;
    }

    /**
     * As pastas compartilhadas pela equipe e ativas com a pessoa no escritório.
     *
     * @return list<int>
     */
    public function idsDasPastasCompartilhadasCom(User $destino, Tenant $tenant, \DateTimeImmutable $hoje): array
    {
        $linhas = $this->ativosInternos($hoje)
            ->select('IDENTITY(c.pasta) AS pasta')
            ->andWhere('c.tenant = :tenant')->andWhere('c.destino = :destino')
            ->setParameter('tenant', $tenant)->setParameter('destino', $destino)
            ->getQuery()->getScalarResult();

        return array_values(array_unique(array_map('intval', array_column($linhas, 'pasta'))));
    }

    /**
     * Com quem a pessoa compartilhou por último no escritório (o "1 clique" do menu, L6), sem repetir: equipe e outra
     * conta, cada destino com o tipo do último compartilhamento com ele; quem recusou não volta (desenho, dc L.827).
     *
     * @return list<array{pessoa: User, interno: bool}>
     */
    public function destinosRecentes(User $origem, Tenant $tenant, int $limite): array
    {
        $linhas = $this->createQueryBuilder('c')
            ->select('IDENTITY(c.destino) AS destino', 'MAX(c.criadoEm) AS ultimo')
            ->andWhere('c.tenant = :tenant')->andWhere('c.origem = :origem')->andWhere('c.status <> :recusada')
            ->setParameter('tenant', $tenant)->setParameter('origem', $origem)
            ->setParameter('recusada', EstadoDoCompartilhamento::Recusada->value)
            ->groupBy('c.destino')->orderBy('ultimo', 'DESC')->setMaxResults($limite)
            ->getQuery()->getScalarResult();
        $ids = array_map('intval', array_column($linhas, 'destino'));
        if ($ids === []) {
            return [];
        }
        $porId = [];
        foreach ($this->getEntityManager()->getRepository(User::class)->findBy(['id' => $ids]) as $usuario) {
            $porId[(int) $usuario->getId()] = $usuario;
        }
        $externos = array_flip($this->destinosExternosDe($origem, $tenant, $ids));
        $recentes = [];
        foreach ($ids as $id) {
            if (isset($porId[$id])) {
                $recentes[] = ['pessoa' => $porId[$id], 'interno' => !isset($externos[$id])];
            }
        }

        return $recentes;
    }

    /**
     * A pessoa já republicou para este destino de outra conta neste escritório (digitou o código dele um dia e ele não
     * recusou)? É o que deixa o "1 clique" mandar convite novo sem o código.
     */
    public function jaRepublicouPara(User $origem, User $destino, Tenant $tenant): bool
    {
        return $this->createQueryBuilder('c')
            ->select('1')
            ->andWhere('c.tenant = :tenant')->andWhere('c.origem = :origem')->andWhere('c.destino = :destino')->andWhere('c.interno = false')
            ->andWhere('c.status <> :recusada')
            ->setParameter('tenant', $tenant)->setParameter('origem', $origem)->setParameter('destino', $destino)
            ->setParameter('recusada', EstadoDoCompartilhamento::Recusada->value)
            ->setMaxResults(1)->getQuery()->getOneOrNullResult() !== null;
    }

    /**
     * A caixa "Recebidas" (L10): os convites de outras contas para a pessoa, de qualquer escritório, só os pendentes e
     * os aceitos (o recusado e o encerrado saem, como no desenho), menos os de pasta excluída, do mais novo ao mais antigo.
     *
     * @return list<PastaCompartilhamento>
     */
    public function recebidasPor(User $destino, int $limite = 100): array
    {
        return $this->semFiltroDeTenant(fn (): array => $this->createQueryBuilder('c')
            ->addSelect('p', 't', 'o')->join('c.pasta', 'p')->join('c.tenant', 't')->join('c.origem', 'o')
            ->andWhere('c.destino = :destino')->andWhere('c.interno = false')->andWhere('c.status IN (:vivos)')
            ->andWhere('p.excluidaEm IS NULL')
            ->setParameter('destino', $destino)->setParameter('vivos', [EstadoDoCompartilhamento::Pendente->value, EstadoDoCompartilhamento::Aceita->value])
            ->orderBy('c.criadoEm', 'DESC')->addOrderBy('c.id', 'DESC')->setMaxResults($limite)
            ->getQuery()->getResult());
    }

    /** Um convite de outra conta para a pessoa (aceitar, recusar, deixar), de qualquer escritório; de outro = nulo. */
    public function recebidaPor(int $id, User $destino): ?PastaCompartilhamento
    {
        return $this->semFiltroDeTenant(fn (): ?PastaCompartilhamento => $this->createQueryBuilder('c')
            ->addSelect('p')->join('c.pasta', 'p')
            ->andWhere('c.id = :id')->andWhere('c.destino = :destino')->andWhere('c.interno = false')
            ->andWhere('p.excluidaEm IS NULL')
            ->setParameter('id', $id)->setParameter('destino', $destino)
            ->getQuery()->getOneOrNullResult());
    }

    /** Quantos convites de outras contas aguardam a pessoa (o número do botão "Recebidas", L9). */
    public function pendentesPara(User $destino, \DateTimeImmutable $hoje): int
    {
        return (int) $this->semFiltroDeTenant(fn (): mixed => $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')->join('c.pasta', 'p')
            ->andWhere('c.destino = :destino')->andWhere('c.interno = false')->andWhere('c.status = :pendente')
            ->andWhere('(c.validoAte IS NULL OR c.validoAte >= :hoje)')->andWhere('p.excluidaEm IS NULL')
            ->setParameter('destino', $destino)->setParameter('pendente', EstadoDoCompartilhamento::Pendente->value)
            ->setParameter('hoje', $hoje->setTime(0, 0), 'date_immutable')
            ->getQuery()->getSingleScalarResult());
    }

    public function doEscritorio(int $id, Tenant $tenant): ?PastaCompartilhamento
    {
        return $this->findOneBy(['id' => $id, 'tenant' => $tenant]);
    }

    private function ativosInternos(\DateTimeImmutable $hoje): QueryBuilder
    {
        return $this->ativos($hoje)->andWhere('c.interno = true');
    }

    /**
     * Dos destinos, os do último compartilhamento de outra conta (o tipo que o "1 clique" repete).
     *
     * @param list<int> $ids
     *
     * @return list<int>
     */
    private function destinosExternosDe(User $origem, Tenant $tenant, array $ids): array
    {
        $linhas = $this->createQueryBuilder('c')
            ->select('IDENTITY(c.destino) AS destino', 'c.interno AS interno')
            ->andWhere('c.tenant = :tenant')->andWhere('c.origem = :origem')->andWhere('c.destino IN (:ids)')->andWhere('c.status <> :recusada')
            ->setParameter('tenant', $tenant)->setParameter('origem', $origem)->setParameter('ids', $ids)
            ->setParameter('recusada', EstadoDoCompartilhamento::Recusada->value)
            ->orderBy('c.criadoEm', 'DESC')->addOrderBy('c.id', 'DESC')
            ->getQuery()->getScalarResult();
        $ultimo = [];
        foreach ($linhas as $linha) {
            $ultimo[(int) $linha['destino']] ??= (bool) $linha['interno'];
        }

        return array_keys(array_filter($ultimo, static fn (bool $interno): bool => !$interno));
    }

    private function ativos(\DateTimeImmutable $hoje): QueryBuilder
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.status = :aceita')->andWhere('(c.validoAte IS NULL OR c.validoAte >= :hoje)')
            ->setParameter('aceita', EstadoDoCompartilhamento::Aceita->value)
            ->setParameter('hoje', $hoje->setTime(0, 0), 'date_immutable');
    }
}
