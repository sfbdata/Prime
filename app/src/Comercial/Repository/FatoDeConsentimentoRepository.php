<?php

declare(strict_types=1);

namespace App\Comercial\Repository;

use App\Comercial\Entity\FatoDeConsentimento;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;

/**
 * A prova do consentimento (`comercial_consentimento`, spec `comercial-e-emails.md` §3.3), por SQL e com o escopo
 * EXPLÍCITO (`tenant_id` null = plataforma). Só de acréscimo: aqui não há UPDATE; o único DELETE é o da purga das
 * pendências nunca confirmadas, que mora no {@see ConfirmacaoDeEnderecoRepository} (apaga o estado junto).
 *
 * Por SQL e não pelo ORM: o `pedir` roda no meio do trabalho de um consumidor e não pode dar `flush` nas entidades
 * pendentes dele; e a rota pública é aberta sem login, sem TenantFilter de sessão.
 *
 * @extends ServiceEntityRepository<FatoDeConsentimento>
 */
class FatoDeConsentimentoRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, FatoDeConsentimento::class);
    }

    /**
     * Acrescenta um fato. Valores já normalizados e validados pelo serviço.
     *
     * @param array{origem: string, versao: string, texto: string} $consentimento
     */
    public function registrar(?int $tenantId, string $fato, string $lista, string $email, array $consentimento, ?string $ip, ?string $agente, \DateTimeImmutable $em): void
    {
        $this->conexao()->executeStatement(
            'INSERT INTO comercial_consentimento (tenant_id, fato, lista, email, origem, versao_consentimento, texto_consentimento, ip, agente, em)
             VALUES (:tenant, :fato, :lista, :email, :origem, :versao, :texto, :ip, :agente, :em)',
            [
                'tenant' => $tenantId, 'fato' => $fato, 'lista' => $lista, 'email' => $email,
                'origem' => $consentimento['origem'], 'versao' => $consentimento['versao'], 'texto' => $consentimento['texto'],
                'ip' => $ip, 'agente' => $agente, 'em' => self::data($em),
            ],
        );
    }

    /**
     * A origem, a versão e o texto do último pedido do endereço na lista e no escopo (o que a pessoa viu antes de
     * confirmar), ou null se não há pedido.
     *
     * @return array{origem: string, versao: string, texto: string}|null
     */
    public function ultimoPedido(?int $tenantId, string $lista, string $email): ?array
    {
        [$escopo, $parametros] = self::escopo($tenantId, ['lista' => $lista, 'email' => $email, 'fato' => FatoDeConsentimento::PEDIDO]);

        /** @var array{origem: string, versao: string, texto: string}|false $linha */
        $linha = $this->conexao()->fetchAssociative(
            'SELECT origem, versao_consentimento AS versao, texto_consentimento AS texto FROM comercial_consentimento
             WHERE ' . $escopo . ' AND lista = :lista AND email = :email AND fato = :fato
             ORDER BY em DESC, id DESC LIMIT 1',
            $parametros,
        );

        return $linha === false ? null : $linha;
    }

    /** Quando foi o último pedido do endereço na lista e no escopo (base dos limites de 24 h e 30 dias). */
    public function ultimoPedidoEm(?int $tenantId, string $lista, string $email): ?\DateTimeImmutable
    {
        [$escopo, $parametros] = self::escopo($tenantId, ['lista' => $lista, 'email' => $email, 'fato' => FatoDeConsentimento::PEDIDO]);

        $em = $this->conexao()->fetchOne(
            'SELECT MAX(em) FROM comercial_consentimento WHERE ' . $escopo . ' AND lista = :lista AND email = :email AND fato = :fato',
            $parametros,
        );

        return \is_string($em) ? new \DateTimeImmutable($em) : null;
    }

    /** Quantos pedidos houve desde o instante, em todos os escopos e listas (o teto global por hora). */
    public function contarPedidosDesde(\DateTimeImmutable $desde): int
    {
        return (int) $this->conexao()->fetchOne(
            'SELECT COUNT(*) FROM comercial_consentimento WHERE fato = :fato AND em >= :desde',
            ['fato' => FatoDeConsentimento::PEDIDO, 'desde' => self::data($desde)],
        );
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
