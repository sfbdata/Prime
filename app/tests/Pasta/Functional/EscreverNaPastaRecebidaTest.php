<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Entity\Auth\User;
use App\Entity\Notificacao;
use App\Entity\Tenant\Tenant;
use App\Pasta\Controller\EscritaNaPastaRecebidaController;
use App\Pasta\Entity\AbaDaPasta;
use App\Pasta\Entity\AbaOcultaDaPasta;
use App\Pasta\Entity\ArquivoArmazenado;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaAcompanhamentoDesligado;
use App\Pasta\Entity\PastaAndamento;
use App\Pasta\Entity\PastaCompartilhamento;
use App\Pasta\Entity\PastaDocumento;
use App\Pasta\Entity\PastaMensagem;
use App\Pasta\Entity\PermissoesDoCompartilhamento;
use App\Pasta\Repository\ArquivoArmazenadoRepository;
use App\Pasta\Service\MencoesDoRegistro;
use App\Pasta\UseCase\AnotarNaPastaRecebidaUseCase;
use App\Pasta\UseCase\EnviarDocumentoNaPastaRecebidaUseCase;
use App\Pasta\UseCase\MudarFaseDaPastaRecebidaUseCase;
use App\Shared\Armazenamento\CategoriaDeArquivo;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Tests\Shared\Doubles\ArmazenamentoEmMemoriaNoContainer;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Fatia 9b da spec `docs/specs/acesso-restrito-e-compartilhar.md` (§4.5.1, mini-adendo "escrita"): quem recebeu a pasta
 * de outra conta e aceitou anota, troca a fase e envia arquivo pelas rotas `expediente_recebida_anotar|fase|
 * documento_enviar`, sem sair do próprio escritório (B). A pasta é do escritório A. Cobre a lista "Testes obrigatórios
 * (9b)" do adendo e a tela (os campos só com a caixa).
 *
 * O storage é o dublê em memória (instalado antes da primeira requisição): é ele que mostra o escopo da chave gravada e
 * o que foi apagado.
 */
#[CoversClass(EscritaNaPastaRecebidaController::class)]
#[CoversClass(AnotarNaPastaRecebidaUseCase::class)]
#[CoversClass(MudarFaseDaPastaRecebidaUseCase::class)]
#[CoversClass(EnviarDocumentoNaPastaRecebidaUseCase::class)]
final class EscreverNaPastaRecebidaTest extends JusPrimeWebTestCase
{
    use CriaCenarioDoCadeadoFuncionalTrait;

    private const PERMISSOES = ['modules.expediente.view', 'modules.pastas.view', 'resources.pasta.view', 'resources.pasta.edit'];

    private const PDF = "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n";

    private const ROTAS = ['anotar', 'fase', 'documento'];

    private Tenant $contaA;
    private Tenant $contaB;
    private User $donoA;
    private User $olga;
    private User $bia;
    private Pasta $pasta;
    private ArmazenamentoEmMemoriaNoContainer $storage;
    /** @var list<string> */
    private array $temporarios = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararCliente();
        $this->storage = ArmazenamentoEmMemoriaNoContainer::instalarEm(static::getContainer());
        [$this->donoA, $this->contaA] = $this->criarEscritorio();
        [, $this->contaB] = $this->criarEscritorio();
        $this->olga = $this->criarColaborador($this->contaA, 'Olga Origem', self::PERMISSOES);
        $this->bia = $this->criarColaborador($this->contaB, 'Bia Parceira', self::PERMISSOES);
        $this->pasta = $this->criarPasta($this->contaA, 'CLIENTE DA CONTA A');
        $this->pasta->setResponsavel($this->donoA);
        $this->em()->flush();
    }

    protected function tearDown(): void
    {
        foreach ($this->temporarios as $arquivo) {
            if (is_file($arquivo)) {
                unlink($arquivo);
            }
        }
        parent::tearDown();
    }

    // ── Anotar ─────────────────────────────────────────────────────────────

    #[TestDox('anotar: grava em A com a autora de B; a auditoria vai para A com a autora; quem acompanha em A é avisado, quem desligou e quem anotou não')]
    public function testAnotarGravaEmA(): void
    {
        $this->em()->persist(new PastaAcompanhamentoDesligado($this->contaA, $this->olga, $this->pasta));
        $this->em()->flush();
        $id = $this->convite(new PermissoesDoCompartilhamento(true, false, false));
        $this->entrar($this->bia, $this->contaB);

        $this->anotar($id, 'ANOTAÇÃO DA PARCEIRA');

        self::assertResponseStatusCodeSame(201);
        $linha = $this->ultimaAnotacao();
        self::assertSame([(int) $this->contaA->getId(), (int) $this->bia->getId(), 'ANOTAÇÃO DA PARCEIRA', null], [(int) $linha['tenant_id'], (int) $linha['autor_id'], $linha['conteudo'], $linha['resposta_a_id']]);
        self::assertContains(PastaMensagem::class, $this->auditoriaEmA());
        self::assertSame([(int) $this->donoA->getId()], $this->avisados(Notificacao::TIPO_PASTA_ACOMPANHADA), 'só quem acompanha em A; a Olga desligou');
        self::assertSame([], $this->avisosDe($this->bia), 'quem anotou nunca é avisado');
        $this->assertFiltroLigadoEmB();
    }

    #[TestDox('anotar: o prazo escrito no texto nasce na pasta de A')]
    public function testAnotacaoComPrazoCriaOPrazo(): void
    {
        $id = $this->convite(new PermissoesDoCompartilhamento(true, false, false));
        $this->entrar($this->bia, $this->contaB);

        $this->anotar($id, 'Audiência de instrução em ' . (new \DateTimeImmutable('+10 days'))->format('d/m/Y') . ' às 14h30.');

        self::assertResponseStatusCodeSame(201);
        self::assertSame(1, $this->contar('SELECT COUNT(*) FROM pasta_prazo WHERE pasta_id = :p AND tenant_id = :a AND origem = :o', ['o' => 'registro']));
        $this->assertFiltroLigadoEmB();
    }

    /** @return iterable<string, array{0: string}> */
    public static function mencoes(): iterable
    {
        yield '@ em texto puro' => ['Fale com @[Rotulo Falso](user:%d) hoje'];
        yield '@ no HTML do editor' => ['<p>Fale com @[Rotulo Falso](user:%d) hoje</p>'];
        yield '&#64; no HTML do editor' => ['<p>Fale com &#64;[Rotulo Falso](user:%d) hoje</p>'];
        yield '&commat; no HTML do editor' => ['<p>Fale com &commat;[Rotulo Falso](user:%d) hoje</p>'];
        yield '&#x40; no HTML do editor' => ['<p>Fale com &#x40;[Rotulo Falso](user:%d) hoje</p>'];
    }

    #[DataProvider('mencoes')]
    #[TestDox('anotar: a menção some ($_dataName): nenhum token nem nome de A no texto gravado, e ninguém de A é avisado por ela')]
    public function testMencaoSome(string $modelo): void
    {
        $id = $this->convite(new PermissoesDoCompartilhamento(true, false, false));
        $this->entrar($this->bia, $this->contaB);

        $this->anotar($id, sprintf($modelo, (int) $this->olga->getId()));

        self::assertResponseStatusCodeSame(201);
        $gravado = (string) $this->ultimaAnotacao()['conteudo'];
        self::assertSame(0, preg_match(MencoesDoRegistro::PADRAO, $gravado), 'token gravado: ' . $gravado);
        self::assertStringNotContainsString('user:', $gravado);
        self::assertStringNotContainsString('Olga', $gravado);
        self::assertStringContainsString('@Rotulo Falso', $gravado);
        self::assertSame([], $this->avisados(Notificacao::TIPO_PASTA_MENCAO_REGISTRO));
    }

    #[TestDox('anotar: o "resposta_a" do pedido é ignorado; nasce anotação nova e o autor do registro não é avisado como respondido')]
    public function testRespostaAIgnorada(): void
    {
        $registro = (new PastaMensagem())->setPasta($this->pasta)->setTenant($this->contaA)->setAutor($this->donoA)->setConteudo('REGISTRO DA EQUIPE A');
        $this->em()->persist($registro);
        $this->em()->flush();
        $id = $this->convite(new PermissoesDoCompartilhamento(true, false, false));
        $this->entrar($this->bia, $this->contaB);

        $this->anotar($id, 'RESPOSTA QUE NÃO É RESPOSTA', ['resposta_a' => (string) $registro->getId()]);

        self::assertResponseStatusCodeSame(201);
        $linha = $this->ultimaAnotacao();
        self::assertSame(['RESPOSTA QUE NÃO É RESPOSTA', null], [$linha['conteudo'], $linha['resposta_a_id']]);
        self::assertFalse((bool) $linha['eh_resposta']);
        self::assertSame([], $this->avisados(Notificacao::TIPO_PASTA_RESPOSTA_REGISTRO));
    }

    // ── Fase ───────────────────────────────────────────────────────────────

    #[TestDox('fase: grava em A sem duplicar o andamento e sem mexer no contrato; o cartão da fase fica com a autora de B')]
    public function testFaseGravaSemDuplicarESemMexerNoContrato(): void
    {
        // Distribuído, com o Contrato pulado e o contrato assinado: a sincronização do contrato daria data ao Contrato.
        $this->andamentoEm(3, [2]);
        $this->pasta->setSituacaoContrato('REGULAR');
        $this->em()->flush();
        $id = $this->convite(new PermissoesDoCompartilhamento(true, false, false));
        $this->entrar($this->bia, $this->contaB);

        $this->fase($id, '5');

        self::assertResponseStatusCodeSame(200);
        $andamentos = $this->conexao()->fetchAllAssociative('SELECT atual, marcos FROM pasta_andamento WHERE pasta_id = :p', ['p' => $this->pasta->getId()]);
        self::assertCount(1, $andamentos, 'o andamento de A foi achado, não nasceu outro');
        self::assertSame(5, (int) $andamentos[0]['atual']);
        $marcos = json_decode((string) $andamentos[0]['marcos'], true, flags: JSON_THROW_ON_ERROR);
        self::assertContains(2, $marcos['pul'], 'o Contrato continua pulado: a sincronização do contrato não rodou');
        self::assertSame('REGULAR', $this->conexao()->fetchOne('SELECT situacao_contrato FROM pasta WHERE id = :p', ['p' => $this->pasta->getId()]));
        self::assertSame(['fase'], $this->conexao()->fetchFirstColumn('SELECT sistema FROM pasta_mensagem WHERE pasta_id = :p AND autor_id = :b AND tenant_id = :a', ['p' => $this->pasta->getId(), 'b' => $this->bia->getId(), 'a' => $this->contaA->getId()]));
        self::assertContains(PastaAndamento::class, $this->auditoriaEmA());
        $this->assertFiltroLigadoEmB();
    }

    #[TestDox('fase: pasta só com a fase estimada ganha UM andamento, também na segunda troca')]
    public function testFaseEstimadaNaoDuplica(): void
    {
        $id = $this->convite(new PermissoesDoCompartilhamento(true, false, false));
        $this->entrar($this->bia, $this->contaB);

        $this->fase($id, '1');
        self::assertResponseStatusCodeSame(200);
        $this->fase($id, '0');
        self::assertResponseStatusCodeSame(200);

        self::assertSame(['0'], array_map('strval', $this->conexao()->fetchFirstColumn('SELECT atual FROM pasta_andamento WHERE pasta_id = :p', ['p' => $this->pasta->getId()])));
    }

    /** @return iterable<string, array{0: int, 1: string}> fase atual e a pedida */
    public static function fasesRecusadas(): iterable
    {
        yield 'cruzar o Contrato para frente' => [1, '3'];
        yield 'cruzar o Contrato para trás' => [3, '1'];
        yield 'ir para o Contrato' => [1, '2'];
        yield 'sair do Contrato para frente' => [2, '3'];
        yield 'sair do Contrato para trás' => [2, '1'];
        yield 'finalizar' => [5, '9'];
        yield 'reativar' => [9, '9'];
        yield 'sair da Finalizada' => [9, '5'];
        yield 'fase que não existe' => [1, '99'];
        yield 'fase que não é número' => [1, 'x'];
    }

    #[DataProvider('fasesRecusadas')]
    #[TestDox('fase: $_dataName dá 422 e nada muda; o filtro de B volta ligado mesmo na recusa')]
    public function testFaseRecusada(int $atual, string $pedida): void
    {
        $this->andamentoEm($atual);
        $id = $this->convite(new PermissoesDoCompartilhamento(true, false, false));
        $this->entrar($this->bia, $this->contaB);

        $this->fase($id, $pedida);

        self::assertResponseStatusCodeSame(422);
        self::assertSame([(string) $atual], array_map('strval', $this->conexao()->fetchFirstColumn('SELECT atual FROM pasta_andamento WHERE pasta_id = :p', ['p' => $this->pasta->getId()])));
        self::assertSame(0, $this->contar('SELECT COUNT(*) FROM pasta_mensagem WHERE pasta_id = :p AND tenant_id = :a AND sistema IS NOT NULL'));
        $this->assertFiltroLigadoEmB();
    }

    // ── Enviar documento ───────────────────────────────────────────────────

    #[TestDox('documento: grava em A pela chave de A, com a autora de B; código DOC de A; auditoria em A; aviso só a quem acompanha em A')]
    public function testDocumentoGravaEmA(): void
    {
        $id = $this->convite(new PermissoesDoCompartilhamento(false, true, false));
        $this->entrar($this->bia, $this->contaB);

        $this->enviar($id, 'PECA');

        self::assertResponseStatusCodeSame(201);
        $doc = $this->conexao()->fetchAssociative('SELECT d.*, a.codigo, a.tenant_id AS arquivo_tenant FROM pasta_documento d JOIN arquivo_armazenado a ON a.id = d.arquivo_id WHERE d.pasta_id = :p', ['p' => $this->pasta->getId()]);
        self::assertIsArray($doc);
        self::assertSame(
            [(int) $this->contaA->getId(), (int) $this->bia->getId(), 'PECA', 'peticao-da-parceira.pdf', (int) $this->contaA->getId()],
            [(int) $doc['tenant_id'], (int) $doc['enviado_por_id'], $doc['categoria'], $doc['nome_original'], (int) $doc['arquivo_tenant']],
        );
        self::assertMatchesRegularExpression('/^DOC-[A-Z2-9]{4}-[A-Z2-9]{4}-[A-Z2-9]{4}$/', (string) $doc['codigo']);
        $gravada = $this->storage->memoria->ultimaGravada();
        self::assertSame(CategoriaDeArquivo::PASTA_DOCUMENTO, $gravada->categoria);
        self::assertSame((int) $this->contaA->getId(), $gravada->escopo->tenantIdOuNull(), 'a chave é do escritório A');
        self::assertSame($doc['caminho_arquivo'], $gravada->nome);
        self::assertContains(PastaDocumento::class, $this->auditoriaEmA());
        self::assertSame([(int) $this->donoA->getId(), (int) $this->olga->getId()], $this->avisados(Notificacao::TIPO_PASTA_ACOMPANHADA));
        self::assertSame([], $this->avisosDe($this->bia), 'quem enviou nunca é avisado');
        $this->assertFiltroLigadoEmB();
    }

    #[TestDox('documento: o código DOC é cunhado vendo os códigos de A (filtro de B desligado), único com outro documento de A')]
    public function testCodigoUnicoComOutroDocumentoDeA(): void
    {
        $existente = new ArquivoArmazenado($this->contaA, 'DOC-AAAA-BBBB-CCCC', 'existente.pdf', 10, null);
        $this->em()->persist($existente);
        $this->em()->flush();
        $espiao = new class (static::getContainer()->get(ManagerRegistry::class)) extends ArquivoArmazenadoRepository {
            /** @var list<array{filtro: bool, veOExistente: bool}> */
            public array $consultas = [];
            public int $tenantDoExistente = 0;

            public function existeCodigo(int $tenantId, string $codigo): bool
            {
                $this->consultas[] = [
                    'filtro'       => $this->getEntityManager()->getFilters()->isEnabled('tenant'),
                    'veOExistente' => parent::existeCodigo($this->tenantDoExistente, 'DOC-AAAA-BBBB-CCCC'),
                ];

                return parent::existeCodigo($tenantId, $codigo);
            }
        };
        $espiao->tenantDoExistente = (int) $this->contaA->getId();
        static::getContainer()->set(ArquivoArmazenadoRepository::class, $espiao);
        $id = $this->convite(new PermissoesDoCompartilhamento(false, true, false));
        $this->entrar($this->bia, $this->contaB);

        $this->enviar($id, 'DEMAIS');

        self::assertResponseStatusCodeSame(201);
        self::assertNotEmpty($espiao->consultas, 'o gerador consultou os códigos');
        foreach ($espiao->consultas as $consulta) {
            self::assertSame(['filtro' => false, 'veOExistente' => true], $consulta, 'o gerador enxerga os códigos de A');
        }
        self::assertSame(2, $this->contar('SELECT COUNT(DISTINCT codigo) FROM arquivo_armazenado WHERE tenant_id = :a'));
    }

    /** @return iterable<string, array{0: string}> */
    public static function categoriasRecusadas(): iterable
    {
        yield 'contrato de honorários' => [PastaDocumento::CATEGORIA_CONTRATO];
        yield 'financeiro' => ['FINANCEIRO'];
        yield 'categoria inventada' => ['QUALQUER'];
    }

    #[DataProvider('categoriasRecusadas')]
    #[TestDox('documento: categoria "$_dataName" dá 422, sem arquivo gravado nem documento')]
    public function testCategoriaRecusada(string $categoria): void
    {
        $id = $this->convite(new PermissoesDoCompartilhamento(false, true, false));
        $this->entrar($this->bia, $this->contaB);

        $this->enviar($id, $categoria);

        self::assertResponseStatusCodeSame(422);
        self::assertSame([], $this->storage->memoria->gravadas);
        self::assertSame(0, $this->contar('SELECT COUNT(*) FROM pasta_documento WHERE pasta_id = :p'));
        $this->assertFiltroLigadoEmB();
    }

    #[TestDox('documento: o flush falha, o arquivo novo sai do storage e o filtro de B volta ligado')]
    public function testArquivoApagadoSeOFlushFalha(): void
    {
        $id = $this->convite(new PermissoesDoCompartilhamento(false, true, false));
        $this->entrar($this->bia, $this->contaB);
        $recusa = new class () {
            public int $tentativas = 0;

            public function onFlush(OnFlushEventArgs $args): void
            {
                foreach ($args->getObjectManager()->getUnitOfWork()->getScheduledEntityInsertions() as $entidade) {
                    if ($entidade instanceof PastaDocumento) {
                        ++$this->tentativas;

                        throw new \RuntimeException('banco recusou o documento');
                    }
                }
            }
        };
        $this->em()->getEventManager()->addEventListener([Events::onFlush], $recusa);

        $this->enviar($id, 'DEMAIS');

        self::assertResponseStatusCodeSame(500);
        self::assertSame(1, $recusa->tentativas);
        self::assertCount(1, $this->storage->memoria->gravadas, 'o arquivo chegou a ser gravado');
        $gravada = $this->storage->memoria->ultimaGravada();
        self::assertFalse($this->storage->existe($gravada), 'e saiu quando o flush falhou');
        self::assertSame(0, $this->contar('SELECT COUNT(*) FROM pasta_documento WHERE pasta_id = :p'));
        $this->assertFiltroLigadoEmB();
    }

    // ── Comum às três ──────────────────────────────────────────────────────

    /** @return iterable<string, array{0: string}> */
    public static function rotas(): iterable
    {
        foreach (self::ROTAS as $rota) {
            yield $rota => [$rota];
        }
    }

    #[DataProvider('rotas')]
    #[TestDox('$rota: 403 sem a caixa e sem CSRF; nada é gravado')]
    public function testRecusaSemACaixaESemCsrf(string $rota): void
    {
        $semCaixa = $this->convite(new PermissoesDoCompartilhamento(false, false, true));
        $todas = $this->convite(new PermissoesDoCompartilhamento(true, true, true), $this->criarPasta($this->contaA, 'OUTRA PASTA DE A'));
        $this->entrar($this->bia, $this->contaB);

        $this->escrever($rota, $semCaixa);
        self::assertResponseStatusCodeSame(403, 'sem a caixa');
        $this->escrever($rota, $todas, token: 'TOKEN_errado');
        self::assertResponseStatusCodeSame(403, 'sem CSRF');

        $this->assertNadaGravado();
        $this->assertFiltroLigadoEmB();
    }

    #[DataProvider('rotas')]
    #[TestDox('$rota: 403 com o escritório A suspenso (só leitura), com todas as caixas; nada é gravado')]
    public function testRecusaComASuspenso(string $rota): void
    {
        $id = $this->convite(new PermissoesDoCompartilhamento(true, true, true));
        $this->contaA->setIsActive(false);
        $this->em()->flush();
        $this->entrar($this->bia, $this->contaB);

        $this->escrever($rota, $id);

        self::assertResponseStatusCodeSame(403);
        $this->assertNadaGravado();
        $this->assertFiltroLigadoEmB();
    }

    #[TestDox('anotar com a aba Dados oculta e enviar com a aba Documentos oculta: 403')]
    public function testAbaOcultaRecusa(): void
    {
        $this->em()->persist(AbaOcultaDaPasta::paraTodaAEquipe($this->contaA, $this->pasta, AbaDaPasta::Dados, $this->donoA, new \DateTimeImmutable()));
        $this->em()->persist(AbaOcultaDaPasta::paraTodaAEquipe($this->contaA, $this->pasta, AbaDaPasta::Documentos, $this->donoA, new \DateTimeImmutable()));
        $this->em()->flush();
        $id = $this->convite(new PermissoesDoCompartilhamento(true, true, true));
        $this->entrar($this->bia, $this->contaB);

        foreach (['anotar', 'documento'] as $rota) {
            $this->escrever($rota, $id);
            self::assertResponseStatusCodeSame(403, $rota);
        }
        $this->assertNadaGravado();
    }

    /** @return iterable<string, array{0: string}> */
    public static function casosQueNaoAbrem(): iterable
    {
        foreach (['pendente', 'encerrado', 'de outra pessoa', 'cadeado Todos', 'A excluído', 'origem sem edição', 'destino com vínculo ativo em A', 'sem convite'] as $caso) {
            yield $caso => [$caso];
        }
    }

    #[DataProvider('casosQueNaoAbrem')]
    #[TestDox('não abre ($caso): 404 nas três escritas, com CSRF válido, e nada é gravado')]
    public function testNaoAbre(string $caso): void
    {
        $id = $this->cenarioQueNaoAbre($caso);
        $this->entrar($this->bia, $this->contaB);

        foreach (self::ROTAS as $rota) {
            $this->escrever($rota, $id);
            self::assertResponseStatusCodeSame(404, $caso . ': ' . $rota);
        }
        $this->assertNadaGravado();
    }

    // ── Tela ───────────────────────────────────────────────────────────────

    #[TestDox('tela: com as caixas, o campo de anotação, a troca de fase (só o mesmo lado do Contrato) e o envio de arquivo aparecem')]
    public function testTelaComAsCaixas(): void
    {
        $this->andamentoEm(3);
        $id = $this->convite(new PermissoesDoCompartilhamento(true, true, false));
        $this->entrar($this->bia, $this->contaB);

        $crawler = $this->client->request('GET', '/expediente/recebidas/' . $id . '/pasta');

        self::assertResponseIsSuccessful();
        $base = '/expediente/recebidas/' . $id;
        self::assertCount(1, $crawler->filter('#andamentos form[action="' . $base . '/anotacao"] textarea[name="conteudo"]'));
        self::assertCount(1, $crawler->filter('.ps-cabecalho form[action="' . $base . '/fase"]'));
        self::assertCount(1, $crawler->filter('#documentos form[action="' . $base . '/documento"] input[type="file"][name="arquivo"]'));
        $opcoes = $crawler->filter('.ps-cabecalho form[action="' . $base . '/fase"] option')->each(static fn ($o): string => (string) $o->attr('value'));
        self::assertSame(['', '4', '5', '6', '7', '8'], $opcoes, 'Protocolo a Cumprimento; nem Contrato, nem antes dele, nem Finalizada');
        $categorias = $crawler->filter('#documentos select[name="categoria"] option')->each(static fn ($o): string => (string) $o->attr('value'));
        self::assertNotContains(PastaDocumento::CATEGORIA_CONTRATO, $categorias);
        foreach (['#andamentos form', '.ps-cabecalho form', '#documentos form'] as $form) {
            self::assertNotSame('', (string) $crawler->filter($form . ' input[name="_token"]')->attr('value'), $form . ' leva o token');
        }
    }

    /** @return iterable<string, array{0: bool, 1: bool}> as caixas e a conta A suspensa */
    public static function telasSemEscrita(): iterable
    {
        yield 'sem as caixas' => [false, false];
        yield 'com as caixas e A suspenso' => [true, true];
    }

    #[DataProvider('telasSemEscrita')]
    #[TestDox('tela: $_dataName, nenhum campo de escrita aparece')]
    public function testTelaSemEscrita(bool $caixas, bool $suspenso): void
    {
        $id = $this->convite(new PermissoesDoCompartilhamento($caixas, $caixas, true));
        if ($suspenso) {
            $this->contaA->setIsActive(false);
            $this->em()->flush();
        }
        $this->entrar($this->bia, $this->contaB);

        $crawler = $this->client->request('GET', '/expediente/recebidas/' . $id . '/pasta');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('.pr-page form'));
        self::assertCount(0, $crawler->filter('.pr-page textarea, .pr-page input[type="file"], .pr-page select'));
    }

    // ── Apoio ──────────────────────────────────────────────────────────────

    private function cenarioQueNaoAbre(string $caso): int
    {
        $todas = new PermissoesDoCompartilhamento(true, true, true);
        switch ($caso) {
            case 'pendente':
                return $this->convite($todas, aceito: false);
            case 'encerrado':
                $id = $this->convite($todas);
                $c = $this->em()->find(PastaCompartilhamento::class, $id);
                self::assertNotNull($c);
                $c->encerrar($this->donoA, new \DateTimeImmutable());
                $this->em()->flush();

                return $id;
            case 'de outra pessoa':
                return $this->convite($todas, destino: $this->criarColaborador($this->contaB, 'Caio de B', self::PERMISSOES));
            case 'cadeado Todos':
                $this->trancar($this->pasta, $this->donoA);

                return $this->convite($todas);
            case 'A excluído':
                $this->contaA->setExcluidoEm(new \DateTimeImmutable());
                $this->em()->flush();

                return $this->convite($todas);
            case 'origem sem edição':
                return $this->convite($todas, origem: $this->criarColaborador($this->contaA, 'Vera Só Vê', ['modules.expediente.view', 'resources.pasta.view']));
            case 'destino com vínculo ativo em A':
                $this->vincular($this->bia, $this->contaA, false, self::PERMISSOES);

                return $this->convite($todas);
            case 'sem convite':
                $interno = PastaCompartilhamento::interno($this->pasta, $this->donoA, $this->olga, $todas, null, null, new \DateTimeImmutable());
                $this->em()->persist($interno);
                $this->em()->flush();

                return (int) $interno->getId();
        }

        throw new \LogicException('caso desconhecido: ' . $caso);
    }

    private function convite(PermissoesDoCompartilhamento $permissoes, ?Pasta $pasta = null, ?User $origem = null, ?User $destino = null, bool $aceito = true): int
    {
        $destino ??= $this->bia;
        $c = PastaCompartilhamento::externo($pasta ?? $this->pasta, $origem ?? $this->donoA, $destino, $permissoes, null, null, new \DateTimeImmutable('-2 days'));
        $this->em()->persist($c);
        $this->em()->flush();
        if ($aceito) {
            $c->aceitar($destino, new \DateTimeImmutable('-1 day'));
            $this->em()->flush();
        }

        return (int) $c->getId();
    }

    /**
     * O andamento gravado de A na fase `$atual`, com as datas até ela (menos as puladas).
     *
     * @param list<int> $puladas
     */
    private function andamentoEm(int $atual, array $puladas = []): void
    {
        $andamento = new PastaAndamento($this->contaA, $this->pasta, PastaAndamento::VIA_JUDICIAL);
        $em = [];
        for ($k = 0; $k <= $atual; ++$k) {
            if (!\in_array($k, $puladas, true)) {
                $em[$k] = (new \DateTimeImmutable('-' . (20 - $k) . ' days'))->format(\DATE_ATOM);
            }
        }
        $andamento->gravar($atual, ['em' => $em, 'pul' => $puladas, 'extra' => []]);
        $this->em()->persist($andamento);
        $this->em()->flush();
    }

    /** @param array<string, string> $extra */
    private function anotar(int $id, string $conteudo, array $extra = [], ?string $token = null): void
    {
        $this->post($id, 'anotacao', ['conteudo' => $conteudo, '_token' => $token ?? 'TOKEN_expediente_recebida_anotar_' . $id] + $extra);
    }

    private function fase(int $id, string $fase, ?string $token = null): void
    {
        $this->post($id, 'fase', ['fase' => $fase, '_token' => $token ?? 'TOKEN_expediente_recebida_fase_' . $id]);
    }

    private function enviar(int $id, string $categoria, ?string $token = null): void
    {
        $origem = sys_get_temp_dir() . '/recebida_' . bin2hex(random_bytes(6));
        file_put_contents($origem, self::PDF);
        $this->temporarios[] = $origem;
        $arquivo = new UploadedFile($origem, 'peticao-da-parceira.pdf', null, null, true);

        $this->post($id, 'documento', ['categoria' => $categoria, '_token' => $token ?? 'TOKEN_expediente_recebida_documento_' . $id], ['arquivo' => $arquivo]);
    }

    /** Uma escrita válida da rota (`anotar`, `fase` ou `documento`), com o token certo salvo `$token`. */
    private function escrever(string $rota, int $id, ?string $token = null): void
    {
        match ($rota) {
            'anotar'    => $this->anotar($id, 'ESCRITA RECUSADA', token: $token),
            'fase'      => $this->fase($id, '1', $token),
            'documento' => $this->enviar($id, 'DEMAIS', $token),
        };
    }

    /**
     * @param array<string, string>       $campos
     * @param array<string, UploadedFile> $arquivos
     */
    private function post(int $id, string $acao, array $campos, array $arquivos = []): void
    {
        $this->client->request('POST', '/expediente/recebidas/' . $id . '/' . $acao, $campos, $arquivos, ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest', 'HTTP_ACCEPT' => 'application/json']);
    }

    /** @return array<string, mixed> a última anotação (não do sistema) da pasta, pelo banco */
    private function ultimaAnotacao(): array
    {
        $linha = $this->conexao()->fetchAssociative('SELECT * FROM pasta_mensagem WHERE pasta_id = :p AND sistema IS NULL ORDER BY id DESC LIMIT 1', ['p' => $this->pasta->getId()]);
        self::assertIsArray($linha, 'a anotação foi gravada');

        return $linha;
    }

    /** @return list<int> quem recebeu aviso do tipo em A, por id */
    private function avisados(string $tipo): array
    {
        return array_map('intval', $this->conexao()->fetchFirstColumn('SELECT DISTINCT usuario_id FROM notificacao WHERE tenant_id = :a AND tipo = :t ORDER BY usuario_id', ['a' => $this->contaA->getId(), 't' => $tipo]));
    }

    /** @return list<string> os tipos de aviso da pessoa, em qualquer escritório */
    private function avisosDe(User $pessoa): array
    {
        return $this->conexao()->fetchFirstColumn('SELECT tipo FROM notificacao WHERE usuario_id = :u', ['u' => $pessoa->getId()]);
    }

    /** @return list<string> as classes que a auditoria gravou em A com a Bia como autora (criação e alteração) */
    private function auditoriaEmA(): array
    {
        return $this->conexao()->fetchFirstColumn(
            'SELECT entity_class FROM audit_log WHERE tenant_id = :a AND actor_user_id = :b AND action IN (\'create\', \'update\')',
            ['a' => $this->contaA->getId(), 'b' => $this->bia->getId()],
        );
    }

    /** @param array<string, mixed> $extra além de `:p` (pasta) e `:a` (escritório A) quando a consulta os usa */
    private function contar(string $sql, array $extra = []): int
    {
        $parametros = $extra;
        if (str_contains($sql, ':p')) {
            $parametros['p'] = $this->pasta->getId();
        }
        if (str_contains($sql, ':a')) {
            $parametros['a'] = $this->contaA->getId();
        }

        return (int) $this->conexao()->fetchOne($sql, $parametros);
    }

    private function assertNadaGravado(): void
    {
        self::assertSame(0, $this->contar('SELECT COUNT(*) FROM pasta_mensagem WHERE autor_id = :b', ['b' => $this->bia->getId()]));
        self::assertSame(0, $this->contar('SELECT COUNT(*) FROM pasta_andamento WHERE pasta_id = :p'));
        self::assertSame(0, $this->contar('SELECT COUNT(*) FROM pasta_documento WHERE enviado_por_id = :b', ['b' => $this->bia->getId()]));
        self::assertSame([], $this->storage->memoria->gravadas);
    }

    /** Depois da escrita (com sucesso ou recusa), o filtro do escritório volta ligado com o escritório da sessão (B). */
    private function assertFiltroLigadoEmB(): void
    {
        $filtros = static::getContainer()->get(EntityManagerInterface::class)->getFilters();
        self::assertTrue($filtros->isEnabled('tenant'));
        self::assertSame((int) $this->contaB->getId(), (int) trim((string) $filtros->getFilter('tenant')->getParameter('tenant'), "'"));
    }

    private function conexao(): Connection
    {
        return static::getContainer()->get(Connection::class);
    }
}
