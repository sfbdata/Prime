<?php

declare(strict_types=1);

namespace App\Dashboard\Repository;

use App\Dashboard\Entity\PreferenciaDoUsuario;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Preferências pessoais de tela. Toda consulta é por escritório E por usuário: o ajuste de um
 * colega não muda nada para mim, e o meu ajuste num escritório não vale no outro.
 *
 * Este repositório não valida chave nem valor — quem decide o que pode ser gravado é o catálogo
 * do domínio, chamado pelo UseCase antes de chegar aqui.
 *
 * Memória por requisição (spec memoria-do-layout): o layout lê as mesmas chaves várias vezes por página (animações,
 * sons, sininho, Meu Perfil). O que {@see valoresDoUsuario()} já leu fica guardado por escritório, pessoa e chave;
 * {@see gravar()} e {@see apagarDoUsuario()} esquecem a pessoa naquele escritório. `ResetInterface` (tag
 * `kernel.reset`) zera entre requisições e entre mensagens do worker.
 *
 * @extends ServiceEntityRepository<PreferenciaDoUsuario>
 */
class PreferenciaDoUsuarioRepository extends ServiceEntityRepository implements ResetInterface
{
    /**
     * Por "escritorio:pessoa": todas as linhas da pessoa naquele escritório (chave => valor), lidas uma vez.
     *
     * @var array<string, array<string, mixed>>
     */
    private array $guardado = [];

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PreferenciaDoUsuario::class);
    }

    /**
     * Valores gravados pelo usuário neste escritório, só das chaves pedidas.
     *
     * Seleção escalar (não hidrata a entidade) de propósito: a gravação é SQL direto, e uma
     * entidade já carregada no EntityManager devolveria o valor de antes da gravação.
     *
     * @param list<string> $chaves
     *
     * @return array<string, mixed> chave => valor decodificado do JSON
     */
    public function valoresDoUsuario(Tenant $tenant, User $usuario, array $chaves): array
    {
        if ($chaves === []) {
            return [];
        }

        $tenantId  = $tenant->getId();
        $usuarioId = $usuario->getId();
        if ($tenantId === null || $usuarioId === null) {
            return $this->consultar($tenant, $usuario, $chaves);
        }

        // Na primeira pergunta da requisição vêm TODAS as linhas da pessoa neste escritório (são poucas): o layout pede
        // chaves diferentes em lugares diferentes (perfil, animações, painéis) e cada pedido era uma consulta.
        $chave = $tenantId . ':' . $usuarioId;
        $this->guardado[$chave] ??= $this->consultar($tenant, $usuario, null);

        // Mesma forma da consulta: só as chaves pedidas que têm linha.
        $valores = [];
        foreach ($chaves as $c) {
            if (array_key_exists($c, $this->guardado[$chave])) {
                $valores[$c] = $this->guardado[$chave][$c];
            }
        }

        return $valores;
    }

    public function reset(): void
    {
        $this->guardado = [];
    }

    /**
     * @param list<string>|null $chaves null = todas as chaves da pessoa neste escritório
     *
     * @return array<string, mixed>
     */
    private function consultar(Tenant $tenant, User $usuario, ?array $chaves): array
    {
        $qb = $this->createQueryBuilder('p')
            ->select('p.chave AS chave', 'p.valor AS valor')
            ->andWhere('p.tenant = :tenant')
            ->andWhere('p.usuario = :usuario')
            ->setParameter('tenant', $tenant)
            ->setParameter('usuario', $usuario);
        if ($chaves !== null) {
            $qb->andWhere('p.chave IN (:chaves)')->setParameter('chaves', $chaves);
        }
        $linhas = $qb->getQuery()->getArrayResult();

        $valores = [];
        foreach ($linhas as $linha) {
            $valores[(string) $linha['chave']] = $linha['valor'];
        }

        return $valores;
    }

    /**
     * Quem DESLIGOU a chave neste escritório (valor gravado `false`), em lote: o resumo diário por e-mail não pergunta
     * pessoa por pessoa. Sem linha ou com valor que não é booleano, a chave vale ligada (`CatalogoDePreferenciasDoPerfil`).
     *
     * @return list<int> ids dos usuários
     */
    public function idsQueDesligaram(Tenant $tenant, string $chave): array
    {
        return array_map('intval', $this->getEntityManager()->getConnection()->fetchFirstColumn(
            "SELECT user_id FROM preferencia_usuario WHERE tenant_id = :t AND chave = :c AND valor::text = 'false'",
            ['t' => $tenant->getId(), 'c' => $chave],
        ));
    }

    /**
     * Grava (ou substitui) o valor de uma chave de forma IDEMPOTENTE: duas abas, clique duplo ou
     * a mesma escolha repetida acabam numa linha só, com o último valor.
     *
     * É SQL direto com `ON CONFLICT … DO UPDATE` (PostgreSQL), e não `persist()` + `flush()`, pelo
     * mesmo motivo do `PastaFavoritaRepository::inserirSeAusente`: no caminho do ORM a corrida
     * estoura `UniqueConstraintViolationException` e o EntityManager FECHA. Aqui o banco decide a
     * corrida, e o UNIQUE (tenant_id, user_id, chave) é o árbitro.
     */
    public function gravar(Tenant $tenant, User $usuario, string $chave, mixed $valor): void
    {
        $this->esquecer($tenant, $usuario);
        $this->getEntityManager()->getConnection()->executeStatement(
            'INSERT INTO preferencia_usuario (atualizado_em, chave, valor, tenant_id, user_id)
             VALUES (:atualizado_em, :chave, CAST(:valor AS JSON), :tenant, :usuario)
             ON CONFLICT (tenant_id, user_id, chave)
             DO UPDATE SET valor = EXCLUDED.valor, atualizado_em = EXCLUDED.atualizado_em',
            [
                'atualizado_em' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                'chave'         => $chave,
                'valor'         => json_encode($valor, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'tenant'        => $tenant->getId(),
                'usuario'       => $usuario->getId(),
            ],
        );
    }

    /**
     * Apaga as chaves pedidas do usuário neste escritório ("Restaurar padrão"). Os outros usuários
     * e o mesmo usuário em outro escritório não são tocados.
     *
     * @param list<string> $chaves
     */
    public function apagarDoUsuario(Tenant $tenant, User $usuario, array $chaves): int
    {
        if ($chaves === []) {
            return 0;
        }
        $this->esquecer($tenant, $usuario);

        return (int) $this->getEntityManager()->createQueryBuilder()
            ->delete(PreferenciaDoUsuario::class, 'p')
            ->andWhere('p.tenant = :tenant')
            ->andWhere('p.usuario = :usuario')
            ->andWhere('p.chave IN (:chaves)')
            ->setParameter('tenant', $tenant)
            ->setParameter('usuario', $usuario)
            ->setParameter('chaves', $chaves)
            ->getQuery()
            ->execute();
    }

    private function esquecer(Tenant $tenant, User $usuario): void
    {
        unset($this->guardado[$tenant->getId() . ':' . $usuario->getId()]);
    }
}
