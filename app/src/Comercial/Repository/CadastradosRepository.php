<?php

declare(strict_types=1);

namespace App\Comercial\Repository;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Os cadastrados do sistema como públicos de campanha (spec §8.5), por SQL de leitura. Atravessa os escritórios de
 * propósito: é o Master quem lê (rota `ROLE_SUPER_ADMIN`) e o público é da plataforma. Vale só quem tem vínculo ativo,
 * usuário ativo e escritório nem suspenso (`tenant.is_active = false`) nem excluído (`tenant.excluido_em`).
 *
 * Primário = vínculo com o papel de sistema (como `UserTenantRepository::findUsuariosAdminsAtivos`). Secundário =
 * vínculo sem papel de sistema, nas mesmas condições, mesmo que a pessoa seja primária em outro escritório (a letra da
 * spec §8.5); "Todos os cadastrados" é a união dos dois, sem repetidos (quem resolve o público tira os repetidos).
 *
 * O B1 ({@see self::ativosEntre()}) usa o mesmo vínculo válido: quem só está num escritório excluído (quarentena) ou
 * suspenso, ou não tem vínculo ativo, não conta como "usuário ativo cadastrado".
 */
class CadastradosRepository
{
    private const VINCULO_VALIDO = 'FROM "user" u
        JOIN user_tenant ut ON ut.user_id = u.id AND ut.is_active = true
        JOIN tenant t ON t.id = ut.tenant_id AND t.is_active = true AND t.excluido_em IS NULL
        LEFT JOIN tenant_role r ON r.id = ut.tenant_role_id
        WHERE u.is_active = true';

    public function __construct(private readonly Connection $conexao)
    {
    }

    /** @return list<array{email: string, nome: string}> do cadastro mais antigo ao mais novo */
    public function primarios(): array
    {
        return $this->lista('AND r.is_system = true');
    }

    /** @return list<array{email: string, nome: string}> */
    public function secundarios(): array
    {
        return $this->lista('AND (r.id IS NULL OR r.is_system = false)');
    }

    /**
     * B1: quais destes endereços são de usuário ativo cadastrado, com o mesmo vínculo válido dos públicos de sistema.
     *
     * @param list<string> $emails já em minúsculas
     *
     * @return list<string>
     */
    public function ativosEntre(array $emails): array
    {
        if ($emails === []) {
            return [];
        }

        return array_map('strval', $this->conexao->fetchFirstColumn(
            'SELECT DISTINCT LOWER(u.email) ' . self::VINCULO_VALIDO . ' AND LOWER(u.email) IN (:emails)',
            ['emails' => $emails],
            ['emails' => ArrayParameterType::STRING],
        ));
    }

    /**
     * Os vínculos válidos (o mesmo `VINCULO_VALIDO` dos públicos) destes endereços, um por linha, para o filtro da
     * campanha (§9): papel, escritório, lotação, último acesso, a OAB da pessoa e o estado do escritório.
     *
     * @param list<string> $emails já em minúsculas
     *
     * @return list<array{email: string, userId: int, tenantId: int, primario: bool, lotacao: string, ultimoAcesso: string, oabNumero: ?string, oabUf: ?string, oabStatus: ?string, estado: ?string}>
     */
    public function vinculosDe(array $emails): array
    {
        if ($emails === []) {
            return [];
        }
        $linhas = $this->conexao->fetchAllAssociative(
            'SELECT LOWER(u.email) AS email, u.id AS user_id, ut.tenant_id, (r.is_system IS TRUE) AS primario,
                    COALESCE(l.nome, \'\') AS lotacao, COALESCE(TO_CHAR(ut.last_login_at, \'YYYY-MM-DD HH24:MI:SS\'), \'\') AS ultimo,
                    u.oab_numero, u.oab_uf, u.oab_status, t.estado '
            . str_replace('LEFT JOIN tenant_role r', 'LEFT JOIN lotacao l ON l.id = ut.lotacao_id LEFT JOIN tenant_role r', self::VINCULO_VALIDO)
            . ' AND LOWER(u.email) IN (:emails) ORDER BY u.id, ut.tenant_id',
            ['emails' => $emails],
            ['emails' => ArrayParameterType::STRING],
        );

        return array_map(static fn (array $l): array => [
            'email' => (string) $l['email'], 'userId' => (int) $l['user_id'], 'tenantId' => (int) $l['tenant_id'],
            'primario' => (bool) $l['primario'], 'lotacao' => (string) $l['lotacao'], 'ultimoAcesso' => (string) $l['ultimo'],
            'oabNumero' => self::ouNulo($l['oab_numero']), 'oabUf' => self::ouNulo($l['oab_uf']),
            'oabStatus' => self::ouNulo($l['oab_status']), 'estado' => self::ouNulo($l['estado']),
        ], $linhas);
    }

    /** @return list<array{email: string, nome: string}> */
    private function lista(string $filtro): array
    {
        $linhas = $this->conexao->fetchAllAssociative(
            'SELECT LOWER(u.email) AS email, MIN(u.full_name) AS nome ' . self::VINCULO_VALIDO . ' ' . $filtro
            . ' GROUP BY LOWER(u.email) ORDER BY MIN(u.id)',
        );

        return array_map(static fn (array $l): array => ['email' => (string) $l['email'], 'nome' => (string) $l['nome']], $linhas);
    }

    private static function ouNulo(mixed $valor): ?string
    {
        $texto = trim((string) $valor);

        return $texto === '' ? null : $texto;
    }
}
