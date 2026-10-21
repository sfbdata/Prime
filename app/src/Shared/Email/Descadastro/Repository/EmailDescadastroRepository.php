<?php

declare(strict_types=1);

namespace App\Shared\Email\Descadastro\Repository;

use App\Entity\Tenant\Tenant;
use App\Shared\Email\Descadastro\Entity\EmailDescadastro;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Persistência do descadastro, por SQL e com o escopo EXPLÍCITO (o escritório, ou null = plataforma).
 *
 * Por que não pelo ORM: o link é aberto por quem não está logado, ou por alguém logado em OUTRO
 * escritório (o TenantFilter estaria ligado no tenant da sessão e esconderia a linha existente,
 * e a segunda gravação estouraria o UNIQUE). A captação roda no CLI, sem filtro. SQL com o escopo
 * na mão vale igual nos três casos, e o `ON CONFLICT DO NOTHING` (contra o índice parcial do
 * escopo, só das linhas ativas) faz do pedido repetido um no-op.
 *
 * "Ativa" = `reativado_em IS NULL`. Nada é apagado: reativar encerra a linha.
 *
 * @extends ServiceEntityRepository<EmailDescadastro>
 */
class EmailDescadastroRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EmailDescadastro::class);
    }

    /** Grava o descadastro ativo no escopo; se já há um ativo, não faz nada. Valores já normalizados. */
    public function registrarSeAusente(?Tenant $tenant, string $email, string $assunto, string $origem, ?string $motivo, \DateTimeImmutable $em): void
    {
        $conflito = $tenant === null
            ? '(email, assunto) WHERE (tenant_id IS NULL) AND (reativado_em IS NULL)'
            : '(tenant_id, email, assunto) WHERE (tenant_id IS NOT NULL) AND (reativado_em IS NULL)';

        $this->conexao()->executeStatement(
            'INSERT INTO email_descadastro (tenant_id, email, assunto, origem, motivo, em)
             VALUES (:tenant, :email, :assunto, :origem, :motivo, :em)
             ON CONFLICT ' . $conflito . ' DO NOTHING',
            ['tenant' => $tenant?->getId(), 'email' => $email, 'assunto' => $assunto, 'origem' => $origem, 'motivo' => $motivo, 'em' => self::data($em)],
        );
    }

    /**
     * Se o assunto está bloqueado no escopo: há linha ATIVA dele; ou há `todos` ATIVO sem exceção do
     * assunto depois dele. "Depois" = reativada no mesmo instante ou mais tarde E inserida depois
     * (id maior): as datas têm precisão de segundo, e a exceção gravada no mesmo segundo do `todos`
     * tem de valer, sem que um `todos` novo, posterior, herde a exceção antiga.
     */
    public function bloqueado(?Tenant $tenant, string $email, string $assunto, string $todos): bool
    {
        [$escopoD, $escopoX, $parametros] = $this->escopo($tenant, ['email' => $email, 'assunto' => $assunto, 'todos' => $todos]);

        $achou = $this->conexao()->fetchOne(
            'SELECT 1 FROM email_descadastro d
             WHERE ' . $escopoD . ' AND d.email = :email AND d.reativado_em IS NULL
               AND (d.assunto = :assunto OR (d.assunto = :todos AND NOT EXISTS (
                   SELECT 1 FROM email_descadastro x
                   WHERE ' . $escopoX . ' AND x.email = d.email AND x.assunto = :assunto
                     AND x.reativado_em IS NOT NULL AND x.reativado_em >= d.em AND x.id > d.id
               )))
             LIMIT 1',
            $parametros,
        );

        return $achou !== false;
    }

    /**
     * Encerra as linhas ativas do endereço no escopo: de um assunto, ou de todos (`$assunto` null).
     */
    public function encerrarAtivos(?Tenant $tenant, string $email, ?string $assunto, string $origem, ?int $porId, \DateTimeImmutable $em): void
    {
        [$escopo, , $parametros] = $this->escopo($tenant, ['email' => $email, 'origem' => $origem, 'por' => $porId, 'em' => self::data($em)]);
        $doAssunto = '';
        if ($assunto !== null) {
            $doAssunto = ' AND d.assunto = :assunto';
            $parametros['assunto'] = $assunto;
        }

        $this->conexao()->executeStatement(
            'UPDATE email_descadastro d SET reativado_em = :em, reativado_origem = :origem, reativado_por_id = :por
             WHERE ' . $escopo . ' AND d.email = :email AND d.reativado_em IS NULL' . $doAssunto,
            $parametros,
        );
    }

    /** A exceção: uma linha do assunto que já nasce reativada (libera o assunto sob um `todos` ativo). */
    public function registrarExcecao(?Tenant $tenant, string $email, string $assunto, string $origem, ?int $porId, \DateTimeImmutable $em): void
    {
        $this->conexao()->executeStatement(
            'INSERT INTO email_descadastro (tenant_id, email, assunto, origem, em, reativado_em, reativado_origem, reativado_por_id)
             VALUES (:tenant, :email, :assunto, :origem, :em, :em, :origem, :por)',
            ['tenant' => $tenant?->getId(), 'email' => $email, 'assunto' => $assunto, 'origem' => $origem, 'em' => self::data($em), 'por' => $porId],
        );
    }

    /**
     * Os descadastros ATIVOS do escopo, do mais recente para o mais antigo, com a busca no e-mail.
     *
     * @return array{total: int, linhas: list<array{email: string, assunto: string, origem: string, motivo: ?string, em: string}>}
     */
    public function listarAtivos(?Tenant $tenant, ?string $busca, int $pagina, int $porPagina): array
    {
        [$escopo, , $parametros] = $this->escopo($tenant, []);
        $onde = $escopo . ' AND d.reativado_em IS NULL';
        if ($busca !== null && trim($busca) !== '') {
            $onde .= " AND d.email ILIKE :busca ESCAPE '\\'";
            $parametros['busca'] = '%' . addcslashes(mb_strtolower(trim($busca)), '%_\\') . '%';
        }

        $total = (int) $this->conexao()->fetchOne('SELECT COUNT(*) FROM email_descadastro d WHERE ' . $onde, $parametros);
        /** @var list<array{email: string, assunto: string, origem: string, motivo: ?string, em: string}> $linhas */
        $linhas = $this->conexao()->fetchAllAssociative(
            'SELECT d.email, d.assunto, d.origem, d.motivo, d.em FROM email_descadastro d WHERE ' . $onde
            . ' ORDER BY d.em DESC, d.id DESC LIMIT ' . $porPagina . ' OFFSET ' . (($pagina - 1) * $porPagina),
            $parametros,
        );

        return ['total' => $total, 'linhas' => $linhas];
    }

    /**
     * O filtro do escopo para o alias `d` (e `x` na subconsulta), com o parâmetro do tenant quando há.
     *
     * @param array<string, mixed> $parametros
     *
     * @return array{0: string, 1: string, 2: array<string, mixed>}
     */
    private function escopo(?Tenant $tenant, array $parametros): array
    {
        if ($tenant === null) {
            return ['d.tenant_id IS NULL', 'x.tenant_id IS NULL', $parametros];
        }

        $parametros['tenant'] = $tenant->getId();

        return ['d.tenant_id = :tenant', 'x.tenant_id = :tenant', $parametros];
    }

    private function conexao(): \Doctrine\DBAL\Connection
    {
        return $this->getEntityManager()->getConnection();
    }

    private static function data(\DateTimeImmutable $em): string
    {
        return $em->format('Y-m-d H:i:s');
    }
}
