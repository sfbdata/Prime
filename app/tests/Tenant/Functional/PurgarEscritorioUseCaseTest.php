<?php

declare(strict_types=1);

namespace App\Tests\Tenant\Functional;

use App\Entity\Tenant\Tenant;
use App\Tenant\UseCase\PurgarEscritorioUseCase;
use App\Tests\Factory\Auth\UserFactory;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Testa a etapa mais perigosa do job: o HARD DELETE de um escritório em quarentena.
 * Semeia um grafo completo de dados tenant-scoped via DBAL (para não depender de dezenas
 * de factories) e prova: purga completa, ISOLAMENTO cross-tenant (inegociável),
 * preservação do User compartilhado, guarda de elegibilidade, guard anti-drift e limpeza
 * de disco. Roda em KernelTestCase → TenantFilter DESLIGADO, igual ao CLI.
 */
#[CoversClass(PurgarEscritorioUseCase::class)]
final class PurgarEscritorioUseCaseTest extends KernelTestCase
{
    use Factories;

    private EntityManagerInterface $em;
    private Connection $conn;
    private PurgarEscritorioUseCase $sut;

    protected function setUp(): void
    {
        self::bootKernel();
        $c          = static::getContainer();
        $this->em   = $c->get(EntityManagerInterface::class);
        $this->conn = $this->em->getConnection();
        $this->sut  = $c->get(PurgarEscritorioUseCase::class);
    }

    #[TestDox('Purga apaga TODAS as linhas tenant-scoped do escritório e o próprio tenant')]
    public function testPurgaCompletaRemoveTudoDoTenant(): void
    {
        $tenant = $this->criarTenant(ativo: false, excluidoEm: '-400 days');
        $userId = UserFactory::createOne()->getId();
        $this->semear((int) $tenant->getId(), $userId);

        self::assertGreaterThan(0, array_sum($this->contarPorTenant((int) $tenant->getId())), 'sanidade: seed populou dados');

        $resultado = $this->sut->executar($tenant, dryRun: false);

        self::assertSame(0, $this->existeTenant((int) $tenant->getId()), 'o tenant deve ser apagado');
        self::assertSame(0, array_sum($this->contarPorTenant((int) $tenant->getId())), 'nenhuma linha tenant-scoped pode sobrar');
        self::assertGreaterThan(0, $resultado->totalLinhas());
        self::assertFalse($resultado->simulado);
    }

    #[TestDox('Purgar um escritório NÃO toca nos dados de outro (isolamento inegociável)')]
    public function testIsolamentoNaoTocaOutroTenant(): void
    {
        $userId = UserFactory::createOne()->getId();

        $tenantA = $this->criarTenant(ativo: false, excluidoEm: '-400 days');
        $tenantB = $this->criarTenant(ativo: true);
        $this->semear((int) $tenantA->getId(), $userId);
        $this->semear((int) $tenantB->getId(), $userId);

        $antesB   = $this->contarPorTenant((int) $tenantB->getId());
        $permsB   = (int) $this->conn->fetchOne('SELECT COUNT(*) FROM permission');
        self::assertGreaterThan(0, array_sum($antesB));

        $this->sut->executar($tenantA, dryRun: false);

        self::assertSame(1, $this->existeTenant((int) $tenantB->getId()), 'tenant B intacto');
        self::assertSame($antesB, $this->contarPorTenant((int) $tenantB->getId()), 'nenhuma linha de B pode mudar');
        self::assertSame($permsB, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM permission'), 'catálogo global permission preservado');
    }

    #[TestDox('User compartilhado sobrevive à purga; só o vínculo com o escritório purgado some')]
    public function testUserCompartilhadoSobrevive(): void
    {
        $userId = UserFactory::createOne()->getId();

        $tenantA = $this->criarTenant(ativo: false, excluidoEm: '-400 days');
        $tenantB = $this->criarTenant(ativo: true);
        $this->semear((int) $tenantA->getId(), $userId);
        $this->semear((int) $tenantB->getId(), $userId);

        $this->sut->executar($tenantA, dryRun: false);

        self::assertSame(1, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM "user" WHERE id = :u', ['u' => $userId]), 'User preservado');
        self::assertSame(0, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM user_tenant WHERE user_id = :u AND tenant_id = :t', ['u' => $userId, 't' => $tenantA->getId()]), 'vínculo com A removido');
        self::assertSame(1, (int) $this->conn->fetchOne('SELECT COUNT(*) FROM user_tenant WHERE user_id = :u AND tenant_id = :t', ['u' => $userId, 't' => $tenantB->getId()]), 'vínculo com B preservado');
    }

    #[TestDox('Dry-run relata o que seria apagado sem apagar nada')]
    public function testDryRunNaoApaga(): void
    {
        $tenant = $this->criarTenant(ativo: false, excluidoEm: '-400 days');
        $userId = UserFactory::createOne()->getId();
        $this->semear((int) $tenant->getId(), $userId);

        $resultado = $this->sut->executar($tenant, dryRun: true);

        self::assertTrue($resultado->simulado);
        self::assertNotSame([], $resultado->linhasPorTabela);
        self::assertSame(1, $this->existeTenant((int) $tenant->getId()), 'dry-run não pode apagar o tenant');
        self::assertGreaterThan(0, array_sum($this->contarPorTenant((int) $tenant->getId())), 'dry-run não pode apagar linhas');
    }

    #[TestDox('Não purga escritório ATIVO')]
    public function testNaoPurgaEscritorioAtivo(): void
    {
        $tenant = $this->criarTenant(ativo: true);

        $this->expectException(\LogicException::class);
        $this->sut->executar($tenant, dryRun: false);
    }

    #[TestDox('Não purga escritório ainda dentro da carência de quarentena')]
    public function testNaoPurgaDentroDaCarencia(): void
    {
        $tenant = $this->criarTenant(ativo: false, excluidoEm: '-10 days');

        $this->expectException(\LogicException::class);
        $this->sut->executar($tenant, dryRun: false);
    }

    #[TestDox('Guard anti-drift: tabela tenant-scoped fora da ordem aborta a purga (tenant preservado)')]
    public function testGuardAntiDriftAborta(): void
    {
        $tenant   = $this->criarTenant(ativo: false, excluidoEm: '-400 days');
        $tenantId = (int) $tenant->getId();

        // Simula uma tabela tenant-scoped NOVA que ninguém adicionou à ordem de deleção.
        $this->conn->executeStatement('CREATE TABLE _drift_teste (id serial PRIMARY KEY, tenant_id integer NOT NULL)');
        $this->conn->executeStatement('INSERT INTO _drift_teste (tenant_id) VALUES (:t)', ['t' => $tenantId]);

        try {
            $this->sut->executar($tenant, dryRun: false);
            self::fail('esperava RuntimeException por tabela não coberta');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('_drift_teste', $e->getMessage());
            self::assertSame(1, $this->existeTenant($tenantId), 'rollback: o tenant não pode ter sido apagado');
        } finally {
            $this->conn->executeStatement('DROP TABLE IF EXISTS _drift_teste');
        }
    }

    #[TestDox('Purga remove os arquivos em disco (anexos e diretório de peças do tenant)')]
    public function testDiscoRemoveArquivos(): void
    {
        $clientesDir = (string) static::getContainer()->getParameter('clientes_uploads_dir');
        $pastasDir   = (string) static::getContainer()->getParameter('uploads_dir');

        $tenant   = $this->criarTenant(ativo: false, excluidoEm: '-400 days');
        $tenantId = (int) $tenant->getId();
        $userId   = UserFactory::createOne()->getId();

        // Anexo flat (cliente_documento) num arquivo real em disco.
        @mkdir($clientesDir, 0o777, true);
        $nomeAnexo = bin2hex(random_bytes(8)) . '.pdf';
        file_put_contents($clientesDir . '/' . $nomeAnexo, 'x');
        $clienteId = $this->ins('cliente', $this->dadosCliente($tenantId));
        $this->ins('cliente_documento', [
            'titulo' => 'Doc', 'categoria' => 'geral', 'caminho_arquivo' => $nomeAnexo,
            'nome_original' => 'd.pdf', 'mime_type' => 'application/pdf', 'tamanho_bytes' => 1,
            'uploaded_at' => '2020-01-01 00:00:00', 'cliente_id' => $clienteId, 'tenant_id' => $tenantId,
        ]);

        // Diretório de peças keyed por tenant.
        $pecaDir = $pastasDir . '/' . $tenantId;
        @mkdir($pecaDir, 0o777, true);
        file_put_contents($pecaDir . '/' . bin2hex(random_bytes(8)) . '.jpg', 'y');

        $resultado = $this->sut->executar($tenant, dryRun: false);

        self::assertFileDoesNotExist($clientesDir . '/' . $nomeAnexo, 'anexo flat removido');
        self::assertDirectoryDoesNotExist($pecaDir, 'diretório de peças do tenant removido');
        self::assertGreaterThanOrEqual(2, $resultado->arquivosRemovidos);
    }

    // ----------------------------------------------------------------- helpers

    private function criarTenant(bool $ativo, ?string $excluidoEm = null): Tenant
    {
        $tenant = new Tenant();
        $tenant->setName('Tenant ' . uniqid());
        $tenant->setIsActive($ativo);

        if ($excluidoEm !== null) {
            $tenant->setExcluidoEm(new \DateTimeImmutable($excluidoEm));
        }

        $this->em->persist($tenant);
        $this->em->flush();

        return $tenant;
    }

    private function existeTenant(int $tenantId): int
    {
        return (int) $this->conn->fetchOne('SELECT COUNT(*) FROM tenant WHERE id = :t', ['t' => $tenantId]);
    }

    /** @return list<string> */
    private function tabelasTenant(): array
    {
        $tabelas = $this->conn->fetchFirstColumn(
            "SELECT table_name FROM information_schema.columns
             WHERE table_schema = 'public' AND column_name = 'tenant_id' ORDER BY table_name",
        );

        return array_values(array_diff($tabelas, ['audit_log']));
    }

    /** @return array<string,int> */
    private function contarPorTenant(int $tenantId): array
    {
        $contagens = [];

        foreach ($this->tabelasTenant() as $tabela) {
            $contagens[$tabela] = (int) $this->conn->fetchOne(
                sprintf('SELECT COUNT(*) FROM %s WHERE tenant_id = :t', $tabela),
                ['t' => $tenantId],
            );
        }

        return $contagens;
    }

    /**
     * @param array<string,mixed> $dados
     */
    private function ins(string $tabela, array $dados): int
    {
        $cols = implode(', ', array_map(static fn (string $c): string => '"' . $c . '"', array_keys($dados)));
        $ph   = implode(', ', array_map(static fn (string $c): string => ':' . $c, array_keys($dados)));

        $tipos = [];
        foreach ($dados as $col => $valor) {
            if (is_bool($valor)) {
                $tipos[$col] = ParameterType::BOOLEAN;
            }
        }

        return (int) $this->conn->fetchOne(
            sprintf('INSERT INTO %s (%s) VALUES (%s) RETURNING id', $tabela, $cols, $ph),
            $dados,
            $tipos,
        );
    }

    /** @return array<string,mixed> */
    private function dadosCliente(int $t): array
    {
        return [
            'email' => 'cli' . uniqid() . '@x.com', 'cep' => '00000-000', 'endereco' => 'Rua',
            'cidade' => 'Cidade', 'estado' => 'SP', 'criado_at' => '2020-01-01 00:00:00',
            'tipo' => 'PF', 'tenant_id' => $t,
        ];
    }

    /** Semeia uma linha em cada tabela tenant-scoped (e filhos por cascata) do tenant. */
    private function semear(int $t, int $u): void
    {
        $ts  = '2020-01-01 00:00:00';
        $d   = '2020-01-01';
        $uid = uniqid();
        $hex = static fn (): string => bin2hex(random_bytes(8)) . '.pdf';

        // Permissões / papéis / vínculo
        $permId = $this->ins('permission', ['code' => 'perm_' . $uid, 'description' => 'Perm', 'group' => 'geral']);
        $roleId = $this->ins('tenant_role', ['name' => 'Admin ' . $uid, 'is_system' => true, 'created_at' => $ts, 'tenant_id' => $t]);
        $this->ins('tenant_role_permission', ['granted_at' => $ts, 'tenant_role_id' => $roleId, 'permission_id' => $permId]);
        $this->ins('user_tenant', ['is_active' => true, 'created_at' => $ts, 'user_id' => $u, 'tenant_id' => $t, 'tenant_role_id' => $roleId]);

        // Estruturais
        $this->ins('sede', ['nome' => 'Sede', 'latitude' => 0, 'longitude' => 0, 'raio_permitido' => 100, 'timezone' => 'America/Sao_Paulo', 'tenant_id' => $t]);
        $this->ins('cargo', ['nome' => 'Cargo', 'tenant_id' => $t]);
        $this->ins('lotacao', ['nome' => 'Lotacao', 'tenant_id' => $t]);

        // Jornada + bloco (bloqueador intermediário)
        $jt = $this->ins('jornada_tenant', ['carga_horaria_semanal' => 40, 'alerta_habilitado' => true, 'tenant_id' => $t, 'validacao_repouso_habilitada' => false, 'minimo_minutos_repouso' => 0, 'validacao_interjornada_habilitada' => false, 'minimo_minutos_interjornada' => 0]);
        $this->ins('bloco_jornada', ['dias_semana' => '[1,2,3,4,5]', 'entrada' => '08:00', 'saida' => '17:00', 'minutos_bloco' => 480, 'jornada_tenant_id' => $jt]);

        // Cliente + documento (bloqueador intermediário)
        $cli = $this->ins('cliente', $this->dadosCliente($t));
        $this->ins('cliente_documento', ['titulo' => 'Doc', 'categoria' => 'geral', 'caminho_arquivo' => $hex(), 'nome_original' => 'd.pdf', 'mime_type' => 'application/pdf', 'tamanho_bytes' => 1, 'uploaded_at' => $ts, 'cliente_id' => $cli, 'tenant_id' => $t]);

        // Cobranças (Etapa 1): carteira→cliente, objeto→carteira, vínculo→pessoa+objeto. Exercita a
        // ordem FK-safe da purga (carteira apagada antes de cliente) e o isolamento entre escritórios.
        $carteira = $this->ins('cobranca_carteira', ['nome' => 'Carteira', 'modo' => 'unico', 'forma_honorarios' => 'sem_percentual', 'tolerancia_atraso_dias' => 0, 'criado_em' => $ts, 'tenant_id' => $t, 'cliente_id' => $cli]);
        $pessoa   = $this->ins('cobranca_pessoa', ['nome' => 'Pessoa', 'criado_em' => $ts, 'tenant_id' => $t]);
        $objeto   = $this->ins('cobranca_objeto', ['identificacao' => 'Objeto', 'criado_em' => $ts, 'tenant_id' => $t, 'carteira_id' => $carteira]);
        $this->ins('cobranca_vinculo_pessoa_objeto', ['tipo_vinculo' => 'proprietario', 'data_inicio' => $d, 'criado_em' => $ts, 'tenant_id' => $t, 'pessoa_id' => $pessoa, 'objeto_id' => $objeto]);

        // Cobranças (Etapa 2): caso→objeto+pessoa, obrigação→caso, evento→caso. Exercita a ordem
        // FK-safe (evento/obrigação antes do caso; caso antes de objeto/pessoa).
        $caso = $this->ins('cobranca_caso', ['status' => 'ativo', 'forma_honorarios' => 'sem_percentual', 'criado_em' => $ts, 'tenant_id' => $t, 'objeto_id' => $objeto, 'pessoa_cobrada_atual_id' => $pessoa]);
        $obrigacao = $this->ins('cobranca_obrigacao', ['descricao' => 'Competência', 'valor_original' => 10000, 'vencimento_original' => $d, 'encargos_reconhecidos' => 0, 'criado_em' => $ts, 'tenant_id' => $t, 'caso_id' => $caso]);
        $this->ins('cobranca_evento_historico', ['tipo' => 'caso_aberto', 'ocorrido_em' => $ts, 'descricao' => 'Caso aberto', 'tenant_id' => $t, 'caso_id' => $caso]);

        // Cobranças (Etapa 3): pagamento→caso, alocação→pagamento+obrigação, liquidação→caso. Exercita
        // a ordem FK-safe (alocação antes de pagamento/obrigação; pagamento/liquidação antes do caso).
        $pagamento = $this->ins('cobranca_pagamento', ['data' => $d, 'valor_divida' => 6000, 'valor_encargos' => 0, 'valor_honorarios' => 0, 'criado_em' => $ts, 'tenant_id' => $t, 'caso_id' => $caso]);
        $this->ins('cobranca_alocacao_pagamento', ['valor' => 6000, 'tenant_id' => $t, 'pagamento_id' => $pagamento, 'obrigacao_id' => $obrigacao]);
        $this->ins('cobranca_liquidacao', ['tipo' => 'bem_movel', 'descricao_bem' => 'Bem', 'valor_atribuido_bem' => 5000, 'valor_reconhecido' => 4000, 'data' => $d, 'criado_em' => $ts, 'tenant_id' => $t, 'caso_id' => $caso]);

        // Cobranças (Etapa 4): acordo→caso; parcela aponta acordo_origem_id. Exercita a ordem FK-safe
        // (obrigação apagada antes do acordo; acordo antes do caso).
        $acordo = $this->ins('cobranca_acordo', ['status' => 'ativo', 'data_acordo' => $d, 'criado_em' => $ts, 'tenant_id' => $t, 'caso_id' => $caso]);
        $this->ins('cobranca_obrigacao', ['descricao' => 'Parcela de acordo', 'valor_original' => 5000, 'vencimento_original' => $d, 'encargos_reconhecidos' => 0, 'criado_em' => $ts, 'tenant_id' => $t, 'caso_id' => $caso, 'acordo_origem_id' => $acordo]);

        // Documentos da Carteira e do Acordo (Ajustes #4/#5): exercita a ordem FK-safe adicionada
        // à ORDEM_DELECAO (documento apagado ANTES do dono) e a limpeza do mesmo diretório flat
        // de disco (sem subdiretório novo).
        $this->ins('cobranca_carteira_documento', ['titulo' => 'Ata', 'categoria' => 'outro', 'caminho_arquivo' => $hex(), 'nome_original' => 'ata.pdf', 'mime_type' => 'application/pdf', 'tamanho_bytes' => 1, 'uploaded_at' => $ts, 'tenant_id' => $t, 'carteira_id' => $carteira]);
        $this->ins('cobranca_acordo_documento', ['titulo' => 'Termo', 'categoria' => 'outro', 'caminho_arquivo' => $hex(), 'nome_original' => 'termo.pdf', 'mime_type' => 'application/pdf', 'tamanho_bytes' => 1, 'uploaded_at' => $ts, 'tenant_id' => $t, 'acordo_id' => $acordo]);

        // Cobranças (Etapa 5): próxima ação aponta o caso (NO ACTION). Exercita a ordem FK-safe
        // (apagada antes do caso).
        $this->ins('cobranca_proxima_acao', ['descricao' => 'Verificar pagamento', 'status' => 'pendente', 'criado_em' => $ts, 'tenant_id' => $t, 'caso_id' => $caso]);

        // Cobranças (Etapa 6): seção→caso; documento→caso+seção. Exercita a ordem FK-safe (documento
        // antes de seção; ambos antes do caso) e a limpeza do diretório de disco por tenant.
        $secaoCobr = $this->ins('cobranca_secao', ['nome' => 'Acordos', 'ordem' => 0, 'criada_em' => $ts, 'tenant_id' => $t, 'caso_id' => $caso]);
        $this->ins('cobranca_documento', ['titulo' => 'TERMO', 'categoria' => 'termo_acordo', 'caminho_arquivo' => $hex(), 'nome_original' => 'termo.pdf', 'mime_type' => 'application/pdf', 'tamanho_bytes' => 1, 'uploaded_at' => $ts, 'ordem' => 0, 'tenant_id' => $t, 'caso_id' => $caso, 'secao_id' => $secaoCobr]);

        // Pasta + seção + documento (bloqueador intermediário)
        $pasta = $this->ins('pasta', ['nup' => 'NUP-' . $uid, 'data_abertura' => $ts, 'created_at' => $ts, 'tenant_id' => $t]);
        $this->ins('pasta_secao', ['nome' => 'Secao', 'criada_em' => $ts, 'pasta_id' => $pasta, 'tenant_id' => $t]);
        $this->ins('pasta_documento', ['titulo' => 'PDoc', 'categoria' => 'geral', 'caminho_arquivo' => $hex(), 'nome_original' => 'p.pdf', 'mime_type' => 'application/pdf', 'tamanho_bytes' => 1, 'uploaded_at' => $ts, 'pasta_id' => $pasta, 'tenant_id' => $t]);

        // Processo + movimentação + parte + documento (bloqueadores intermediários)
        $proc = $this->ins('processo', ['numero_processo' => 'PROC-' . $uid, 'orgao_julgador' => 'OJ', 'sigla_tribunal' => 'TJSP', 'classe_processual' => 'CLS', 'assunto_processual' => 'ASS', 'situacao_processo' => 'ativo', 'instancia' => '1', 'tenant_id' => $t]);
        $this->ins('movimentacao_processo', ['processo_id' => $proc, 'descricao' => 'Mov', 'tenant_id' => $t]);
        $this->ins('parte_processo', ['processo_id' => $proc, 'tipo' => 'autor', 'nome' => 'Parte', 'tenant_id' => $t]);
        $this->ins('assunto_processo', ['processo_id' => $proc, 'nome' => 'Assunto', 'codigo' => 123, 'tenant_id' => $t]);
        $this->ins('documento_processo', ['processo_id' => $proc, 'tipo' => 'peticao', 'nome_original' => 'dp.pdf', 'caminho_arquivo' => $hex(), 'criado_em' => $ts, 'tenant_id' => $t]);

        // Tarefa + mensagem + notificação
        $tarefa = $this->ins('tarefa', ['pasta_id' => $pasta, 'titulo' => 'Tarefa', 'descricao' => 'desc', 'status' => 'pendente', 'data_criacao' => $ts, 'tenant_id' => $t]);
        $this->ins('tarefa_mensagem', ['tarefa_id' => $tarefa, 'usuario_id' => $u, 'mensagem' => 'msg', 'criado_em' => $ts, 'tenant_id' => $t, 'arquivo_anexo' => $hex()]);
        $this->ins('notificacao', ['usuario_id' => $u, 'tipo' => 'info', 'titulo' => 'N', 'lida' => false, 'criada_em' => $ts, 'tenant_id' => $t]);

        // Chamado + anexo
        $cham = $this->ins('chamado', ['solicitante_id' => $u, 'titulo' => 'C', 'descricao' => 'd', 'categoria' => 'geral', 'prioridade' => 'baixa', 'status' => 'aberto', 'criado_em' => $ts, 'tenant_id' => $t]);
        $this->ins('chamado_anexo', ['chamado_id' => $cham, 'nome_original' => 'a.pdf', 'nome_arquivo' => $hex(), 'mime_type' => 'application/pdf', 'tamanho' => 1, 'criado_em' => $ts, 'enviado_por_id' => $u]);

        // Evento
        $this->ins('evento', ['criador_id' => $u, 'titulo' => 'E', 'data_inicio' => $ts, 'data_fim' => $ts, 'status' => 'ativo', 'cor' => '#fff', 'dia_inteiro' => false, 'recorrente' => false, 'criado_em' => $ts, 'visibilidade' => 'privado', 'tenant_id' => $t]);

        // Kanban (board → coluna → card → anexo)
        $board = $this->ins('kanban_board', ['nome' => 'B', 'criado_em' => $ts, 'tenant_id' => $t, 'criado_por_id' => $u]);
        // tenant_id nas filhas: denormalizado do pai desde a fatia 2 da spec de isolamento
        // multi-tenant. Aqui vai explícito porque a inserção é SQL cru e não passa pelos
        // construtores, que são quem deriva o tenant do pai no código de produção.
        $col   = $this->ins('kanban_coluna', ['nome' => 'Col', 'tipo' => 'todo', 'posicao' => 0, 'board_id' => $board, 'tenant_id' => $t]);
        $card  = $this->ins('kanban_card', ['titulo' => 'Card', 'posicao' => 0, 'criado_em' => $ts, 'coluna_id' => $col, 'board_id' => $board, 'criado_por_id' => $u, 'tenant_id' => $t]);
        $this->ins('kanban_anexo', ['nome_original' => 'k.pdf', 'caminho' => $hex(), 'tamanho' => 1, 'mime_type' => 'application/pdf', 'criado_em' => $ts, 'card_id' => $card, 'criado_por_id' => $u, 'tenant_id' => $t]);

        // Diversos tenant-scoped
        $this->ins('marcador', ['nome' => 'M', 'ordem' => 0, 'criado_at' => $ts, 'tenant_id' => $t, 'criado_por_id' => $u]);
        $this->ins('legenda_cor', ['nome' => 'L', 'cor' => '#000', 'criado_at' => $ts, 'tenant_id' => $t]);
        $this->ins('feriado', ['nome' => 'F', 'data' => $d, 'recorrente' => false, 'tenant_id' => $t]);
        $this->ins('registro_ponto', ['data_hora' => $ts, 'tipo' => 'entrada', 'user_id' => $u, 'tenant_id' => $t]);
        $this->ins('justificativa_ponto', ['data' => $d, 'status' => 'pendente', 'user_id' => $u, 'abono_parcial' => false, 'tenant_id' => $t, 'anexo_path' => $hex()]);
        $this->ins('aceite_termo', ['versao' => 'v-' . $uid, 'aceito_em' => $ts, 'ip' => '127.0.0.1', 'user_id' => $u, 'tenant_id' => $t]);
        $this->ins('invitation', ['email' => 'inv' . $uid . '@x.com', 'token' => 'tok-' . $uid, 'type' => 'office', 'status' => 'pending', 'created_at' => $ts, 'expires_at' => $ts, 'tenant_id' => $t]);
        $this->ins('access_request', ['resource_type' => 'pasta', 'resource_id' => $pasta, 'action' => 'view', 'requested_at' => $ts, 'user_id' => $u, 'tenant_id' => $t]);
        $this->ins('resource_access', ['resource_type' => 'pasta', 'resource_id' => $pasta, 'granted_at' => $ts, 'user_id' => $u, 'tenant_id' => $t]);
    }
}
