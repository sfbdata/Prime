<?php

declare(strict_types=1);

namespace App\Master\Repository;

use App\Entity\Auth\Invitation;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Permission\Permission;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Entity\Tenant\TenantRolePermission;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

/**
 * Consultas GLOBAIS do Painel do Master (spec perfis-master §4.3), SOMENTE LEITURA.
 *
 * Por que DBAL e não ORM: o painel atravessa todos os escritórios de propósito. SQL direto não passa
 * pelos filtros do ORM (TenantFilter, LixeiraFilter), então nenhum filtro precisa ser desligado e o
 * recorte de cada consulta fica explícito aqui. Nada neste arquivo grava: só SELECT.
 *
 * Quem pode chamar: apenas os UseCases do domínio Master, atrás de `ROLE_SUPER_ADMIN`.
 *
 * Recortes comuns:
 *  - escritório na quarentena (`tenant.excluido_em` preenchido) não conta em lugar nenhum;
 *  - pessoa = `user` ativo com pelo menos um vínculo ativo (`user_tenant.is_active`) em escritório
 *    fora da quarentena. Quem não tem vínculo (o Master sem escritório) não é usuário de escritório;
 *  - pasta na lixeira (`pasta.excluida_em`) e documento de pasta na lixeira (`excluido_em`) não contam.
 */
final class PainelMasterRepository
{
    /**
     * Tabelas com tamanho de arquivo no banco: [tabela, coluna do tamanho, coluna da data, SQL do tenant, filtro extra].
     * Justificativa de ponto e anexo de tarefa NÃO têm tamanho gravado: ficam fora (o painel diz "não medido").
     */
    private const TABELAS_DE_ARQUIVO = [
        ['pasta_documento', 'tamanho_bytes', 'uploaded_at', 'x.tenant_id', 'x.excluido_em IS NULL'],
        ['cliente_documento', 'tamanho_bytes', 'uploaded_at', 'x.tenant_id', null],
        ['documento_processo', 'tamanho', 'criado_em', 'x.tenant_id', null],
        ['cobranca_documento', 'tamanho_bytes', 'uploaded_at', 'x.tenant_id', null],
        ['cobranca_carteira_documento', 'tamanho_bytes', 'uploaded_at', 'x.tenant_id', null],
        ['cobranca_acordo_documento', 'tamanho_bytes', 'uploaded_at', 'x.tenant_id', null],
        ['chamado_anexo', 'tamanho', 'criado_em', '(SELECT c.tenant_id FROM chamado c WHERE c.id = x.chamado_id)', null],
        ['kanban_anexo', 'tamanho', 'criado_em', 'x.tenant_id', null],
    ];

    /** Entidades administrativas que entram na trilha "Segurança e auditoria". */
    public const ENTIDADES_AUDITADAS = [
        Tenant::class,
        User::class,
        UserTenant::class,
        TenantRole::class,
        TenantRolePermission::class,
        Permission::class,
        Invitation::class,
    ];

    public function __construct(
        private readonly Connection $conexao,
    ) {
    }

    /**
     * Escritórios fora da quarentena, com o nome do titular (`tenant.criado_por_id`).
     *
     * @return list<array{id: int, nome: string, cidade: ?string, estado: ?string, criado_em: string, ativo: bool, titular: ?string}>
     */
    public function escritorios(): array
    {
        $linhas = $this->conexao->fetchAllAssociative(
            'SELECT t.id, t.name AS nome, t.cidade, t.estado, t.created_at AS criado_em, t.is_active AS ativo,
                    u.full_name AS titular
               FROM tenant t
          LEFT JOIN "user" u ON u.id = t.criado_por_id
              WHERE t.excluido_em IS NULL
           ORDER BY t.name',
        );

        return array_map(static fn (array $l): array => [
            'id'        => (int) $l['id'],
            'nome'      => (string) $l['nome'],
            'cidade'    => $l['cidade'] !== null ? (string) $l['cidade'] : null,
            'estado'    => $l['estado'] !== null ? (string) $l['estado'] : null,
            'criado_em' => (string) $l['criado_em'],
            'ativo'     => (bool) $l['ativo'],
            'titular'   => $l['titular'] !== null ? (string) $l['titular'] : null,
        ], $linhas);
    }

    /**
     * Uma linha por VÍNCULO ativo de pessoa ativa em escritório fora da quarentena. O agrupamento
     * por pessoa (uma pessoa conta uma vez) é feito por quem consome ({@see pessoas()}).
     *
     * @return list<array<string, mixed>>
     */
    private function vinculosAtivos(): array
    {
        return $this->conexao->fetchAllAssociative(
            'SELECT u.id AS user_id, u.created_at AS criado_em, u.oab_numero, u.oab_uf, u.oab_status,
                    (SELECT MIN(up.data_nascimento) FROM user_profiles up WHERE up.user_id = u.id) AS nascimento,
                    ut.tenant_id, l.nome AS lotacao, COALESCE(tr.is_system, FALSE) AS primario,
                    ut.last_login_at AS ultimo_acesso
               FROM "user" u
               JOIN user_tenant ut ON ut.user_id = u.id AND ut.is_active = TRUE
               JOIN tenant t ON t.id = ut.tenant_id AND t.excluido_em IS NULL
          LEFT JOIN lotacao l ON l.id = ut.lotacao_id
          LEFT JOIN tenant_role tr ON tr.id = ut.tenant_role_id
              WHERE u.is_active = TRUE
           ORDER BY u.id, ut.tenant_id',
        );
    }

    /**
     * Pessoas contadas pelo painel, uma entrada por pessoa (nunca uma por vínculo). Sem nome, e-mail
     * nem CPF: o painel só conta.
     *
     * @return list<array{id: int, criadoEm: string, oabNumero: ?string, oabUf: ?string, oabStatus: ?string, nascimento: ?string, primario: bool, vinculos: list<array{tenantId: int, lotacao: string, primario: bool, ultimoAcesso: string}>}>
     */
    public function pessoas(): array
    {
        $pessoas = [];
        foreach ($this->vinculosAtivos() as $l) {
            $id = (int) $l['user_id'];
            if (!isset($pessoas[$id])) {
                $pessoas[$id] = [
                    'id'         => $id,
                    'criadoEm'   => (string) $l['criado_em'],
                    'oabNumero'  => self::textoOuNulo($l['oab_numero']),
                    'oabUf'      => self::textoOuNulo($l['oab_uf']),
                    'oabStatus'  => self::textoOuNulo($l['oab_status']),
                    'nascimento' => self::textoOuNulo($l['nascimento']),
                    'primario'   => false,
                    'vinculos'   => [],
                ];
            }

            $primario = (bool) $l['primario'];
            $pessoas[$id]['primario'] = $pessoas[$id]['primario'] || $primario;
            $pessoas[$id]['vinculos'][] = [
                'tenantId'     => (int) $l['tenant_id'],
                'lotacao'      => (string) ($l['lotacao'] ?? ''),
                'primario'     => $primario,
                'ultimoAcesso' => (string) ($l['ultimo_acesso'] ?? ''),
            ];
        }

        return array_values($pessoas);
    }

    /**
     * Pessoas por escritório (vínculos ativos de pessoas ativas) e último acesso de cada escritório.
     *
     * @return array<int, array{usuarios: int, ultimoAcesso: ?string}>
     */
    public function usuariosPorEscritorio(): array
    {
        $linhas = $this->conexao->fetchAllAssociative(
            'SELECT ut.tenant_id, COUNT(DISTINCT ut.user_id) AS usuarios, MAX(ut.last_login_at) AS ultimo_acesso
               FROM user_tenant ut
               JOIN "user" u ON u.id = ut.user_id AND u.is_active = TRUE
               JOIN tenant t ON t.id = ut.tenant_id AND t.excluido_em IS NULL
              WHERE ut.is_active = TRUE
           GROUP BY ut.tenant_id',
        );

        $saida = [];
        foreach ($linhas as $l) {
            $saida[(int) $l['tenant_id']] = [
                'usuarios'     => (int) $l['usuarios'],
                'ultimoAcesso' => $l['ultimo_acesso'] !== null ? (string) $l['ultimo_acesso'] : null,
            ];
        }

        return $saida;
    }

    /**
     * @return array<int, int> tenant_id => pastas fora da lixeira
     */
    public function pastasPorEscritorio(): array
    {
        $linhas = $this->conexao->fetchAllAssociative(
            'SELECT p.tenant_id, COUNT(*) AS n
               FROM pasta p
               JOIN tenant t ON t.id = p.tenant_id AND t.excluido_em IS NULL
              WHERE p.excluida_em IS NULL
           GROUP BY p.tenant_id',
        );

        $saida = [];
        foreach ($linhas as $l) {
            $saida[(int) $l['tenant_id']] = (int) $l['n'];
        }

        return $saida;
    }

    /**
     * Documentos e bytes por escritório, com os bytes enviados desde `$desde` (coluna "30 DIAS").
     *
     * @return array<int, array{documentos: int, bytes: int, bytesRecentes: int}>
     */
    public function consumoPorEscritorio(\DateTimeImmutable $desde): array
    {
        $linhas = $this->conexao->fetchAllAssociative(
            'SELECT a.tenant_id, COUNT(*) AS documentos, COALESCE(SUM(a.bytes), 0) AS bytes,
                    COALESCE(SUM(CASE WHEN a.criado >= :desde THEN a.bytes ELSE 0 END), 0) AS bytes_recentes
               FROM (' . self::arquivosComTamanho() . ') a
               JOIN tenant t ON t.id = a.tenant_id AND t.excluido_em IS NULL
           GROUP BY a.tenant_id',
            ['desde' => $desde->format('Y-m-d H:i:s')],
        );

        $saida = [];
        foreach ($linhas as $l) {
            $saida[(int) $l['tenant_id']] = [
                'documentos'    => (int) $l['documentos'],
                'bytes'         => (int) $l['bytes'],
                'bytesRecentes' => (int) $l['bytes_recentes'],
            ];
        }

        return $saida;
    }

    /**
     * Entradas por mês ('YYYY-MM' => quantidade) de cada métrica do gráfico "Crescimento".
     * Usuários entram pela data de criação da pessoa (`user.created_at`); GB pela data de envio de
     * cada arquivo com tamanho no banco.
     *
     * @return array{contas: array<string, float>, usuarios: array<string, float>, pastas: array<string, float>, bytes: array<string, float>}
     */
    public function entradasPorMes(): array
    {
        $porMes = function (string $sql): array {
            $saida = [];
            foreach ($this->conexao->fetchAllAssociative($sql) as $l) {
                $saida[(string) $l['mes']] = (float) $l['n'];
            }

            return $saida;
        };

        return [
            'contas' => $porMes(
                "SELECT to_char(t.created_at, 'YYYY-MM') AS mes, COUNT(*) AS n
                   FROM tenant t WHERE t.excluido_em IS NULL GROUP BY 1",
            ),
            'usuarios' => $porMes(
                "SELECT to_char(u.created_at, 'YYYY-MM') AS mes, COUNT(*) AS n
                   FROM \"user\" u
                  WHERE u.is_active = TRUE
                    AND EXISTS (SELECT 1 FROM user_tenant ut JOIN tenant t ON t.id = ut.tenant_id AND t.excluido_em IS NULL
                                 WHERE ut.user_id = u.id AND ut.is_active = TRUE)
               GROUP BY 1",
            ),
            'pastas' => $porMes(
                "SELECT to_char(p.created_at, 'YYYY-MM') AS mes, COUNT(*) AS n
                   FROM pasta p JOIN tenant t ON t.id = p.tenant_id AND t.excluido_em IS NULL
                  WHERE p.excluida_em IS NULL GROUP BY 1",
            ),
            'bytes' => $porMes(
                "SELECT to_char(a.criado, 'YYYY-MM') AS mes, SUM(a.bytes) AS n
                   FROM (" . self::arquivosComTamanho() . ") a
                   JOIN tenant t ON t.id = a.tenant_id AND t.excluido_em IS NULL
                  WHERE a.criado IS NOT NULL
               GROUP BY 1",
            ),
        ];
    }

    /**
     * Usuários com `ROLE_SUPER_ADMIN` (o Master). `roles` é json: a busca é textual pelo nome exato
     * entre aspas, como o Symfony grava.
     *
     * @return list<array{id: int, nome: string, email: string, oabNumero: ?string, oabUf: ?string}>
     */
    public function masters(): array
    {
        $linhas = $this->conexao->fetchAllAssociative(
            'SELECT u.id, u.full_name, u.email, u.oab_numero, u.oab_uf
               FROM "user" u
              WHERE u.roles::text LIKE :papel
           ORDER BY u.id',
            ['papel' => '%"ROLE_SUPER_ADMIN"%'],
        );

        return array_map(static fn (array $l): array => [
            'id'        => (int) $l['id'],
            'nome'      => (string) $l['full_name'],
            'email'     => (string) $l['email'],
            'oabNumero' => self::textoOuNulo($l['oab_numero']),
            'oabUf'     => self::textoOuNulo($l['oab_uf']),
        ], $linhas);
    }

    /**
     * Trilha administrativa do `audit_log` (cross-tenant), mais recente primeiro.
     *
     * Fica de fora a atualização de vínculo que só mexe em `lastLoginAt`/`updatedAt`: é o carimbo de
     * acesso gravado a cada login, não uma alteração administrativa (e afogaria a trilha).
     *
     * @return list<array{id: int, acao: string, entidade: string, entidadeId: ?string, mudancas: ?string, tenantId: ?int, conta: ?string, responsavel: ?string, responsavelEmail: ?string, criadoEm: string}>
     */
    public function trilhaAdministrativa(int $limite): array
    {
        $linhas = $this->conexao->fetchAllAssociative(
            "SELECT a.id, a.action, a.entity_class, a.entity_id, a.changes::text AS changes, a.tenant_id,
                    t.name AS conta, u.full_name AS responsavel, a.actor_email, a.created_at
               FROM audit_log a
          LEFT JOIN tenant t ON t.id = a.tenant_id
          LEFT JOIN \"user\" u ON u.id = a.actor_user_id
              WHERE (a.entity_class IN (:entidades) OR a.action = 'purge')
                AND NOT (
                    a.entity_class = :vinculo AND a.action = 'update'
                    AND COALESCE(((a.changes::jsonb #> '{diff,changes}') - 'lastLoginAt' - 'updatedAt') = '{}'::jsonb, FALSE)
                )
           ORDER BY a.created_at DESC, a.id DESC
              LIMIT :limite",
            ['entidades' => self::ENTIDADES_AUDITADAS, 'vinculo' => UserTenant::class, 'limite' => $limite],
            ['entidades' => ArrayParameterType::STRING, 'limite' => ParameterType::INTEGER],
        );

        return array_map(static fn (array $l): array => [
            'id'               => (int) $l['id'],
            'acao'             => (string) $l['action'],
            'entidade'         => (string) $l['entity_class'],
            'entidadeId'       => self::textoOuNulo($l['entity_id']),
            'mudancas'         => self::textoOuNulo($l['changes']),
            'tenantId'         => $l['tenant_id'] !== null ? (int) $l['tenant_id'] : null,
            'conta'            => self::textoOuNulo($l['conta']),
            'responsavel'      => self::textoOuNulo($l['responsavel']),
            'responsavelEmail' => self::textoOuNulo($l['actor_email']),
            'criadoEm'         => (string) $l['created_at'],
        ], $linhas);
    }

    /**
     * Subconsulta com uma linha por arquivo com tamanho no banco: (tenant_id, bytes, criado).
     */
    private static function arquivosComTamanho(): string
    {
        $partes = [];
        foreach (self::TABELAS_DE_ARQUIVO as [$tabela, $tamanho, $data, $tenant, $filtro]) {
            $partes[] = sprintf(
                'SELECT %s AS tenant_id, COALESCE(x.%s, 0)::bigint AS bytes, x.%s AS criado FROM %s x%s',
                $tenant,
                $tamanho,
                $data,
                $tabela,
                $filtro !== null ? ' WHERE ' . $filtro : '',
            );
        }

        return implode(' UNION ALL ', $partes);
    }

    private static function textoOuNulo(mixed $valor): ?string
    {
        if ($valor === null) {
            return null;
        }

        $texto = trim((string) $valor);

        return $texto === '' ? null : $texto;
    }
}
