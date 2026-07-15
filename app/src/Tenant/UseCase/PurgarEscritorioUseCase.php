<?php

declare(strict_types=1);

namespace App\Tenant\UseCase;

use App\Entity\Tenant\Tenant;
use App\Shared\Service\ArquivoStorageInterface;
use App\Tenant\DTO\PurgaEscritorioResultado;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Purga definitiva (hard delete) de um escritório em quarentena — a etapa final do
 * ciclo soft-delete → quarentena → purga (RN09). Apaga o Tenant e TUDO que pertence a
 * ele, sem tocar em dado de outro escritório e sem apagar entidades compartilhadas.
 *
 * Disparado pelo job de manutenção (sem request/usuário no contexto). Pontos de risco
 * ALTO tratados aqui:
 *
 *  - O TenantFilter do Doctrine fica DESLIGADO no CLI (só liga por request), e não afeta
 *    DML direto de qualquer forma. Por isso o isolamento é garantido por `tenant_id`
 *    EXPLÍCITO em cada DELETE (nunca confiar no filtro — lição do achado B1/Datajud).
 *  - 32 das 34 FKs → tenant são NO ACTION: o banco NÃO cascateia a partir do tenant, então
 *    a deleção é manual, de baixo para cima (ver ORDEM_DELECAO). As cascatas internas de
 *    cada subsistema (pasta, processo, kanban, etc.) são aproveitadas.
 *  - Antes de apagar o tenant, uma verificação de integridade (garantirTenantVazio) confere,
 *    via information_schema, que nenhuma tabela tenant-scoped sobrou — falha segura e clara
 *    caso uma tabela nova não tenha sido adicionada à ordem (drift de schema).
 *  - PRESERVADOS: User (nunca apagado — decisão de produto), audit_log (retido; a própria
 *    purga é registrada como evento), permission (catálogo global), cadastro_pendente e
 *    jornada_colaborador (per-user, sem tenant_id).
 */
final class PurgarEscritorioUseCase
{
    /** Tabelas com coluna tenant_id que NÃO são apagadas na purga (retidas por decisão de produto). */
    private const TABELAS_RETIDAS = ['audit_log'];

    /**
     * Ordem de deleção (do filho para o pai), verificada contra o grafo de FKs do banco.
     * Cada item é [tabela, condição WHERE com :tenant]. Tabelas com tenant_id usam o
     * escopo direto; filhas sem tenant_id (bloco_jornada, tenant_role_permission) usam
     * subquery escopada pelo pai. As tabelas ausentes daqui caem por CASCADE do banco ao
     * apagar a raiz do seu subsistema (ex.: pasta_secao, documento_processo, kanban_*).
     *
     * @var list<array{0:string,1:string}>
     */
    private const ORDEM_DELECAO = [
        // Fase 1 — bloqueadores intermediários (NO ACTION): apagar antes do pai.
        ['bloco_jornada', 'jornada_tenant_id IN (SELECT id FROM jornada_tenant WHERE tenant_id = :tenant)'],
        ['tenant_role_permission', 'tenant_role_id IN (SELECT id FROM tenant_role WHERE tenant_id = :tenant)'],
        ['cliente_documento', 'tenant_id = :tenant'],
        ['pasta_documento', 'tenant_id = :tenant'],
        ['movimentacao_processo', 'tenant_id = :tenant'],
        ['parte_processo', 'tenant_id = :tenant'],
        ['assunto_processo', 'tenant_id = :tenant'],
        ['user_tenant', 'tenant_id = :tenant'],
        // DJEN — publicações e OABs monitoradas (tenant_id direto; FKs a processo/oab_monitorada
        // são SET NULL, então não caem por cascata — precisam de deleção explícita).
        ['publicacao_djen', 'tenant_id = :tenant'],
        ['oab_monitorada', 'tenant_id = :tenant'],
        // Sync — conexão de Drive do escritório (guarda o refresh_token CIFRADO). FK tenant NO ACTION
        // (bloqueia apagar o tenant) + tenant_id direto → deleção explícita. Apaga o segredo junto.
        ['sync_drive_conexao', 'tenant_id = :tenant'],
        // Cobranças — movimentos financeiros (Etapa 3). Filhos antes do pai: a alocação referencia
        // pagamento E obrigação; pagamento/liquidação referenciam o caso (NO ACTION). Por isso vem
        // ANTES do bloco Etapa 2 (que apaga obrigação e caso).
        ['cobranca_alocacao_pagamento', 'tenant_id = :tenant'],
        ['cobranca_pagamento', 'tenant_id = :tenant'],
        ['cobranca_liquidacao', 'tenant_id = :tenant'],
        // Cobranças — casos e movimentos (Etapa 2). Filhos antes do pai: evento/obrigação
        // referenciam o caso; o caso referencia objeto/pessoa (NO ACTION). Por isso vem ANTES
        // do bloco de cadastro abaixo (que apaga objeto/pessoa).
        ['cobranca_evento_historico', 'tenant_id = :tenant'],
        ['cobranca_obrigacao', 'tenant_id = :tenant'],
        // Estados/ações (Etapa 5): próxima ação referencia o caso (NO ACTION). Apagada ANTES do caso.
        // (A FK cobranca_caso.pasta_judicial_id é SET NULL e o caso é apagado antes de `pasta` na
        // Fase 2 — não precisa de deleção extra aqui.)
        ['cobranca_proxima_acao', 'tenant_id = :tenant'],
        // Acordo (Etapa 4): a obrigação referencia o acordo (SET NULL) e o acordo referencia o caso
        // (NO ACTION). Apagado APÓS a obrigação e ANTES do caso.
        ['cobranca_acordo', 'tenant_id = :tenant'],
        // Documentos/seções (Etapa 6): documento referencia seção e caso; seção referencia o caso
        // (ambos onDelete CASCADE). Apagados EXPLICITAMENTE (padrão do módulo) ANTES do caso —
        // documento antes de seção. Os arquivos físicos moram em cobrancas/<tenantId>/ e são
        // removidos por removerDiretorioDeTenant (não dependem de coletarArquivos).
        ['cobranca_documento', 'tenant_id = :tenant'],
        ['cobranca_secao', 'tenant_id = :tenant'],
        ['cobranca_caso', 'tenant_id = :tenant'],
        // Cobranças — cadastro (Etapa 1). As FKs entre si e para cliente/tenant são NO ACTION
        // (não cascateiam), então deleção explícita de baixo para cima: vínculo → objeto →
        // carteira → pessoa. A carteira referencia cliente (NO ACTION), por isso todo o bloco
        // vem ANTES de apagar cliente na Fase 2.
        ['cobranca_vinculo_pessoa_objeto', 'tenant_id = :tenant'],
        ['cobranca_objeto', 'tenant_id = :tenant'],
        ['cobranca_carteira', 'tenant_id = :tenant'],
        ['cobranca_pessoa', 'tenant_id = :tenant'],
        // Fase 2 — raízes de subsistema (a CASCADE do banco derruba os filhos estruturais).
        ['tarefa', 'tenant_id = :tenant'],
        ['notificacao', 'tenant_id = :tenant'],
        ['pasta', 'tenant_id = :tenant'],
        ['processo', 'tenant_id = :tenant'],
        ['cliente', 'tenant_id = :tenant'],
        ['chamado', 'tenant_id = :tenant'],
        ['evento', 'tenant_id = :tenant'],
        ['kanban_board', 'tenant_id = :tenant'],
        ['marcador', 'tenant_id = :tenant'],
        ['legenda_cor', 'tenant_id = :tenant'],
        ['jornada_tenant', 'tenant_id = :tenant'],
        ['justificativa_ponto', 'tenant_id = :tenant'],
        ['registro_ponto', 'tenant_id = :tenant'],
        ['home_office_config', 'tenant_id = :tenant'],
        ['feriado', 'tenant_id = :tenant'],
        // Fase 3 — estruturais / permissões do tenant.
        ['aceite_termo', 'tenant_id = :tenant'],
        ['access_request', 'tenant_id = :tenant'],
        ['resource_access', 'tenant_id = :tenant'],
        ['invitation', 'tenant_id = :tenant'],
        ['tenant_role', 'tenant_id = :tenant'],
        ['cargo', 'tenant_id = :tenant'],
        ['lotacao', 'tenant_id = :tenant'],
        ['sede', 'tenant_id = :tenant'],
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ArquivoStorageInterface $storage,
        private readonly string $uploadsDir,
        private readonly string $clientesUploadsDir,
        private readonly string $chamadosUploadsDir,
        private readonly string $justificativasUploadsDir,
        private readonly string $cobrancasUploadsDir,
        private readonly int $carenciaPurgaDias,
    ) {
    }

    public function executar(Tenant $tenant, bool $dryRun): PurgaEscritorioResultado
    {
        $this->garantirElegivel($tenant);

        $tenantId = (int) $tenant->getId();
        $nome     = $tenant->getName() ?? '';
        $conn     = $this->em->getConnection();

        if ($dryRun === true) {
            return new PurgaEscritorioResultado($tenantId, $nome, true, $this->contarPorTabela($conn, $tenantId), 0);
        }

        // Fase 0 — coletar caminhos dos arquivos em disco ANTES de apagar as linhas.
        $arquivos = $this->coletarArquivos($conn, $tenantId);

        $conn->beginTransaction();

        try {
            $linhas = [];

            foreach (self::ORDEM_DELECAO as [$tabela, $where]) {
                $n = (int) $conn->executeStatement(
                    sprintf('DELETE FROM %s WHERE %s', $tabela, $where),
                    ['tenant' => $tenantId],
                );

                if ($n > 0) {
                    $linhas[$tabela] = $n;
                }
            }

            // Fase 3.5 — verificação de integridade (guard anti-drift): nada tenant-scoped pode sobrar.
            $this->garantirTenantVazio($conn, $tenantId);

            // Fase 4 — o próprio tenant (agora sem filhas bloqueando).
            $conn->executeStatement('DELETE FROM tenant WHERE id = :tenant', ['tenant' => $tenantId]);
            $linhas['tenant'] = 1;

            // Fase 6 — auditoria: registra a purga (audit_log é retido; insert manual, pois o
            // hard delete é via DBAL e não passa pelo AuditLogSubscriber do ORM).
            $this->registrarAuditoria($conn, $tenantId, $nome);

            $conn->commit();
        } catch (\Throwable $e) {
            $conn->rollBack();

            throw $e;
        }

        // Fase 5 — disco: após o commit (unlink não é transacional), best-effort e não fatal.
        $arquivosRemovidos = $this->limparDisco($arquivos, $tenantId);

        return new PurgaEscritorioResultado($tenantId, $nome, false, $linhas, $arquivosRemovidos);
    }

    /**
     * Guarda de segurança: só purga escritório inativo (soft-deletado) cuja carência de
     * quarentena já venceu. Impede purgar um escritório ativo ou ainda recuperável, mesmo
     * se chamado por engano.
     */
    private function garantirElegivel(Tenant $tenant): void
    {
        if ($tenant->isActive() !== false) {
            throw new \LogicException('Só é possível purgar escritório inativo (soft-deletado).');
        }

        $excluidoEm = $tenant->getExcluidoEm();

        if ($excluidoEm === null) {
            throw new \LogicException('Escritório inativo sem data de exclusão — não elegível à purga.');
        }

        $limite = new \DateTimeImmutable(sprintf('-%d days', $this->carenciaPurgaDias));

        if ($excluidoEm > $limite) {
            throw new \LogicException('Escritório ainda dentro da carência de quarentena — não pode ser purgado.');
        }
    }

    /**
     * Tabelas com coluna tenant_id (descobertas do schema), exceto as retidas por decisão.
     * Serve tanto ao dry-run quanto à verificação de integridade e é imune a drift.
     *
     * @return list<string>
     */
    private function tabelasComTenantId(Connection $conn): array
    {
        $tabelas = $conn->fetchFirstColumn(
            "SELECT table_name FROM information_schema.columns
             WHERE table_schema = 'public' AND column_name = 'tenant_id'
             ORDER BY table_name",
        );

        return array_values(array_diff($tabelas, self::TABELAS_RETIDAS));
    }

    /**
     * Contagem do dry-run: usa as MESMAS cláusulas WHERE da ORDEM_DELECAO, então o número
     * por tabela espelha exatamente o que a execução real apaga diretamente (inclui as
     * tabelas-ponte sem tenant_id, como bloco_jornada e tenant_role_permission). As
     * linhas-filhas removidas por CASCADE do banco não são itemizadas aqui (nem no real).
     *
     * @return array<string,int>
     */
    private function contarPorTabela(Connection $conn, int $tenantId): array
    {
        $contagens = [];

        foreach (self::ORDEM_DELECAO as [$tabela, $where]) {
            $n = (int) $conn->fetchOne(
                sprintf('SELECT COUNT(*) FROM %s WHERE %s', $tabela, $where),
                ['tenant' => $tenantId],
            );

            if ($n > 0) {
                $contagens[$tabela] = $n;
            }
        }

        return $contagens;
    }

    /**
     * Confere que nenhuma tabela tenant-scoped ainda tem linha do tenant antes de apagá-lo.
     * Se sobrou algo, aborta com o nome da tabela (rollback) em vez de estourar um FK
     * críptico — protege contra tabela tenant_id nova não coberta pela ORDEM_DELECAO.
     */
    private function garantirTenantVazio(Connection $conn, int $tenantId): void
    {
        foreach ($this->tabelasComTenantId($conn) as $tabela) {
            $n = (int) $conn->fetchOne(
                sprintf('SELECT COUNT(*) FROM %s WHERE tenant_id = :tenant', $tabela),
                ['tenant' => $tenantId],
            );

            if ($n > 0) {
                throw new \RuntimeException(sprintf(
                    'Purga abortada: a tabela "%s" ainda tem %d linha(s) do escritório %d — '
                    . 'provável tabela tenant-scoped nova fora da ordem de deleção. Nada foi apagado.',
                    $tabela,
                    $n,
                    $tenantId,
                ));
            }
        }
    }

    /**
     * Coleta os nomes de arquivo (hash) dos anexos do tenant, antes da deleção das linhas.
     *
     * @return list<string>
     */
    private function coletarArquivos(Connection $conn, int $tenantId): array
    {
        $consultas = [
            'SELECT caminho_arquivo FROM cliente_documento WHERE tenant_id = :tenant',
            'SELECT caminho_arquivo FROM documento_processo WHERE tenant_id = :tenant',
            'SELECT caminho_arquivo FROM pasta_documento WHERE tenant_id = :tenant',
            'SELECT anexo_path FROM justificativa_ponto WHERE tenant_id = :tenant AND anexo_path IS NOT NULL',
            'SELECT arquivo_anexo FROM tarefa_mensagem WHERE tenant_id = :tenant AND arquivo_anexo IS NOT NULL',
            'SELECT nome_arquivo FROM chamado_anexo WHERE chamado_id IN (SELECT id FROM chamado WHERE tenant_id = :tenant)',
            'SELECT caminho FROM kanban_anexo WHERE card_id IN (SELECT id FROM kanban_card WHERE board_id IN (SELECT id FROM kanban_board WHERE tenant_id = :tenant))',
        ];

        $nomes = [];

        foreach ($consultas as $sql) {
            foreach ($conn->fetchFirstColumn($sql, ['tenant' => $tenantId]) as $nome) {
                if (is_string($nome) && $nome !== '') {
                    $nomes[] = $nome;
                }
            }
        }

        return $nomes;
    }

    /**
     * Remove os arquivos em disco. Os nomes são hashes globalmente únicos (bin2hex de 16
     * bytes), então tentar cada nome nos diretórios candidatos é seguro (não colide com
     * arquivo de outro tenant). O diretório de peças é keyed por tenant e removido inteiro.
     * O diretório de fotos de perfil NÃO entra (pertence ao User, que é preservado).
     *
     * @param list<string> $nomes
     */
    private function limparDisco(array $nomes, int $tenantId): int
    {
        $removidos = 0;

        $diretorios = [
            $this->uploadsDir,
            $this->clientesUploadsDir,
            $this->chamadosUploadsDir,
            $this->justificativasUploadsDir,
        ];

        foreach ($nomes as $nome) {
            foreach ($diretorios as $dir) {
                $caminho = $dir . '/' . $nome;

                if ($this->storage->existe($caminho) === true) {
                    $this->storage->excluir($caminho);
                    ++$removidos;
                }
            }
        }

        $removidos += $this->removerDiretorioDeTenant($this->uploadsDir . '/' . $tenantId);
        // Documentos do Caso de Cobrança (Etapa 6): isolados por tenant em cobrancas/<tenantId>/.
        $removidos += $this->removerDiretorioDeTenant($this->cobrancasUploadsDir . '/' . $tenantId);

        return $removidos;
    }

    /**
     * Remove um diretório de peças keyed por tenant (public/uploads/pastas/<tenantId>/) e
     * seu conteúdo. Best-effort: tolera diretório ausente.
     */
    private function removerDiretorioDeTenant(string $diretorio): int
    {
        if (is_dir($diretorio) === false) {
            return 0;
        }

        $removidos = 0;

        foreach (glob($diretorio . '/*') ?: [] as $arquivo) {
            if (is_file($arquivo)) {
                $this->storage->excluir($arquivo);
                ++$removidos;
            }
        }

        @rmdir($diretorio);

        return $removidos;
    }

    private function registrarAuditoria(Connection $conn, int $tenantId, string $nome): void
    {
        $conn->insert('audit_log', [
            'action'       => 'purge',
            'entity_class' => Tenant::class,
            'entity_id'    => (string) $tenantId,
            'changes'      => json_encode(['nome' => $nome, 'motivo' => 'quarentena_expirada'], \JSON_THROW_ON_ERROR),
            'tenant_id'    => $tenantId,
            'created_at'   => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
    }
}
