<?php

declare(strict_types=1);

namespace App\Tests\Tenant\Functional;

use App\Entity\Tenant\Tenant;
use App\Shared\Armazenamento\CategoriaDeArquivo;
use App\Shared\Armazenamento\ChaveDeArquivo;
use App\Shared\Armazenamento\EscopoDeArquivo;
use App\Shared\Armazenamento\ArmazenamentoDeArquivos;
use App\Shared\Armazenamento\ArmazenamentoLocal;
use App\Shared\Armazenamento\RemocaoAposTransacao;
use App\Shared\Doctrine\Transacao\ConsultaDeDestinoDaTransacao;
use App\Shared\Doctrine\Transacao\DestinoDaTransacao;
use App\Tenant\DTO\PurgaEscritorioResultado;
use App\Tenant\Exception\PurgaComDestinoIncerto;
use App\Tenant\UseCase\PurgarEscritorioUseCase;
use App\Tests\Factory\Auth\UserFactory;
use App\Tests\Shared\Doubles\ArmazenamentoEmMemoria;
use App\Tests\Shared\Doubles\ConsultaDeDestinoFixa;
use App\Tests\Shared\Doubles\FalhaDeCommitArmavel;
use App\Tests\Shared\Doubles\LoggerEmMemoria;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Os ARQUIVOS da purga de escritório, contra o banco e o disco reais (E2.5, D7).
 *
 * Dois escritórios dividem os mesmos diretórios planos (`clientes/`, `pastas/`, `justificativas/`,
 * `chamados/`, `kanban/`) — é o layout de produção. A pergunta de cada teste é a mesma: a purga de
 * um apaga exatamente o que é dele, e nada do outro?
 *
 *  - planas: um a um, pelos registros, e só o que nenhum registro alheio referencia;
 *  - com isolamento físico (`pastas/<id>`, `cobrancas/<id>`): o prefixo inteiro, com prova;
 *  - Kanban entrou; `documento_processo` saiu; Tarefa fica de fora, reportada;
 *  - depois do COMMIT nada lança — sobra vira item do resultado.
 */
#[CoversClass(PurgarEscritorioUseCase::class)]
final class PurgaArquivosTest extends KernelTestCase
{
    use Factories;

    private EntityManagerInterface $em;
    private Connection $conn;
    private int $userId;

    /** @var list<string> */
    private array $criados = [];

    /** @var list<string> */
    private array $diretoriosCriados = [];

    private ?string $diretorioComModoAlterado = null;
    private int $modoOriginal = 0;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em     = static::getContainer()->get(EntityManagerInterface::class);
        $this->conn   = $this->em->getConnection();
        $this->userId = (int) UserFactory::createOne()->getId();
    }

    protected function tearDown(): void
    {
        FalhaDeCommitArmavel::desarmar();
        $this->conn->executeStatement('DROP TABLE IF EXISTS _drift_arquivos');

        if ($this->diretorioComModoAlterado !== null) {
            @chmod($this->diretorioComModoAlterado, $this->modoOriginal);
        }

        foreach ($this->criados as $caminho) {
            if (is_link($caminho) || is_file($caminho)) {
                @unlink($caminho);
            }
        }

        foreach (array_reverse($this->diretoriosCriados) as $diretorio) {
            $this->removerArvore($diretorio);
        }

        parent::tearDown();
    }

    #[TestDox('categorias planas: a purga apaga os arquivos do escritório — Kanban inclusive — e nenhum do vizinho')]
    public function testPlanasSoApagamOQueEhDoEscritorio(): void
    {
        $a = $this->tenantEmQuarentena();
        $b = $this->tenantEmQuarentena();

        $deA = $this->semearPlanas($a);
        $deB = $this->semearPlanas($b);

        $resultado = $this->purgar($a);

        foreach ($deA as $categoria => $caminho) {
            self::assertFileDoesNotExist($caminho, "arquivo de {$categoria} do escritório purgado ficou");
        }
        foreach ($deB as $categoria => $caminho) {
            self::assertFileExists($caminho, "a purga do A apagou o arquivo de {$categoria} do B");
        }
        self::assertSame(5, $resultado->arquivosRemovidos, 'cliente, pasta, justificativa (uma vez só para o lote), chamado e Kanban');
        self::assertSame([], $resultado->arquivosNaoRemovidos);
        self::assertSame(0, $this->existeTenant($a));
        self::assertSame(1, $this->existeTenant($b));
    }

    /** @return iterable<string, array{string}> */
    public static function tabelasPlanas(): iterable
    {
        foreach (['cliente_documento', 'pasta_documento', 'justificativa_ponto', 'chamado_anexo', 'kanban_anexo'] as $tabela) {
            yield $tabela => [$tabela];
        }
    }

    /**
     * O cenário que D7 existe para impedir: o mesmo nome referenciado pelos dois escritórios. Não
     * acontece por construção (128 bits), mas a purga não pode confiar nisso para apagar arquivo
     * de um diretório compartilhado. Uma prova POR CONSULTA: são cinco SQLs independentes.
     */
    #[TestDox('$tabela: nome também referenciado por outro escritório — o arquivo FICA e é reportado')]
    #[DataProvider('tabelasPlanas')]
    public function testNomeCompartilhadoFicaEEhReportado(string $tabela): void
    {
        $a       = $this->tenantEmQuarentena();
        $b       = $this->tenantEmQuarentena();
        $nome    = $this->nome();
        $caminho = $this->arquivo(self::DIRETORIO_DE[$tabela], $nome);
        $this->registrar($tabela, $a, $nome);
        $this->registrar($tabela, $b, $nome);

        $resultado = $this->purgar($a);

        self::assertFileExists($caminho, 'o B ainda referencia o arquivo');
        self::assertSame(0, $this->existeTenant($a), 'o banco é purgado mesmo assim');
        self::assertCount(1, $resultado->arquivosNaoRemovidos);
        self::assertStringContainsString($tabela . '/' . $nome, $resultado->arquivosNaoRemovidos[0]);
        self::assertStringContainsString('outro escritório', $resultado->arquivosNaoRemovidos[0]);
        self::assertSame(0, $resultado->arquivosRemovidos);
    }

    /**
     * Linha de OUTRO escritório que cai por CASCADE de um pai deste: pela seção da pasta, e pela
     * coluna do Kanban. A prova exige a cadeia inteira; sem ela o arquivo fica — e é reportado.
     */
    #[TestDox('cadeia que passa por este escritório (seção da pasta, coluna do Kanban): o arquivo do outro fica e é reportado')]
    public function testCadeiaDivergentePorCascataEhReportada(): void
    {
        $a  = $this->tenantEmQuarentena();
        $x  = $this->tenantEmQuarentena();
        $ts = '2020-01-01 00:00:00';

        $pastaA = $this->ins('pasta', ['nup' => 'NUP-' . uniqid(), 'data_abertura' => $ts, 'created_at' => $ts, 'tenant_id' => $a]);
        $secaoA = $this->ins('pasta_secao', ['nome' => 'Secao', 'criada_em' => $ts, 'pasta_id' => $pastaA, 'tenant_id' => $a]);
        $pastaX = $this->ins('pasta', ['nup' => 'NUP-' . uniqid(), 'data_abertura' => $ts, 'created_at' => $ts, 'tenant_id' => $x]);
        $docX   = $this->nome();
        $this->ins('pasta_documento', ['titulo' => 'PDoc', 'categoria' => 'geral', 'caminho_arquivo' => $docX, 'nome_original' => 'p.pdf', 'mime_type' => 'application/pdf', 'tamanho_bytes' => 1, 'uploaded_at' => $ts, 'pasta_id' => $pastaX, 'secao_id' => $secaoA, 'tenant_id' => $x]);
        $arquivoPasta = $this->arquivo('uploads_dir', $docX);

        [$boardA]       = $this->mural($a);
        $colunaA        = (int) $this->conn->fetchOne('SELECT id FROM kanban_coluna WHERE board_id = ?', [$boardA]);
        [$boardX]       = $this->mural($x);
        $cardX          = $this->ins('kanban_card', ['titulo' => 'Card', 'posicao' => 0, 'criado_em' => $ts, 'coluna_id' => $colunaA, 'board_id' => $boardX, 'criado_por_id' => $this->userId, 'tenant_id' => $x]);
        $anexoX         = $this->nome();
        $this->ins('kanban_anexo', ['nome_original' => 'k.pdf', 'caminho' => $anexoX, 'tamanho' => 1, 'mime_type' => 'application/pdf', 'criado_em' => $ts, 'card_id' => $cardX, 'criado_por_id' => $this->userId, 'tenant_id' => $x]);
        $arquivoKanban  = $this->arquivo('kanban_uploads_dir', $anexoX);

        $resultado = $this->purgar($a);

        self::assertFileExists($arquivoPasta);
        self::assertFileExists($arquivoKanban);
        $reportados = implode("\n", $resultado->arquivosNaoRemovidos);
        self::assertStringContainsString('pasta_documento/' . $docX, $reportados);
        self::assertStringContainsString('kanban_anexo/' . $anexoX, $reportados);
    }

    /**
     * O caso inverso do anterior, e o único em que as cláusulas da SEÇÃO e da COLUNA decidem: o
     * registro é deste escritório, mas um pai dele é de outro. Ali a primeira condição
     * (`d.tenant_id`/`c.tenant_id` diferente) não dispara — sem as duas cláusulas o arquivo sairia.
     */
    #[TestDox('registro deste escritório pendurado em seção ou coluna de outro: sem prova, o arquivo fica e é reportado')]
    public function testRegistroComPaiDeOutroEscritorioFicaEEhReportado(): void
    {
        $a  = $this->tenantEmQuarentena();
        $x  = $this->tenantEmQuarentena();
        $ts = '2020-01-01 00:00:00';

        $pastaA = $this->ins('pasta', ['nup' => 'NUP-' . uniqid(), 'data_abertura' => $ts, 'created_at' => $ts, 'tenant_id' => $a]);
        $pastaX = $this->ins('pasta', ['nup' => 'NUP-' . uniqid(), 'data_abertura' => $ts, 'created_at' => $ts, 'tenant_id' => $x]);
        $secaoX = $this->ins('pasta_secao', ['nome' => 'Secao', 'criada_em' => $ts, 'pasta_id' => $pastaX, 'tenant_id' => $x]);
        $docA   = $this->nome();
        $this->ins('pasta_documento', ['titulo' => 'PDoc', 'categoria' => 'geral', 'caminho_arquivo' => $docA, 'nome_original' => 'p.pdf', 'mime_type' => 'application/pdf', 'tamanho_bytes' => 1, 'uploaded_at' => $ts, 'pasta_id' => $pastaA, 'secao_id' => $secaoX, 'tenant_id' => $a]);
        $arquivoPasta = $this->arquivo('uploads_dir', $docA);

        $boardA  = $this->ins('kanban_board', ['nome' => 'B', 'criado_em' => $ts, 'tenant_id' => $a, 'criado_por_id' => $this->userId]);
        [$boardX] = $this->mural($x);
        $colunaX = (int) $this->conn->fetchOne('SELECT id FROM kanban_coluna WHERE board_id = ?', [$boardX]);
        $cardA   = $this->ins('kanban_card', ['titulo' => 'Card', 'posicao' => 0, 'criado_em' => $ts, 'coluna_id' => $colunaX, 'board_id' => $boardA, 'criado_por_id' => $this->userId, 'tenant_id' => $a]);
        $anexoA  = $this->nome();
        $this->ins('kanban_anexo', ['nome_original' => 'k.pdf', 'caminho' => $anexoA, 'tamanho' => 1, 'mime_type' => 'application/pdf', 'criado_em' => $ts, 'card_id' => $cardA, 'criado_por_id' => $this->userId, 'tenant_id' => $a]);
        $arquivoKanban = $this->arquivo('kanban_uploads_dir', $anexoA);

        $resultado = $this->purgar($a);

        self::assertSame(0, $this->existeTenant($a), 'o banco é purgado mesmo assim');
        self::assertFileExists($arquivoPasta, 'documento do A numa seção do X: sem prova, fica');
        self::assertFileExists($arquivoKanban, 'anexo do A num card com coluna do X: sem prova, fica');
        $reportados = implode("\n", $resultado->arquivosNaoRemovidos);
        self::assertStringContainsString('pasta_documento/' . $docA, $reportados);
        self::assertStringContainsString('kanban_anexo/' . $anexoA, $reportados);
        self::assertSame(0, $resultado->arquivosRemovidos);
    }

    /**
     * Rollback sem perda física na purga: a guarda anti-drift aborta DEPOIS da coleta e dos
     * DELETEs. Nenhum arquivo — plano ou de prefixo — pode ter saído.
     */
    #[TestDox('purga abortada antes do COMMIT: o escritório fica e nenhum arquivo sai')]
    public function testPurgaAbortadaNaoApagaNada(): void
    {
        $a      = $this->tenantEmQuarentena();
        $planas = $this->semearPlanas($a);
        $imagem = $this->arquivo('uploads_dir', $a . '/img.png');
        $doc    = $this->arquivo('cobrancas_uploads_dir', $a . '/doc.pdf');
        $this->conn->executeStatement('CREATE TABLE _drift_arquivos (id serial PRIMARY KEY, tenant_id integer NOT NULL)');
        $this->conn->executeStatement('INSERT INTO _drift_arquivos (tenant_id) VALUES (?)', [$a]);

        $capturada = null;
        try {
            $this->purgar($a);
        } catch (\RuntimeException $e) {
            $capturada = $e;
        }

        self::assertStringContainsString('_drift_arquivos', (string) $capturada?->getMessage());
        self::assertSame(1, $this->existeTenant($a));
        foreach ([...array_values($planas), $imagem, $doc] as $caminho) {
            self::assertFileExists($caminho, 'rollback sem perda física');
        }
    }

    /**
     * O COMMIT da purga chega ao banco e a resposta se perde. Sob o DAMA a consulta real responde
     * "em andamento": sem prova, nenhum arquivo é tocado e o resultado é declarado INCERTO — a
     * purga não pode dizer "nada foi apagado", porque o banco foi purgado.
     */
    #[TestDox('COMMIT da purga com a resposta perdida e sem prova: PurgaComDestinoIncerto, nenhum arquivo tocado, rastro no log')]
    public function testCommitIncertoNaoTocaNoDisco(): void
    {
        $a      = $this->tenantEmQuarentena();
        $planas = $this->semearPlanas($a);
        $doc    = $this->arquivo('cobrancas_uploads_dir', $a . '/doc.pdf');
        $logger = new LoggerEmMemoria();
        FalhaDeCommitArmavel::perderRespostaDoProximoCommit();

        $capturada = null;
        try {
            $this->purgaCom(static::getContainer()->get(ConsultaDeDestinoDaTransacao::class), $logger)
                ->executar($this->tenant($a), dryRun: false);
        } catch (PurgaComDestinoIncerto $e) {
            $capturada = $e;
        }

        self::assertNotNull($capturada);
        self::assertSame(1, FalhaDeCommitArmavel::$disparos);
        self::assertSame(0, $this->existeTenant($a), 'o banco confirmou: o escritório já não existe');
        foreach ([...array_values($planas), $doc] as $caminho) {
            self::assertFileExists($caminho, 'sem prova do destino, nada sai do disco');
        }
        $erro = $logger->doNivel('error')[0];
        self::assertSame(DestinoDaTransacao::Incerta->value, $erro['contexto']['destino']);
        self::assertCount(5, $erro['contexto']['seria_removido'], 'o rastro para a limpeza manual');
    }

    #[TestDox('COMMIT da purga com a resposta perdida e o banco PROVA committed: o disco segue')]
    public function testCommitConfirmadoSegueParaODisco(): void
    {
        $a      = $this->tenantEmQuarentena();
        $planas = $this->semearPlanas($a);
        FalhaDeCommitArmavel::perderRespostaDoProximoCommit();
        $consulta = new ConsultaDeDestinoFixa(DestinoDaTransacao::Confirmada);

        $resultado = $this->purgaCom($consulta, new LoggerEmMemoria())
            ->executar($this->tenant($a), dryRun: false);

        self::assertSame([$this->xidDaTransacao()], $consulta->perguntados, 'o banco é perguntado pela transação da purga');
        self::assertSame(5, $resultado->arquivosRemovidos);
        foreach ($planas as $caminho) {
            self::assertFileDoesNotExist($caminho);
        }
    }

    #[TestDox('COMMIT da purga recusado e o banco PROVA aborted: a exceção original sobe, o escritório e os arquivos ficam')]
    public function testCommitDesfeitoNaoTocaNada(): void
    {
        $a      = $this->tenantEmQuarentena();
        $planas = $this->semearPlanas($a);
        FalhaDeCommitArmavel::recusarProximoCommit();
        $consulta = new ConsultaDeDestinoFixa(DestinoDaTransacao::NaoConfirmada);
        $xid      = $this->xidDaTransacao();

        $capturada = null;
        try {
            $this->purgaCom($consulta, new LoggerEmMemoria())
                ->executar($this->tenant($a), dryRun: false);
        } catch (\Throwable $e) {
            $capturada = $e;
        }

        self::assertInstanceOf(\Doctrine\DBAL\Exception::class, $capturada, 'a exceção original, e não a de destino incerto');
        self::assertStringContainsString('COMMIT simulado: recusado', $capturada->getMessage(), 'a causa que o operador vê é a do COMMIT');
        self::assertSame([$xid], $consulta->perguntados);
        self::assertSame(1, $this->existeTenant($a), 'o banco desfez: "nada foi apagado" é verdade');
        foreach ($planas as $caminho) {
            self::assertFileExists($caminho);
        }
    }

    /**
     * O que a purga faz com a resposta do prefixo que sai só em parte: os itens que ficaram vão
     * para o resultado (e daí para o aviso e o FAILURE do comando), não só para o log.
     */
    #[TestDox('diretório do escritório que sai só em parte: o banco é purgado e cada sobra é reportada')]
    public function testPrefixoQueSaiEmParteEhReportado(): void
    {
        if (\function_exists('posix_geteuid') && posix_geteuid() === 0) {
            self::markTestSkipped('root ignora permissão de diretório');
        }

        $a        = $this->tenantEmQuarentena();
        $trancado = $this->arquivo('cobrancas_uploads_dir', $a . '/a-trancada/b.pdf');
        $livre    = $this->arquivo('cobrancas_uploads_dir', $a . '/z.pdf');

        $this->diretorioComModoAlterado = \dirname($trancado);
        $this->modoOriginal             = fileperms($this->diretorioComModoAlterado) & 0o7777;
        chmod($this->diretorioComModoAlterado, 0o555);

        try {
            $resultado = $this->purgar($a);
        } finally {
            chmod($this->diretorioComModoAlterado, $this->modoOriginal);
        }

        self::assertSame(0, $this->existeTenant($a), 'o banco é autoritativo');
        self::assertFileExists($trancado, 'órfão recuperável');
        self::assertFileDoesNotExist($livre, 'o que vinha depois da falha também saiu');
        self::assertSame(1, $resultado->arquivosRemovidos);
        self::assertTrue($resultado->teveArquivoNaoRemovido());
        $reportados = implode("\n", $resultado->arquivosNaoRemovidos);
        self::assertStringContainsString('cobranca_documento/a-trancada/b.pdf', $reportados);
        self::assertStringContainsString('cobranca_documento/a-trancada/', $reportados);
        self::assertStringNotContainsString($this->parametro('cobrancas_uploads_dir'), $reportados, 'sem caminho absoluto de disco');
    }

    /**
     * Um logger que lança não pode mudar o desfecho que o banco decidiu: nem transformar o
     * resultado INCERTO em "nada foi apagado", nem fazer a purga confirmada lançar.
     */
    #[TestDox('logger que lança: COMMIT incerto continua PurgaComDestinoIncerto')]
    public function testLoggerQueFalhaNaoEscondeOCommitIncerto(): void
    {
        $a              = $this->tenantEmQuarentena();
        $planas         = $this->semearPlanas($a);
        $logger         = new LoggerEmMemoria();
        $logger->falhar = true;
        FalhaDeCommitArmavel::perderRespostaDoProximoCommit();

        $capturada = null;
        try {
            $this->purgaCom(new ConsultaDeDestinoFixa(DestinoDaTransacao::Incerta), $logger)
                ->executar($this->tenant($a), dryRun: false);
        } catch (\Throwable $e) {
            $capturada = $e;
        }

        self::assertInstanceOf(PurgaComDestinoIncerto::class, $capturada);
        self::assertNotEmpty($logger->doNivel('error'), 'o registro foi tentado');
        foreach ($planas as $caminho) {
            self::assertFileExists($caminho);
        }
    }

    #[TestDox('logger que lança: purga confirmada com sobra no disco não lança e reporta a sobra')]
    public function testLoggerQueFalhaNaoFazAPurgaConfirmadaLancar(): void
    {
        $a = $this->tenantEmQuarentena();
        $b = $this->tenantEmQuarentena();
        $this->semearPlanas($a);
        $nome    = $this->nome();
        $caminho = $this->arquivo('clientes_uploads_dir', $nome);
        $this->registrar('cliente_documento', $a, $nome);
        $this->registrar('cliente_documento', $b, $nome);
        $logger         = new LoggerEmMemoria();
        $logger->falhar = true;

        $resultado = $this->purgaCom(new ConsultaDeDestinoFixa(DestinoDaTransacao::Incerta), $logger)
            ->executar($this->tenant($a), dryRun: false);

        self::assertSame(0, $this->existeTenant($a));
        self::assertFileExists($caminho);
        self::assertSame(5, $resultado->arquivosRemovidos);
        self::assertCount(1, $resultado->arquivosNaoRemovidos);
        self::assertNotEmpty($logger->doNivel('error'), 'o registro foi tentado');
    }

    #[TestDox('simulação: conta o que seria removido e o que ficaria, sem apagar nada')]
    public function testSimulacaoMostraOsArquivos(): void
    {
        $a     = $this->tenantEmQuarentena();
        $b     = $this->tenantEmQuarentena();
        $planas = $this->semearPlanas($a);
        $this->arquivo('uploads_dir', $a . '/img.png');
        $compartilhado = $this->nome();
        $this->arquivo('clientes_uploads_dir', $compartilhado);
        $this->registrar('cliente_documento', $a, $compartilhado);
        $this->registrar('cliente_documento', $b, $compartilhado);

        $resultado = static::getContainer()->get(PurgarEscritorioUseCase::class)->executar($this->tenant($a), dryRun: true);

        self::assertTrue($resultado->simulado);
        self::assertSame(6, $resultado->arquivosPrevistos, '5 planos + 1 imagem do editor');
        self::assertSame(0, $resultado->arquivosRemovidos);
        self::assertCount(1, $resultado->arquivosNaoRemovidos);
        self::assertSame(1, $this->existeTenant($a));
        foreach ($planas as $caminho) {
            self::assertFileExists($caminho);
        }
    }

    /**
     * Cada nome só é procurado na categoria da própria tabela. O produto cartesiano antigo tentava
     * todo nome em quatro diretórios: um nome do escritório purgado que coincidisse com um arquivo
     * de outra categoria (de outro escritório) apagava esse arquivo.
     */
    #[TestDox('um nome de uma categoria não apaga arquivo de mesmo nome em outra categoria')]
    public function testNomeSoEhProcuradoNaPropriaCategoria(): void
    {
        $a        = $this->tenantEmQuarentena();
        $b        = $this->tenantEmQuarentena();
        $nome     = $this->nome();
        $ts       = '2020-01-01 00:00:00';
        $cliente  = $this->ins('cliente', ['email' => 'cli' . uniqid() . '@x.com', 'cep' => '00000-000', 'endereco' => 'Rua', 'cidade' => 'Cidade', 'estado' => 'SP', 'criado_at' => $ts, 'tipo' => 'PF', 'tenant_id' => $a]);
        $this->ins('cliente_documento', ['titulo' => 'Doc', 'categoria' => 'geral', 'caminho_arquivo' => $nome, 'nome_original' => 'd.pdf', 'mime_type' => 'application/pdf', 'tamanho_bytes' => 1, 'uploaded_at' => $ts, 'cliente_id' => $cliente, 'tenant_id' => $a]);
        $doA  = $this->arquivo('clientes_uploads_dir', $nome);
        $deB  = $this->arquivo('uploads_dir', $nome);
        $this->pastaDocumento($b, $nome);

        $resultado = $this->purgar($a);

        self::assertFileDoesNotExist($doA);
        self::assertFileExists($deB, 'o arquivo de pasta do B tem o mesmo nome, mas é de outra categoria');
        self::assertSame(1, $resultado->arquivosRemovidos);
    }

    /**
     * Um anexo de OUTRO escritório pendurado num mural deste: a linha cai pelo CASCADE do mural,
     * mas a prova de pertencimento exige que anexo, card e mural concordem — o arquivo fica.
     */
    #[TestDox('Kanban: anexo cuja cadeia não é toda do escritório fica no disco e é reportado')]
    public function testKanbanComCadeiaDivergenteNaoApaga(): void
    {
        $a = $this->tenantEmQuarentena();
        $b = $this->tenantEmQuarentena();
        [, $card] = $this->mural($a);
        $nome     = $this->nome();
        $caminho  = $this->arquivo('kanban_uploads_dir', $nome);
        $this->ins('kanban_anexo', ['nome_original' => 'k.pdf', 'caminho' => $nome, 'tamanho' => 1, 'mime_type' => 'application/pdf', 'criado_em' => '2020-01-01 00:00:00', 'card_id' => $card, 'criado_por_id' => $this->userId, 'tenant_id' => $b]);

        $resultado = $this->purgar($a);

        self::assertFileExists($caminho);
        self::assertCount(1, $resultado->arquivosNaoRemovidos);
        self::assertStringContainsString('kanban_anexo', $resultado->arquivosNaoRemovidos[0]);
    }

    #[TestDox('prefixos físicos: pastas/<id> e cobrancas/<id> saem inteiros — ocultos e subpastas — e o vizinho e o import-tmp ficam')]
    public function testPrefixosFisicosSaemInteiros(): void
    {
        $a = $this->tenantEmQuarentena();
        $b = $this->tenantEmQuarentena();

        $this->arquivo('uploads_dir', $a . '/img.png');
        $this->arquivo('uploads_dir', $a . '/.oculto');
        $this->arquivo('cobrancas_uploads_dir', $a . '/doc.pdf');
        $this->arquivo('cobrancas_uploads_dir', $a . '/.compress_abc');
        $this->arquivo('cobrancas_uploads_dir', $a . '/sub/antigo.pdf');
        $vizinho   = $this->arquivo('cobrancas_uploads_dir', $b . '/doc.pdf');
        $importTmp = $this->arquivo('cobrancas_uploads_dir', 'import-tmp/' . $a . '/previa.xlsx');

        $resultado = $this->purgar($a);

        self::assertDirectoryDoesNotExist($this->parametro('uploads_dir') . '/' . $a);
        self::assertDirectoryDoesNotExist($this->parametro('cobrancas_uploads_dir') . '/' . $a);
        self::assertFileExists($vizinho);
        self::assertFileExists($importTmp, 'import-tmp é irmão do prefixo, não filho (D3) — dívida registrada');
        self::assertSame(5, $resultado->arquivosRemovidos);
        self::assertSame([], $resultado->arquivosNaoRemovidos);
    }

    /**
     * Reproduz o defeito medido na investigação: com `pastas/<id> -> pastas`, a purga antiga
     * (`glob` + `is_file`, que seguem link) apagava os documentos de TODOS os escritórios.
     */
    #[TestDox('prefixo que é link para a raiz compartilhada: nada é apagado, o banco é purgado e a sobra é reportada')]
    public function testPrefixoQueEhLinkNaoApagaARaiz(): void
    {
        $a     = $this->tenantEmQuarentena();
        $b     = $this->tenantEmQuarentena();
        $nomeB = $this->nome();
        $docB  = $this->arquivo('uploads_dir', $nomeB);
        $this->pastaDocumento($b, $nomeB);

        $link = $this->parametro('uploads_dir') . '/' . $a;
        symlink('.', $link);
        $this->criados[] = $link;

        $resultado = $this->purgar($a);

        self::assertFileExists($docB, 'a purga do A seguiu o link e apagou o acervo do B');
        self::assertTrue(is_link($link));
        self::assertSame(0, $this->existeTenant($a));
        self::assertCount(1, $resultado->arquivosNaoRemovidos);
        self::assertStringContainsString('prefixo pasta_imagem_editor', $resultado->arquivosNaoRemovidos[0]);
    }

    /**
     * `documento_processo` saiu da purga (decisão do dono). O produto cartesiano antigo tentava
     * cada nome dela em quatro diretórios alheios — este teste prova que um nome coincidente com
     * um arquivo real de `pastas/` não o apaga mais.
     */
    #[TestDox('documento_processo não é mais consultado: um nome coincidente em pastas/ não é apagado')]
    public function testDocumentoProcessoNaoApagaNada(): void
    {
        $a       = $this->tenantEmQuarentena();
        $nome    = $this->nome();
        $caminho = $this->arquivo('uploads_dir', $nome);
        $proc    = $this->ins('processo', ['numero_processo' => 'PROC-' . uniqid(), 'orgao_julgador' => 'OJ', 'sigla_tribunal' => 'TJSP', 'classe_processual' => 'CLS', 'assunto_processual' => 'ASS', 'situacao_processo' => 'ativo', 'instancia' => '1', 'tenant_id' => $a]);
        $this->ins('documento_processo', ['processo_id' => $proc, 'tipo' => 'peticao', 'nome_original' => 'dp.pdf', 'caminho_arquivo' => $nome, 'criado_em' => '2020-01-01 00:00:00', 'tenant_id' => $a]);

        $resultado = $this->purgar($a);

        self::assertFileExists($caminho);
        self::assertSame(0, $resultado->arquivosRemovidos);
        self::assertSame(0, $this->existeTenant($a));
    }

    #[TestDox('anexos de Tarefa ficam fora da purga (E2.7) e aparecem no resultado — sem virar chave')]
    public function testTarefaFicaForaEEhReportada(): void
    {
        $a      = $this->tenantEmQuarentena();
        $pasta  = $this->ins('pasta', ['nup' => 'NUP-' . uniqid(), 'data_abertura' => '2020-01-01 00:00:00', 'created_at' => '2020-01-01 00:00:00', 'tenant_id' => $a]);
        $tarefa = $this->ins('tarefa', ['pasta_id' => $pasta, 'titulo' => 'T', 'descricao' => 'd', 'status' => 'pendente', 'data_criacao' => '2020-01-01 00:00:00', 'tenant_id' => $a]);
        $valor  = '/uploads/tarefas/chat/arquivo_' . uniqid('', true) . '.pdf';
        $this->ins('tarefa_mensagem', ['tarefa_id' => $tarefa, 'usuario_id' => $this->userId, 'mensagem' => 'm', 'criado_em' => '2020-01-01 00:00:00', 'tenant_id' => $a, 'arquivo_anexo' => $valor]);

        $resultado = $this->purgar($a);

        self::assertSame([$valor], $resultado->arquivosForaDoEscopo);
        self::assertTrue($resultado->teveSobraNoDisco());
        self::assertFalse($resultado->teveArquivoNaoRemovido(), 'Tarefa é dívida conhecida, não anomalia');
        self::assertSame([], $resultado->arquivosNaoRemovidos);
    }

    /** COMMIT confirmado seguido de falha física: a purga não lança, e a sobra fica registrada. */
    #[TestDox('disco recusa depois do COMMIT: o escritório é purgado, nada é lançado e a sobra é reportada')]
    public function testDiscoQueFalhaDepoisDoCommitNaoLanca(): void
    {
        if (\function_exists('posix_geteuid') && posix_geteuid() === 0) {
            self::markTestSkipped('root ignora permissão de diretório');
        }

        $a      = $this->tenantEmQuarentena();
        $planas = $this->semearPlanas($a);

        $this->diretorioComModoAlterado = $this->parametro('clientes_uploads_dir');
        $this->modoOriginal             = fileperms($this->diretorioComModoAlterado) & 0o7777;
        chmod($this->diretorioComModoAlterado, 0o555);

        try {
            $resultado = $this->purgar($a);
        } finally {
            chmod($this->diretorioComModoAlterado, $this->modoOriginal);
        }

        self::assertSame(0, $this->existeTenant($a), 'o banco é autoritativo');
        self::assertFileExists($planas['cliente'], 'órfão recuperável');
        self::assertFileDoesNotExist($planas['pasta'], 'a falha de um não segura os outros');
        self::assertCount(1, $resultado->arquivosNaoRemovidos);
        self::assertStringContainsString('cliente_documento', $resultado->arquivosNaoRemovidos[0]);
        self::assertSame(4, $resultado->arquivosRemovidos);
    }

    /**
     * R1: no disco o escopo das categorias planas é invisível. Contra o dublê em memória, as chaves
     * da purga têm de carregar o escritório PURGADO — um arquivo de mesmo nome com escopo de outro
     * escritório não pode ser tocado, e os prefixos são os do escritório purgado.
     */
    #[TestDox('R1: as chaves e os prefixos da purga carregam o escopo do escritório purgado')]
    public function testEscopoDasChavesEhODoEscritorioPurgado(): void
    {
        $a = $this->tenantEmQuarentena();
        $b = $this->tenantEmQuarentena();
        $nome = $this->nome();
        $this->pastaDocumento($a, $nome);

        $memoria = new ArmazenamentoEmMemoria();
        $minha   = new ChaveDeArquivo(EscopoDeArquivo::deTenant($a), CategoriaDeArquivo::PASTA_DOCUMENTO, $nome);
        $alheia  = new ChaveDeArquivo(EscopoDeArquivo::deTenant($b), CategoriaDeArquivo::PASTA_DOCUMENTO, $nome);
        $memoria->semear($minha);
        $memoria->semear($alheia);

        $logger = new LoggerEmMemoria();
        $purga  = new PurgarEscritorioUseCase(
            $this->em,
            $memoria,
            new RemocaoAposTransacao($memoria, $logger),
            $memoria,
            new ConsultaDeDestinoFixa(DestinoDaTransacao::Incerta),
            $logger,
            (int) static::getContainer()->getParameter('tenant_carencia_purga_dias'),
        );

        $purga->executar($this->tenant($a), dryRun: false);

        self::assertFalse($memoria->existe($minha));
        self::assertTrue($memoria->existe($alheia), 'mesmo nome, outro escritório: intocado');
        self::assertSame(
            [
                EscopoDeArquivo::deTenant($a)->comoTexto() . '/pasta_imagem_editor',
                EscopoDeArquivo::deTenant($a)->comoTexto() . '/cobranca_documento',
            ],
            $memoria->prefixosExcluidos,
        );
    }

    // ----------------------------------------------------------------- cenário

    /** @var array<string, string> tabela → parâmetro do diretório */
    private const DIRETORIO_DE = [
        'cliente_documento'   => 'clientes_uploads_dir',
        'pasta_documento'     => 'uploads_dir',
        'justificativa_ponto' => 'justificativas_uploads_dir',
        'chamado_anexo'       => 'chamados_uploads_dir',
        'kanban_anexo'        => 'kanban_uploads_dir',
    ];

    /** Uma linha de `$tabela`, do escritório `$t`, apontando para `$nome` — com a cadeia coerente. */
    private function registrar(string $tabela, int $t, string $nome): void
    {
        $ts = '2020-01-01 00:00:00';

        match ($tabela) {
            'cliente_documento' => $this->ins('cliente_documento', ['titulo' => 'Doc', 'categoria' => 'geral', 'caminho_arquivo' => $nome, 'nome_original' => 'd.pdf', 'mime_type' => 'application/pdf', 'tamanho_bytes' => 1, 'uploaded_at' => $ts, 'tenant_id' => $t,
                'cliente_id' => $this->ins('cliente', ['email' => 'cli' . uniqid() . '@x.com', 'cep' => '00000-000', 'endereco' => 'Rua', 'cidade' => 'Cidade', 'estado' => 'SP', 'criado_at' => $ts, 'tipo' => 'PF', 'tenant_id' => $t])]),
            'pasta_documento' => $this->pastaDocumento($t, $nome),
            'justificativa_ponto' => $this->ins('justificativa_ponto', ['data' => '2020-01-01', 'status' => 'pendente', 'user_id' => $this->userId, 'abono_parcial' => false, 'tenant_id' => $t, 'anexo_path' => $nome]),
            'chamado_anexo' => $this->ins('chamado_anexo', ['nome_original' => 'a.pdf', 'nome_arquivo' => $nome, 'mime_type' => 'application/pdf', 'tamanho' => 1, 'criado_em' => $ts, 'enviado_por_id' => $this->userId,
                'chamado_id' => $this->ins('chamado', ['solicitante_id' => $this->userId, 'titulo' => 'C', 'descricao' => 'd', 'categoria' => 'geral', 'prioridade' => 'baixa', 'status' => 'aberto', 'criado_em' => $ts, 'tenant_id' => $t])]),
            'kanban_anexo' => $this->ins('kanban_anexo', ['nome_original' => 'k.pdf', 'caminho' => $nome, 'tamanho' => 1, 'mime_type' => 'application/pdf', 'criado_em' => $ts, 'criado_por_id' => $this->userId, 'tenant_id' => $t,
                'card_id' => $this->mural($t)[1]]),
        };
    }

    private function purgaCom(ConsultaDeDestinoDaTransacao $consulta, LoggerEmMemoria $logger): PurgarEscritorioUseCase
    {
        $container = static::getContainer();
        $disco     = $container->get(ArmazenamentoDeArquivos::class);

        return new PurgarEscritorioUseCase(
            $this->em,
            $disco,
            new RemocaoAposTransacao($disco, $logger),
            $container->get(ArmazenamentoLocal::class),
            $consulta,
            $logger,
            (int) $container->getParameter('tenant_carencia_purga_dias'),
        );
    }

    /** @return array<string, string> categoria → caminho do arquivo real */
    private function semearPlanas(int $t): array
    {
        $ts = '2020-01-01 00:00:00';

        $cliente    = $this->ins('cliente', ['email' => 'cli' . uniqid() . '@x.com', 'cep' => '00000-000', 'endereco' => 'Rua', 'cidade' => 'Cidade', 'estado' => 'SP', 'criado_at' => $ts, 'tipo' => 'PF', 'tenant_id' => $t]);
        $nomeCli    = $this->nome();
        $this->ins('cliente_documento', ['titulo' => 'Doc', 'categoria' => 'geral', 'caminho_arquivo' => $nomeCli, 'nome_original' => 'd.pdf', 'mime_type' => 'application/pdf', 'tamanho_bytes' => 1, 'uploaded_at' => $ts, 'cliente_id' => $cliente, 'tenant_id' => $t]);

        $nomePasta = $this->nome();
        $this->pastaDocumento($t, $nomePasta);

        $nomeAtestado = $this->nome();
        foreach (['2020-01-01', '2020-01-02'] as $dia) {
            $this->ins('justificativa_ponto', ['data' => $dia, 'status' => 'pendente', 'user_id' => $this->userId, 'abono_parcial' => false, 'tenant_id' => $t, 'anexo_path' => $nomeAtestado, 'batch_id' => 'lote-' . $t]);
        }

        $chamado     = $this->ins('chamado', ['solicitante_id' => $this->userId, 'titulo' => 'C', 'descricao' => 'd', 'categoria' => 'geral', 'prioridade' => 'baixa', 'status' => 'aberto', 'criado_em' => $ts, 'tenant_id' => $t]);
        $nomeChamado = $this->nome();
        $this->ins('chamado_anexo', ['chamado_id' => $chamado, 'nome_original' => 'a.pdf', 'nome_arquivo' => $nomeChamado, 'mime_type' => 'application/pdf', 'tamanho' => 1, 'criado_em' => $ts, 'enviado_por_id' => $this->userId]);

        [, $card]   = $this->mural($t);
        $nomeKanban = $this->nome();
        $this->ins('kanban_anexo', ['nome_original' => 'k.pdf', 'caminho' => $nomeKanban, 'tamanho' => 1, 'mime_type' => 'application/pdf', 'criado_em' => $ts, 'card_id' => $card, 'criado_por_id' => $this->userId, 'tenant_id' => $t]);

        return [
            'cliente'       => $this->arquivo('clientes_uploads_dir', $nomeCli),
            'pasta'         => $this->arquivo('uploads_dir', $nomePasta),
            'justificativa' => $this->arquivo('justificativas_uploads_dir', $nomeAtestado),
            'chamado'       => $this->arquivo('chamados_uploads_dir', $nomeChamado),
            'kanban'        => $this->arquivo('kanban_uploads_dir', $nomeKanban),
        ];
    }

    /** @return array{int, int} mural e card */
    private function mural(int $t): array
    {
        $ts    = '2020-01-01 00:00:00';
        $board = $this->ins('kanban_board', ['nome' => 'B', 'criado_em' => $ts, 'tenant_id' => $t, 'criado_por_id' => $this->userId]);
        $col   = $this->ins('kanban_coluna', ['nome' => 'Col', 'tipo' => 'todo', 'posicao' => 0, 'board_id' => $board, 'tenant_id' => $t]);
        $card  = $this->ins('kanban_card', ['titulo' => 'Card', 'posicao' => 0, 'criado_em' => $ts, 'coluna_id' => $col, 'board_id' => $board, 'criado_por_id' => $this->userId, 'tenant_id' => $t]);

        return [$board, $card];
    }

    private function pastaDocumento(int $t, string $nome): int
    {
        $ts    = '2020-01-01 00:00:00';
        $pasta = $this->ins('pasta', ['nup' => 'NUP-' . uniqid(), 'data_abertura' => $ts, 'created_at' => $ts, 'tenant_id' => $t]);
        return $this->ins('pasta_documento', ['titulo' => 'PDoc', 'categoria' => 'geral', 'caminho_arquivo' => $nome, 'nome_original' => 'p.pdf', 'mime_type' => 'application/pdf', 'tamanho_bytes' => 1, 'uploaded_at' => $ts, 'pasta_id' => $pasta, 'tenant_id' => $t]);
    }

    /**
     * O xid da transação em que o teste roda. Sob o DAMA a purga abre a dela por dentro da do teste
     * (savepoint), e `pg_current_xact_id()` devolve o da transação de topo: é o mesmo.
     */
    private function xidDaTransacao(): string
    {
        return (string) $this->conn->fetchOne('SELECT pg_current_xact_id()::text');
    }

    private function tenantEmQuarentena(): int
    {
        $tenant = new Tenant();
        $tenant->setName('Tenant E25 ' . uniqid());
        $tenant->setIsActive(false);
        $tenant->setExcluidoEm(new \DateTimeImmutable('-400 days'));
        $this->em->persist($tenant);
        $this->em->flush();

        return (int) $tenant->getId();
    }

    private function tenant(int $id): Tenant
    {
        $tenant = $this->em->find(Tenant::class, $id);
        self::assertNotNull($tenant);

        return $tenant;
    }

    private function purgar(int $tenantId): PurgaEscritorioResultado
    {
        return static::getContainer()->get(PurgarEscritorioUseCase::class)->executar($this->tenant($tenantId), dryRun: false);
    }

    private function existeTenant(int $tenantId): int
    {
        return (int) $this->conn->fetchOne('SELECT COUNT(*) FROM tenant WHERE id = ?', [$tenantId]);
    }

    private function nome(): string
    {
        return bin2hex(random_bytes(16)) . '.pdf';
    }

    private function parametro(string $nome): string
    {
        return rtrim((string) static::getContainer()->getParameter($nome), '/');
    }

    /** Cria o arquivo e registra para limpeza; devolve o caminho completo. */
    private function arquivo(string $parametro, string $relativo): string
    {
        $raiz      = $this->parametro($parametro);
        $caminho   = $raiz . '/' . $relativo;
        $diretorio = \dirname($caminho);

        // O diretório mais alto que ESTE teste cria (`<id>/`, `import-tmp/<id>/`) sai inteiro no fim.
        $maisAlto = null;
        for ($d = $diretorio; $d !== $raiz && str_starts_with($d, $raiz . '/'); $d = \dirname($d)) {
            if (!is_dir($d)) {
                $maisAlto = $d;
            }
        }
        if ($maisAlto !== null && !\in_array($maisAlto, $this->diretoriosCriados, true)) {
            $this->diretoriosCriados[] = $maisAlto;
        }

        if (!is_dir($diretorio)) {
            mkdir($diretorio, 0o775, true);
        }

        file_put_contents($caminho, 'x');
        $this->criados[] = $caminho;

        return $caminho;
    }

    /** @param array<string, mixed> $dados */
    private function ins(string $tabela, array $dados): int
    {
        $cols  = implode(', ', array_map(static fn (string $c): string => '"' . $c . '"', array_keys($dados)));
        $ph    = implode(', ', array_map(static fn (string $c): string => ':' . $c, array_keys($dados)));
        $tipos = [];
        foreach ($dados as $col => $valor) {
            if (\is_bool($valor)) {
                $tipos[$col] = ParameterType::BOOLEAN;
            }
        }

        return (int) $this->conn->fetchOne(
            sprintf('INSERT INTO %s (%s) VALUES (%s) RETURNING id', $tabela, $cols, $ph),
            $dados,
            $tipos,
        );
    }

    private function removerArvore(string $caminho): void
    {
        if (is_link($caminho) || is_file($caminho)) {
            @unlink($caminho);

            return;
        }

        if (!is_dir($caminho)) {
            return;
        }

        foreach (scandir($caminho) ?: [] as $entrada) {
            if ($entrada !== '.' && $entrada !== '..') {
                $this->removerArvore($caminho . '/' . $entrada);
            }
        }

        @rmdir($caminho);
    }
}
