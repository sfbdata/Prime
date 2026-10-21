<?php

declare(strict_types=1);

namespace App\Cliente\Repository;

use App\Cliente\Entity\Cliente;
use App\Cliente\Entity\ClienteEmail;
use App\Entity\Tenant\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Os e-mails do cliente do "Avisar cliente" (spec push-avisar-cliente §1). Tenant explícito em toda
 * consulta, sem depender do TenantFilter.
 *
 * @extends ServiceEntityRepository<ClienteEmail>
 */
// Não-final: os testes de UseCase mockam o repositório do domínio.
class ClienteEmailRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ClienteEmail::class);
    }

    /**
     * TODAS as linhas do cliente, inclusive a do cadastro marcada como removida (o caderno precisa
     * dela para não ressuscitar o item virtual), em ordem de criação.
     *
     * @return list<ClienteEmail>
     */
    public function listarDoCliente(Tenant $tenant, Cliente $cliente): array
    {
        /** @var list<ClienteEmail> $linhas */
        $linhas = $this->createQueryBuilder('e')
            ->andWhere('e.tenant = :tenant')
            ->andWhere('e.cliente = :cliente')
            ->setParameter('tenant', $tenant)
            ->setParameter('cliente', $cliente)
            ->orderBy('e.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $linhas;
    }

    /**
     * Roda a operação numa transação com a linha do CLIENTE travada (SELECT ... FOR UPDATE): duas
     * telas mexendo nos e-mails do mesmo cliente ficam em fila (limite de 5, principal único e a
     * materialização do item virtual). O flush e o commit são da transação.
     *
     * @template T
     *
     * @param callable(): T $operacao
     *
     * @return T
     */
    public function comClienteTravado(Cliente $cliente, callable $operacao): mixed
    {
        // Transação à mão (e não wrapInTransaction): uma recusa de regra (422) desfaz a transação sem
        // FECHAR o EntityManager. Falha no flush o próprio Doctrine fecha.
        $em = $this->getEntityManager();
        $conexao = $em->getConnection();
        $conexao->beginTransaction();
        try {
            $conexao->executeQuery('SELECT id FROM cliente WHERE id = :id FOR UPDATE', ['id' => $cliente->getId()]);
            $resultado = $operacao();
            $em->flush();
            $conexao->commit();

            return $resultado;
        } catch (\Throwable $e) {
            if ($conexao->isTransactionActive()) {
                $conexao->rollBack();
            }

            throw $e;
        }
    }

    /**
     * Tira a marca de principal das linhas do cliente, menos a do id informado (null = todas), ANTES
     * do flush: o índice único parcial do principal não é adiável, e a ordem dos UPDATE do Doctrine
     * não garante que o antigo principal saia antes de o novo entrar.
     */
    public function limparPrincipaisExceto(Cliente $cliente, ?int $principalId): void
    {
        $sql = 'UPDATE cliente_email SET principal = false WHERE cliente_id = :cliente AND principal';
        $parametros = ['cliente' => $cliente->getId()];
        if ($principalId !== null) {
            $sql .= ' AND id <> :principal';
            $parametros['principal'] = $principalId;
        }
        $this->getEntityManager()->getConnection()->executeStatement($sql, $parametros);
    }

    /**
     * Agenda o resultado de uma operação do caderno: as linhas novas e as que saem. As alteradas já
     * estão gerenciadas; tudo vai no flush da transação de {@see self::comClienteTravado()}.
     *
     * @param list<ClienteEmail> $novas
     * @param list<ClienteEmail> $apagadas
     */
    public function gravar(array $novas, array $apagadas): void
    {
        foreach ($novas as $email) {
            $this->getEntityManager()->persist($email);
        }
        foreach ($apagadas as $email) {
            $this->getEntityManager()->remove($email);
        }
    }
}
