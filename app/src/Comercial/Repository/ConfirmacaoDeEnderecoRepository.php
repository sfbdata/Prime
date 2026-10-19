<?php

declare(strict_types=1);

namespace App\Comercial\Repository;

use App\Comercial\Entity\ConfirmacaoDeEndereco;
use App\Comercial\Entity\FatoDeConsentimento;
use App\Entity\Tenant\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;

/**
 * O estado do double opt-in (`comercial_confirmacao_endereco`, spec `comercial-e-emails.md` §3.3), por SQL e com o
 * escopo EXPLÍCITO (`tenant_id` null = plataforma), como o descadastro da 0d: o `pedir` não pode dar `flush` nas
 * entidades pendentes do consumidor, e a rota pública roda sem login. Um índice parcial por escopo
 * (`uniq_comercial_confirmacao_escritorio` e `_plataforma`): o `ON CONFLICT` aponta o do escopo certo.
 *
 * Só o hash SHA-256 do token fica no banco. Confirmar apaga o hash (uso único).
 *
 * @extends ServiceEntityRepository<ConfirmacaoDeEndereco>
 *
 * @phpstan-type Estado array{id: int, tenant_id: ?int, lista: string, email: string, expira_em: ?string, confirmado_em: ?string}
 */
class ConfirmacaoDeEnderecoRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ConfirmacaoDeEndereco::class);
    }

    /** Roda o trabalho numa transação só da conexão (sem `flush` do EntityManager). */
    public function emTransacao(\Closure $trabalho): mixed
    {
        return $this->conexao()->transactional($trabalho);
    }

    /**
     * O estado do endereço na lista e no escopo, ou null se nunca houve pedido.
     *
     * @return Estado|null
     */
    public function buscar(?int $tenantId, string $lista, string $email): ?array
    {
        [$escopo, $parametros] = self::escopo($tenantId, ['lista' => $lista, 'email' => $email]);

        return $this->umaLinha('WHERE ' . $escopo . ' AND lista = :lista AND email = :email', $parametros);
    }

    /**
     * O estado dono do token (pelo hash), ou null: token desconhecido, já usado ou trocado por um pedido novo.
     *
     * @return Estado|null
     */
    public function buscarPorTokenHash(string $tokenHash): ?array
    {
        return $this->umaLinha('WHERE token_hash = :hash', ['hash' => $tokenHash]);
    }

    /**
     * Grava o pedido: cria o estado ou troca o token da pendência (o antigo deixa de valer). Não mexe em
     * `confirmado_em`: o pedido sobre quem saiu da lista é o caminho de volta, e a confirmação antiga fica.
     */
    public function gravarPedido(?int $tenantId, string $lista, string $email, string $tokenHash, \DateTimeImmutable $pedidoEm, \DateTimeImmutable $expiraEm): void
    {
        $conflito = $tenantId === null
            ? '(lista, email) WHERE (tenant_id IS NULL)'
            : '(tenant_id, lista, email) WHERE (tenant_id IS NOT NULL)';

        $this->conexao()->executeStatement(
            'INSERT INTO comercial_confirmacao_endereco (tenant_id, lista, email, token_hash, pedido_em, expira_em)
             VALUES (:tenant, :lista, :email, :hash, :pedido, :expira)
             ON CONFLICT ' . $conflito . ' DO UPDATE
             SET token_hash = EXCLUDED.token_hash, pedido_em = EXCLUDED.pedido_em, expira_em = EXCLUDED.expira_em',
            ['tenant' => $tenantId, 'lista' => $lista, 'email' => $email, 'hash' => $tokenHash, 'pedido' => self::data($pedidoEm), 'expira' => self::data($expiraEm)],
        );
    }

    /**
     * Marca confirmado e apaga o token, só se o token ainda é o do estado e não venceu (duas confirmações ao mesmo
     * tempo: só uma vence). Devolve se marcou.
     */
    public function marcarConfirmado(int $id, string $tokenHash, \DateTimeImmutable $em): bool
    {
        $linhas = $this->conexao()->executeStatement(
            'UPDATE comercial_confirmacao_endereco SET confirmado_em = :em, token_hash = NULL, expira_em = NULL
             WHERE id = :id AND token_hash = :hash AND expira_em >= :em',
            ['id' => $id, 'hash' => $tokenHash, 'em' => self::data($em)],
        );

        return $linhas === 1;
    }

    /** O escritório do estado (o escopo do link), ou null se ele não existe mais. */
    public function escritorio(int $tenantId): ?Tenant
    {
        return $this->getEntityManager()->find(Tenant::class, $tenantId);
    }

    /** Quantas pendências nunca confirmadas venceram antes do limite (a simulação da purga). */
    public function contarPendentesVencidas(\DateTimeImmutable $limite): int
    {
        return (int) $this->conexao()->fetchOne(
            'SELECT COUNT(*) FROM comercial_confirmacao_endereco WHERE confirmado_em IS NULL AND expira_em < :limite',
            ['limite' => self::data($limite)],
        );
    }

    /**
     * Apaga as pendências NUNCA confirmadas que venceram antes do limite: os fatos `pedido` delas e o estado, numa
     * transação. Estado com `confirmado_em` nunca sai (nem com um pedido novo vencido por cima), e fato `confirmado`
     * nunca sai (§3.3 Retenção). Devolve quantos estados apagou.
     */
    public function purgarPendentesVencidas(\DateTimeImmutable $limite): int
    {
        $parametros = ['limite' => self::data($limite), 'fato' => FatoDeConsentimento::PEDIDO];

        return (int) $this->conexao()->transactional(static function (Connection $conexao) use ($parametros): int {
            $conexao->executeStatement(
                'DELETE FROM comercial_consentimento f WHERE f.fato = :fato AND EXISTS (
                     SELECT 1 FROM comercial_confirmacao_endereco c
                     WHERE c.confirmado_em IS NULL AND c.expira_em < :limite AND c.lista = f.lista AND c.email = f.email
                       AND (c.tenant_id = f.tenant_id OR (c.tenant_id IS NULL AND f.tenant_id IS NULL)))',
                $parametros,
            );

            return $conexao->executeStatement(
                'DELETE FROM comercial_confirmacao_endereco WHERE confirmado_em IS NULL AND expira_em < :limite',
                ['limite' => $parametros['limite']],
            );
        });
    }

    /**
     * @param array<string, mixed> $parametros
     *
     * @return Estado|null
     */
    private function umaLinha(string $onde, array $parametros): ?array
    {
        $linha = $this->conexao()->fetchAssociative(
            'SELECT id, tenant_id, lista, email, expira_em, confirmado_em FROM comercial_confirmacao_endereco ' . $onde . ' LIMIT 1',
            $parametros,
        );
        if ($linha === false) {
            return null;
        }

        return [
            'id' => (int) $linha['id'],
            'tenant_id' => $linha['tenant_id'] === null ? null : (int) $linha['tenant_id'],
            'lista' => (string) $linha['lista'],
            'email' => (string) $linha['email'],
            'expira_em' => $linha['expira_em'] === null ? null : (string) $linha['expira_em'],
            'confirmado_em' => $linha['confirmado_em'] === null ? null : (string) $linha['confirmado_em'],
        ];
    }

    /**
     * O filtro do escopo, com o null tratado à parte (`tenant_id = NULL` nunca casa).
     *
     * @param array<string, mixed> $parametros
     *
     * @return array{0: string, 1: array<string, mixed>}
     */
    private static function escopo(?int $tenantId, array $parametros): array
    {
        if ($tenantId === null) {
            return ['tenant_id IS NULL', $parametros];
        }

        $parametros['tenant'] = $tenantId;

        return ['tenant_id = :tenant', $parametros];
    }

    private function conexao(): Connection
    {
        return $this->getEntityManager()->getConnection();
    }

    private static function data(\DateTimeImmutable $em): string
    {
        return $em->format('Y-m-d H:i:s');
    }
}
