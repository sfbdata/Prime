<?php

declare(strict_types=1);

namespace App\Master\Repository;

use App\Contribua\Entity\ConfiguracaoDoEspaco;
use App\Contribua\Entity\EspacoDoEscritorio;
use App\Contribua\Entity\Plano;
use App\Contribua\Event\EspacoAtingiuFaixa;
use App\Entity\Auth\Invitation;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Permission\Permission;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Entity\Tenant\TenantRolePermission;
use App\Master\Entity\DivulgacaoNaSeccional;
use App\Master\Entity\NotaDaCidadeOab;
use App\Master\Entity\NotaDaSeccional;
use App\Master\Entity\RecursoEstado;
use App\Master\Entity\SeccionalOab;
use App\Master\Entity\SubsecaoOab;
use App\Master\Service\RegraDosMasters;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

/**
 * Consultas GLOBAIS do Painel do Master (spec perfis-master §4.3), mais a gravação do "Alerta de consumo".
 *
 * Por que DBAL e não ORM: o painel atravessa todos os escritórios de propósito. SQL direto não passa
 * pelos filtros do ORM (TenantFilter, LixeiraFilter), então nenhum filtro precisa ser desligado e o
 * recorte de cada consulta fica explícito aqui. Tudo é SELECT, com UMA exceção:
 * {@see registrarAlertaDeConsumo()}, o INSERT da linha do Sistema no `audit_log` (spec
 * perfis-1.2-planos-e-limites P11).
 *
 * Quem pode chamar: as consultas, só os UseCases do domínio Master, atrás de `ROLE_SUPER_ADMIN`; a
 * gravação do alerta, só o ouvinte {@see \App\Master\EventListener\AlertaDeConsumoNaTrilha}, que roda
 * no envio de arquivo de qualquer escritório (sem Master na sessão).
 *
 * Recortes comuns:
 *  - escritório na quarentena (`tenant.excluido_em` preenchido) não conta em lugar nenhum;
 *  - pessoa = `user` ativo com pelo menos um vínculo ativo (`user_tenant.is_active`) em escritório
 *    fora da quarentena. Quem não tem vínculo (o Master sem escritório) não é usuário de escritório;
 *  - pasta na lixeira (`pasta.excluida_em`) e documento de pasta na lixeira (`excluido_em`) não contam.
 */
// Não-final: o teste do ouvinte do alerta dubla a gravação (padrão dos repositórios, skill criar-repository).
class PainelMasterRepository
{
    /**
     * Tabelas com tamanho de arquivo no banco: [tabela, coluna do tamanho, coluna da data, SQL do tenant, filtro extra].
     * Justificativa de ponto e anexo de tarefa NÃO têm tamanho gravado: ficam fora (o painel diz "não medido").
     * `documento_processo` também fica fora: a tabela não tem escritor (vazia em produção) e citar ela
     * derruba `DocumentoProcessoSemEscritorTest`, a guarda que obriga rever a purga no dia em que
     * alguém passar a gravar nela. Quando isso acontecer, ela entra aqui junto.
     */
    private const TABELAS_DE_ARQUIVO = [
        ['pasta_documento', 'tamanho_bytes', 'uploaded_at', 'x.tenant_id', 'x.excluido_em IS NULL'],
        ['cliente_documento', 'tamanho_bytes', 'uploaded_at', 'x.tenant_id', null],
        ['cobranca_documento', 'tamanho_bytes', 'uploaded_at', 'x.tenant_id', null],
        ['cobranca_carteira_documento', 'tamanho_bytes', 'uploaded_at', 'x.tenant_id', null],
        ['cobranca_acordo_documento', 'tamanho_bytes', 'uploaded_at', 'x.tenant_id', null],
        ['chamado_anexo', 'tamanho', 'criado_em', '(SELECT c.tenant_id FROM chamado c WHERE c.id = x.chamado_id)', null],
        ['kanban_anexo', 'tamanho', 'criado_em', 'x.tenant_id', null],
    ];

    /**
     * Espaço cobrado x espaço que ocuparia se o conteúdo repetido fosse guardado uma vez só (desenho 05 de 27/10,
     * faixa de Armazenamento). Cobrado: soma de cada arquivo em cada pasta, o mesmo número do KPI de GB. Único:
     * arquivos com o mesmo `sha256` (hoje só em `pasta_documento`, entre todos os escritórios) contam uma vez; arquivo
     * sem impressão digital conta como único. Escritório excluído fica fora, como no consumo.
     *
     * @return array{cobrado: int, fisico: int, vinculos: int, arquivos: int}
     */
    public function duplicidadeDeArquivos(): array
    {
        $linha = $this->conexao->fetchAssociative(
            'WITH a AS (SELECT u.bytes, u.sha256
                          FROM (' . self::arquivosComTamanho() . ') u
                          JOIN tenant t ON t.id = u.tenant_id AND t.excluido_em IS NULL)
             SELECT COALESCE(SUM(bytes), 0) AS cobrado, COUNT(*) AS vinculos,
                    COUNT(DISTINCT sha256) + COUNT(*) FILTER (WHERE sha256 IS NULL) AS arquivos,
                    COALESCE(SUM(bytes) FILTER (WHERE sha256 IS NULL), 0)
                      + COALESCE((SELECT SUM(m) FROM (SELECT MAX(bytes) AS m FROM a WHERE sha256 IS NOT NULL GROUP BY sha256) z), 0) AS fisico
               FROM a',
        );

        return [
            'cobrado'  => (int) ($linha['cobrado'] ?? 0),
            'fisico'   => (int) ($linha['fisico'] ?? 0),
            'vinculos' => (int) ($linha['vinculos'] ?? 0),
            'arquivos' => (int) ($linha['arquivos'] ?? 0),
        ];
    }

    /**
     * Entidades administrativas que entram na trilha "Segurança e auditoria". As três da Contribua são os
     * limites da conta, o catálogo de planos e as faixas de alerta (spec perfis-1.2-planos-e-limites P7).
     * As duas das seccionais da OAB são a sede editada e a divulgação registrada pelo Master (spec
     * master-seccionais-oab), gravadas sem escritório ("Plataforma (sem conta)"). A subseção e a nota da janela da
     * OAB (spec master-oab-estados-que-faltam), do estado e da capital, entram do mesmo jeito. A visibilidade no mapa e a
     * marca da divulgação (desenho 05) gravam na própria seccional e aparecem como "Sede da seccional alterada", com os
     * campos alterados.
     */
    public const ENTIDADES_AUDITADAS = [
        Tenant::class,
        User::class,
        UserTenant::class,
        TenantRole::class,
        TenantRolePermission::class,
        Permission::class,
        Invitation::class,
        EspacoDoEscritorio::class,
        Plano::class,
        ConfiguracaoDoEspaco::class,
        RecursoEstado::class,
        SeccionalOab::class,
        DivulgacaoNaSeccional::class,
        SubsecaoOab::class,
        NotaDaSeccional::class,
        NotaDaCidadeOab::class,
    ];

    /** Linha própria do alerta de consumo (`action` cabe em 10 caracteres). */
    public const ACAO_ALERTA_DE_CONSUMO = 'alerta';
    public const ENTIDADE_ALERTA_DE_CONSUMO = EspacoAtingiuFaixa::class;

    /**
     * Filtro da trilha: entidades administrativas, purga e alerta de consumo, na janela `:desde`, sem o
     * carimbo de login do vínculo (só `lastLoginAt`/`updatedAt`), que afogaria a trilha.
     */
    private const FILTRO_DA_TRILHA = "(a.entity_class IN (:entidades) OR a.action IN ('purge', '" . self::ACAO_ALERTA_DE_CONSUMO . "'))
                AND a.created_at >= :desde
                AND NOT (
                    a.entity_class = :vinculo AND a.action = 'update'
                    AND COALESCE(((a.changes::jsonb #> '{diff,changes}') - 'lastLoginAt' - 'updatedAt') = '{}'::jsonb, FALSE)
                )";

    public function __construct(
        private readonly Connection $conexao,
    ) {
    }

    /**
     * Escritórios fora da quarentena, com o nome do titular (`tenant.criado_por_id`) e se é a conta do
     * Master (`do_master`, spec perfis-1.2-contas C5: não se suspende nem exclui). Mesma regra de
     * RegraDosMasters::ehContaDoMaster(): titular Master; sem titular, algum Master com vínculo ativo.
     *
     * @return list<array{id: int, nome: string, cidade: ?string, estado: ?string, criado_em: string, ativo: bool, titular: ?string, do_master: bool, excluido_em: ?string}>
     */
    public function escritorios(): array
    {
        return $this->escritoriosOnde('t.excluido_em IS NULL');
    }

    /**
     * Escritórios NA quarentena (excluídos e ainda não purgados), para a opção "Em quarentena" da lista
     * de contas (spec perfis-1.2-contas C4b). Mesmo formato de {@see escritorios()}, com `excluido_em`.
     * Só esta consulta enxerga a quarentena; as contagens do painel continuam fora dela.
     *
     * @return list<array{id: int, nome: string, cidade: ?string, estado: ?string, criado_em: string, ativo: bool, titular: ?string, do_master: bool, excluido_em: ?string}>
     */
    public function escritoriosEmQuarentena(): array
    {
        return $this->escritoriosOnde('t.excluido_em IS NOT NULL');
    }

    /**
     * @param string $filtro recorte FIXO da quarentena (nunca dado de fora): um dos dois acima
     *
     * @return list<array{id: int, nome: string, cidade: ?string, estado: ?string, criado_em: string, ativo: bool, titular: ?string, do_master: bool, excluido_em: ?string}>
     */
    private function escritoriosOnde(string $filtro): array
    {
        $linhas = $this->conexao->fetchAllAssociative(
            'SELECT t.id, t.name AS nome, t.cidade, t.estado, t.created_at AS criado_em, t.is_active AS ativo,
                    t.excluido_em, u.full_name AS titular,
                    CASE WHEN u.id IS NOT NULL THEN u.roles::text LIKE :papel
                         ELSE EXISTS (SELECT 1 FROM user_tenant ut JOIN "user" m ON m.id = ut.user_id
                                       WHERE ut.tenant_id = t.id AND ut.is_active = TRUE AND m.roles::text LIKE :papel)
                    END AS do_master
               FROM tenant t
          LEFT JOIN "user" u ON u.id = t.criado_por_id
              WHERE ' . $filtro . '
           ORDER BY t.name',
            ['papel' => '%"' . RegraDosMasters::PAPEL_MASTER . '"%'],
        );

        return array_map(static fn (array $l): array => [
            'id'          => (int) $l['id'],
            'nome'        => (string) $l['nome'],
            'cidade'      => $l['cidade'] !== null ? (string) $l['cidade'] : null,
            'estado'      => $l['estado'] !== null ? (string) $l['estado'] : null,
            'criado_em'   => (string) $l['criado_em'],
            'ativo'       => (bool) $l['ativo'],
            'titular'     => $l['titular'] !== null ? (string) $l['titular'] : null,
            'do_master'   => (bool) $l['do_master'],
            'excluido_em' => $l['excluido_em'] !== null ? (string) $l['excluido_em'] : null,
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
     * Saídas da equipe no período `[$desde, $ate)` (tabela `saida_da_equipe`, gravada pela remoção do vínculo; spec
     * saida-da-equipe), para a seta de tendência do mapa (spec perfis-1.2-planos-e-limites §5, C6). Só leitura.
     *
     * Mesmo critério de escritório das entradas ({@see vinculosAtivos()}): escritório na quarentena fica fora;
     * suspenso conta. O `user` vem por LEFT JOIN: `user_id` fica nulo quando o usuário é apagado (SET NULL), e aí
     * a UF é a do escritório. Sem nome, e-mail nem CPF: só o necessário para localizar e contar.
     *
     * Fuso: `saiu_em` é gravado na hora local, sem fuso (`TIMESTAMP WITHOUT TIME ZONE`, `new \DateTimeImmutable()`
     * no fuso padrão do PHP). As bordas vêm do relógio do sistema e são formatadas no MESMO fuso padrão, para a
     * comparação valer mesmo se o relógio entregar outro fuso.
     *
     * @return list<array{saiuEm: string, eraPrimario: bool, tenantId: int, userId: ?int, oabUf: ?string, estado: ?string}>
     */
    public function saidasRecentes(\DateTimeImmutable $desde, \DateTimeImmutable $ate): array
    {
        $fuso = new \DateTimeZone(date_default_timezone_get());
        $linhas = $this->conexao->fetchAllAssociative(
            'SELECT s.saiu_em, s.era_primario, s.tenant_id, s.user_id, u.oab_uf, t.estado
               FROM saida_da_equipe s
               JOIN tenant t ON t.id = s.tenant_id AND t.excluido_em IS NULL
          LEFT JOIN "user" u ON u.id = s.user_id
              WHERE s.saiu_em >= :desde AND s.saiu_em < :ate
           ORDER BY s.saiu_em, s.id',
            [
                'desde' => $desde->setTimezone($fuso)->format('Y-m-d H:i:s'),
                'ate'   => $ate->setTimezone($fuso)->format('Y-m-d H:i:s'),
            ],
        );

        return array_map(static fn (array $l): array => [
            'saiuEm'      => (string) $l['saiu_em'],
            'eraPrimario' => (bool) $l['era_primario'],
            'tenantId'    => (int) $l['tenant_id'],
            'userId'      => $l['user_id'] !== null ? (int) $l['user_id'] : null,
            'oabUf'       => self::textoOuNulo($l['oab_uf']),
            'estado'      => self::textoOuNulo($l['estado']),
        ], $linhas);
    }

    /**
     * Pessoas por escritório (vínculos ativos de pessoas ativas) e último acesso de cada escritório.
     *
     * @return array<int, array{usuarios: int, ultimoAcesso: ?string}>
     */
    public function usuariosPorEscritorio(): array
    {
        return $this->usuariosOnde('t.excluido_em IS NULL');
    }

    /**
     * A mesma contagem para as contas EM QUARENTENA (spec perfis-1.2-contas C4b): os vínculos continuam
     * até a purga, e a lista "Em quarentena" mostra quantas pessoas a conta ainda tem.
     *
     * @return array<int, array{usuarios: int, ultimoAcesso: ?string}>
     */
    public function usuariosPorEscritorioEmQuarentena(): array
    {
        return $this->usuariosOnde('t.excluido_em IS NOT NULL');
    }

    /**
     * @param string $filtro recorte FIXO da quarentena (nunca dado de fora): um dos dois acima
     *
     * @return array<int, array{usuarios: int, ultimoAcesso: ?string}>
     */
    private function usuariosOnde(string $filtro): array
    {
        $linhas = $this->conexao->fetchAllAssociative(
            'SELECT ut.tenant_id, COUNT(DISTINCT ut.user_id) AS usuarios, MAX(ut.last_login_at) AS ultimo_acesso
               FROM user_tenant ut
               JOIN "user" u ON u.id = ut.user_id AND u.is_active = TRUE
               JOIN tenant t ON t.id = ut.tenant_id AND ' . $filtro . '
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
     * Documento de pasta em lápide (`pasta.excluida_em`) não conta como documento, mas os bytes contam:
     * o arquivo continua guardado.
     *
     * @return array<int, array{documentos: int, bytes: int, bytesRecentes: int}>
     */
    public function consumoPorEscritorio(\DateTimeImmutable $desde): array
    {
        $linhas = $this->conexao->fetchAllAssociative(
            'SELECT a.tenant_id, COALESCE(SUM(a.conta), 0) AS documentos, COALESCE(SUM(a.bytes), 0) AS bytes,
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
     * Masters do sistema (`ROLE_SUPER_ADMIN`), o primário (`ROLE_MASTER_PRIMARIO`) primeiro, depois
     * os secundários por nome (spec perfis-1.2-masters §4.1). `roles` é json: a busca é textual pelo
     * nome exato entre aspas, como o Symfony grava.
     *
     * @return list<array{id: int, nome: string, email: string, oabNumero: ?string, oabUf: ?string, primario: bool}>
     */
    public function masters(): array
    {
        $linhas = $this->conexao->fetchAllAssociative(
            'SELECT u.id, u.full_name, u.email, u.oab_numero, u.oab_uf,
                    (u.roles::text LIKE :primario) AS primario
               FROM "user" u
              WHERE u.roles::text LIKE :papel
           ORDER BY primario DESC, u.full_name, u.id',
            [
                'papel'    => '%"' . RegraDosMasters::PAPEL_MASTER . '"%',
                'primario' => '%"' . RegraDosMasters::PAPEL_MASTER_PRIMARIO . '"%',
            ],
        );

        return array_map(static fn (array $l): array => [
            'id'        => (int) $l['id'],
            'nome'      => (string) $l['full_name'],
            'email'     => (string) $l['email'],
            'oabNumero' => self::textoOuNulo($l['oab_numero']),
            'oabUf'     => self::textoOuNulo($l['oab_uf']),
            'primario'  => (bool) $l['primario'],
        ], $linhas);
    }

    /**
     * Quem pode ser promovido a Master secundário (spec perfis-1.2-masters M5): vínculo ATIVO com o
     * escritório informado (o da sessão do Master), qualquer perfil (o primário do escritório entra, com o
     * nome exibido "Usuário Primário"; decisão do dono em 29/10), conta ativa e ainda sem `ROLE_SUPER_ADMIN`. Recorte por escritório:
     * nada de outro escritório entra na lista.
     *
     * @return list<array{id: int, nome: string, email: string, perfil: ?string, oabNumero: ?string, oabUf: ?string}>
     */
    public function candidatosAMaster(int $tenantId): array
    {
        $linhas = $this->conexao->fetchAllAssociative(
            'SELECT u.id, u.full_name, u.email, u.oab_numero, u.oab_uf,
                    CASE WHEN tr.is_system THEN :primario ELSE tr.name END AS perfil
               FROM user_tenant ut
               JOIN "user" u ON u.id = ut.user_id
          LEFT JOIN tenant_role tr ON tr.id = ut.tenant_role_id
              WHERE ut.tenant_id = :tenant
                AND ut.is_active = TRUE
                AND u.is_active = TRUE
                AND u.roles::text NOT LIKE :papel
           ORDER BY u.full_name, u.id',
            ['tenant' => $tenantId, 'primario' => TenantRole::NOME_EXIBIDO_PRIMARIO, 'papel' => '%"' . RegraDosMasters::PAPEL_MASTER . '"%'],
            ['tenant' => ParameterType::INTEGER],
        );

        return array_map(static fn (array $l): array => [
            'id'        => (int) $l['id'],
            'nome'      => (string) $l['full_name'],
            'email'     => (string) $l['email'],
            'perfil'    => self::textoOuNulo($l['perfil']),
            'oabNumero' => self::textoOuNulo($l['oab_numero']),
            'oabUf'     => self::textoOuNulo($l['oab_uf']),
        ], $linhas);
    }

    /**
     * Quantas linhas a trilha mostraria (até `$limite`): o selo do botão "Segurança e auditoria" desde a
     * abertura do painel (desenho 1.2, linha 678; spec perfis-1.2-planos-e-limites P10), sem trazer as linhas.
     */
    public function contarTrilhaAdministrativa(int $limite, \DateTimeImmutable $desde): int
    {
        return (int) $this->conexao->fetchOne(
            'SELECT COUNT(*) FROM (SELECT 1 FROM audit_log a WHERE ' . self::FILTRO_DA_TRILHA . ' LIMIT :limite) x',
            ['entidades' => self::ENTIDADES_AUDITADAS, 'vinculo' => UserTenant::class, 'limite' => $limite, 'desde' => $desde->format('Y-m-d H:i:s')],
            ['entidades' => ArrayParameterType::STRING, 'limite' => ParameterType::INTEGER],
        );
    }

    /**
     * Grava o "Alerta de consumo" do Sistema na trilha (desenho 1.2, linhas 481 e 700; spec P11): sem ator
     * (aparece como "Sistema"), com a conta do evento. SQL direto, como a linha da purga: o ouvinte roda
     * depois da resposta e não pode dar flush em nada que tenha ficado pendente no EntityManager.
     */
    public function registrarAlertaDeConsumo(int $tenantId, int $limiar, ?int $percentual, string $faixa): void
    {
        $this->conexao->insert('audit_log', [
            'action'       => self::ACAO_ALERTA_DE_CONSUMO,
            'entity_class' => self::ENTIDADE_ALERTA_DE_CONSUMO,
            'entity_id'    => (string) $tenantId,
            'changes'      => json_encode(['limiar' => $limiar, 'percentual' => $percentual, 'faixa' => $faixa], \JSON_THROW_ON_ERROR),
            'tenant_id'    => $tenantId,
            'created_at'   => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Trilha administrativa do `audit_log` (cross-tenant), mais recente primeiro.
     *
     * Janela `$desde` (o painel usa 12 meses) para a varredura pelo índice de `created_at` parar cedo
     * mesmo quando há menos de `$limite` linhas administrativas no período.
     *
     * Fica de fora a atualização de vínculo que só mexe em `lastLoginAt`/`updatedAt`: é o carimbo de
     * acesso gravado a cada login, não uma alteração administrativa (e afogaria a trilha).
     *
     * `recurso` é a chave da tela ou função ligada/desligada (linha de RecursoEstado): a alteração só
     * grava os campos mudados, e a chave nunca muda, então ela vem da própria `recurso_estado`.
     *
     * @return list<array{id: int, acao: string, entidade: string, entidadeId: ?string, mudancas: ?string, tenantId: ?int, conta: ?string, responsavel: ?string, responsavelId: ?int, criadoEm: string, recurso: ?string}>
     */
    public function trilhaAdministrativa(int $limite, \DateTimeImmutable $desde): array
    {
        $linhas = $this->conexao->fetchAllAssociative(
            "SELECT a.id, a.action, a.entity_class, a.entity_id, a.changes::text AS changes, a.tenant_id,
                    t.name AS conta, u.full_name AS responsavel, a.actor_user_id, a.created_at, re.chave AS recurso
               FROM audit_log a
          LEFT JOIN tenant t ON t.id = a.tenant_id
          LEFT JOIN \"user\" u ON u.id = a.actor_user_id
          LEFT JOIN recurso_estado re ON a.entity_class = :recurso AND re.id::text = a.entity_id
              WHERE " . self::FILTRO_DA_TRILHA . "
           ORDER BY a.created_at DESC, a.id DESC
              LIMIT :limite",
            ['entidades' => self::ENTIDADES_AUDITADAS, 'vinculo' => UserTenant::class, 'recurso' => RecursoEstado::class, 'limite' => $limite, 'desde' => $desde->format('Y-m-d H:i:s')],
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
            'responsavelId'    => $l['actor_user_id'] !== null ? (int) $l['actor_user_id'] : null,
            'criadoEm'         => (string) $l['created_at'],
            'recurso'          => self::textoOuNulo($l['recurso']),
        ], $linhas);
    }

    /**
     * Subconsulta com uma linha por arquivo com tamanho no banco: (tenant_id, bytes, criado).
     */
    private static function arquivosComTamanho(): string
    {
        $partes = [];
        foreach (self::TABELAS_DE_ARQUIVO as [$tabela, $tamanho, $data, $tenant, $filtro]) {
            $comHash  = str_starts_with($tabela, 'pasta_documento');
            $partes[] = sprintf(
                'SELECT %s AS tenant_id, COALESCE(x.%s, 0)::bigint AS bytes, x.%s AS criado, %s AS conta, %s AS sha256 FROM %s x%s',
                $tenant,
                $tamanho,
                $data,
                $tabela === 'pasta_documento' ? 'CASE WHEN EXISTS (SELECT 1 FROM pasta p WHERE p.id = x.pasta_id AND p.excluida_em IS NOT NULL) THEN 0 ELSE 1 END' : '1',
                $comHash ? 'x.sha256' : 'NULL::char(64)',
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
