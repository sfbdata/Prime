<?php

namespace App\Repository;

use App\Entity\Auth\User;
use App\Entity\Tarefa\TarefaMensagem;
use App\Entity\Tenant\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TarefaMensagem>
 */
class TarefaMensagemRepository extends ServiceEntityRepository
{
    /** Classe de trava (primeiro argumento de pg_advisory_xact_lock): o Lembrar de uma meta (4717 é a numeração). */
    public const CLASSE_TRAVA_DO_LEMBRETE = 4718;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TarefaMensagem::class);
    }

    public function salvar(TarefaMensagem $mensagem, bool $flush = false): void
    {
        $this->getEntityManager()->persist($mensagem);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /** Grava o que já está gerenciado (ciência, certidão, troca de status da meta junto do despacho). */
    public function confirmar(): void
    {
        $this->getEntityManager()->flush();
    }

    /**
     * Roda o trabalho numa transação do EntityManager (o `flush` sai no fim) com a trava do Lembrar da meta: dois
     * pedidos simultâneos para a mesma meta passam um depois do outro, e o segundo já lê o aviso que o primeiro gravou
     * (o limite de um por hora não estoura por corrida). A trava é advisory por meta e cai no COMMIT.
     *
     * @template T
     *
     * @param \Closure(): T $trabalho
     *
     * @return T
     */
    public function emTransacaoTravadaPelaMeta(int $metaId, \Closure $trabalho): mixed
    {
        return $this->getEntityManager()->wrapInTransaction(function () use ($metaId, $trabalho): mixed {
            $this->getEntityManager()->getConnection()->executeStatement('SELECT pg_advisory_xact_lock(?, ?)', [self::CLASSE_TRAVA_DO_LEMBRETE, $metaId]);

            return $trabalho();
        });
    }

    /**
     * Grava o código do registro (D1) num despacho já gravado, se ele ainda não tem código. O índice único
     * (tenant, código) é a trava contra a colisão: o UPDATE corre num ponto de salvamento, para a violação não
     * derrubar a transação (nem fechar o EntityManager, como faria um flush). Devolve falso na colisão.
     */
    public function gravarCodigoSeLivre(TarefaMensagem $despacho, string $codigo): bool
    {
        $conexao = $this->getEntityManager()->getConnection();
        $conexao->beginTransaction();
        try {
            $linhas = $conexao->executeStatement(
                'UPDATE tarefa_mensagem SET codigo = :codigo WHERE id = :id AND codigo IS NULL',
                ['codigo' => $codigo, 'id' => $despacho->getId()],
            );
            $conexao->commit();
        } catch (UniqueConstraintViolationException) {
            $conexao->rollBack();

            return false;
        }
        $this->getEntityManager()->refresh($despacho);

        return $linhas === 1;
    }

    /** Ciência (C14) atômica: só grava se ainda não há ciência; a primeira vale. */
    public function darCienciaSeAinda(TarefaMensagem $despacho, User $quem, \DateTimeImmutable $quando): bool
    {
        return $this->atualizarSeVazio($despacho, 'cienteEm', ['cientePor' => $quem, 'cienteEm' => $quando]);
    }

    /** Certidão (C15) atômica: só grava se o despacho ainda não tem certidão; a segunda não sobrescreve. */
    public function certificarSeAinda(TarefaMensagem $despacho, User $quem, string $texto, \DateTimeImmutable $quando): bool
    {
        return $this->atualizarSeVazio($despacho, 'certidao', ['certidao' => $texto, 'certificadoPor' => $quem, 'certificadoEm' => $quando]);
    }

    /** @param array<string, mixed> $valores campo => valor */
    private function atualizarSeVazio(TarefaMensagem $despacho, string $campoVazio, array $valores): bool
    {
        $qb = $this->getEntityManager()->createQueryBuilder()
            ->update(TarefaMensagem::class, 'm')
            ->where('m.id = :id')
            ->andWhere(sprintf('m.%s IS NULL', $campoVazio))
            ->setParameter('id', $despacho->getId());
        foreach ($valores as $campo => $valor) {
            $qb->set('m.' . $campo, ':' . $campo)->setParameter($campo, $valor);
        }
        $linhas = (int) $qb->getQuery()->execute();
        $this->getEntityManager()->refresh($despacho);

        return $linhas === 1;
    }

    /** Validação do registro (C13): só acha no escritório da sessão; outro escritório é "não encontrado". */
    public function doEscritorioPorCodigo(Tenant $tenant, string $codigo): ?TarefaMensagem
    {
        return $this->findOneBy(['tenant' => $tenant, 'codigo' => $codigo]);
    }
}
