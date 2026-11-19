<?php

declare(strict_types=1);

namespace App\Tests\Conector\Integration;

use App\Cliente\Armazenamento\ChavesDeCliente;
use App\Cliente\Entity\Cliente;
use App\Cliente\Entity\ClienteDocumento;
use App\Cliente\Entity\ClientePF;
use App\Conector\DTO\ChamadaDoConector;
use App\Conector\DTO\RespostaDaFerramenta;
use App\Conector\Entity\ClienteDoConector;
use App\Conector\Entity\ConsentimentoDoConector;
use App\Conector\Entity\EscritorioDoConector;
use App\Conector\Enum\TipoDeClienteDoConector;
use App\Conector\Exception\ErroDaFerramenta;
use App\Conector\Ferramenta\AtualizarClienteExclusoes;
use App\Conector\Ferramenta\AtualizarClienteFerramenta;
use App\Conector\Ferramenta\ConfirmarAcaoFerramenta;
use App\Conector\Ferramenta\DocumentosDoClienteNoRecorte;
use App\Conector\Ferramenta\LerClientesFerramenta;
use App\Conector\Ferramenta\LerClientesNosDocumentos;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\AbaDaPasta;
use App\Pasta\Entity\AbaOcultaDaPasta;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaDocumento;
use App\Pasta\Entity\PastaProcesso;
use App\Processo\Entity\Processo;
use App\Tests\Conector\Support\CenarioDoConectorTrait;
use App\Tests\Factory\Cobranca\CarteiraFactory;
use App\Tests\Shared\Doubles\ArmazenamentoEmMemoriaNoContainer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * PR 14b do conector (spec mcp-remoto-oauth, 6.4 e 6.6): `ler_clientes` `documentos_do_cliente` e, por proposta,
 * `atualizar_cliente` `excluir_documento` e `excluir_cliente`. Cobre o caminho feliz, a permissão, o outro escritório, o
 * documento de outro cliente, o vinculado de pasta em segredo ou com a aba Documentos oculta, a carteira de cobrança
 * (recusada ao propor e na confirmação, sem fechar o EntityManager) e o arquivo removido só DEPOIS do COMMIT.
 */
#[CoversClass(LerClientesNosDocumentos::class)]
#[CoversClass(DocumentosDoClienteNoRecorte::class)]
#[CoversClass(AtualizarClienteExclusoes::class)]
final class ClienteDocumentosEExclusoesPeloConectorTest extends KernelTestCase
{
    use CenarioDoConectorTrait;
    use Factories;

    private const DONO = ['modules.clientes.view', 'resources.cliente.view', 'resources.cliente.edit', 'resources.cliente.delete', 'resources.pasta.view'];
    private const SO_VE = ['modules.clientes.view', 'resources.cliente.view', 'resources.pasta.view'];

    private Tenant $escritorio;
    private User $pessoa;
    private EscritorioDoConector $doConector;
    private ConsentimentoDoConector $consentimento;
    private ArmazenamentoEmMemoriaNoContainer $disco;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->disco = ArmazenamentoEmMemoriaNoContainer::instalarEm(static::getContainer());
        $this->escritorio = $this->escritorio();
        $this->pessoa = $this->pessoa();
        $this->vinculo($this->pessoa, $this->escritorio, permissoes: self::DONO);
        $this->doConector = new EscritorioDoConector($this->escritorio);
        $this->doConector->liberarPeloMaster();
        $this->doConector->ligar($this->pessoa, new \DateTimeImmutable());
        $this->em()->persist($this->doConector);
        $app = new ClienteDoConector('https://claude.ai/oauth/' . uniqid() . '/client.json', TipoDeClienteDoConector::Cimd, 'Claude', ['https://claude.ai/api/mcp/auth_callback'], new \DateTimeImmutable());
        $this->em()->persist($app);
        $this->consentimento = new ConsentimentoDoConector($this->pessoa, $this->escritorio, $app, ['mcp:leitura', 'mcp:escrita'], new \DateTimeImmutable());
        $this->em()->persist($this->consentimento);
        $this->em()->flush();
    }

    protected function tearDown(): void
    {
        static::getContainer()->get('services_resetter')->reset();
        parent::tearDown();
    }

    // ------------------------------------------------------------------ ler_clientes: documentos_do_cliente

    #[TestDox('documentos_do_cliente: só metadados; o vinculado em segredo e o de pasta com a aba oculta saem e são contados')]
    public function testListaDosDocumentos(): void
    {
        $cliente = $this->cliente();
        $proprio = $this->documentoProprio($cliente, 'rg-da-maria.pdf');
        $aberta = $this->pasta($cliente);
        $vinculadoAberto = $this->vinculado($cliente, $aberta, 'comprovante.pdf');
        $secreta = $this->pasta($cliente);
        $this->vincularProcessoSigiloso($secreta);
        $this->vinculado($cliente, $secreta, 'segredo.pdf');
        $semAba = $this->pasta($cliente);
        $this->vinculado($cliente, $semAba, 'aba-oculta.pdf');
        $this->em()->persist(AbaOcultaDaPasta::paraTodaAEquipe($this->escritorio, $semAba, AbaDaPasta::Documentos, $this->pessoa, new \DateTimeImmutable()));
        $this->em()->flush();
        $ids = [(int) $cliente->getId(), (int) $proprio->getId(), (int) $vinculadoAberto->getId(), (int) $aberta->getId()];

        $resposta = $this->ler(['acao' => 'documentos_do_cliente', 'cliente_id' => $ids[0]]);

        self::assertSame($ids[0], $resposta->dados['cliente_id']);
        self::assertSame(1, $resposta->dados['documentos_omitidos_em_segredo']);
        self::assertSame(1, $resposta->dados['documentos_omitidos_sem_acesso']);
        $porId = array_column($resposta->dados['documentos'], null, 'id');
        self::assertEqualsCanonicalizing([$ids[1], $ids[2]], array_keys($porId));
        self::assertSame(['id', 'categoria', 'nome_original', 'descricao', 'tamanho_bytes', 'enviado_em', 'vinculado_de_pasta', 'pasta_id'], array_keys($porId[$ids[1]]));
        self::assertFalse($porId[$ids[1]]['vinculado_de_pasta']);
        self::assertNull($porId[$ids[1]]['pasta_id']);
        self::assertTrue($porId[$ids[2]]['vinculado_de_pasta']);
        self::assertSame($ids[3], $porId[$ids[2]]['pasta_id']);
        $json = json_encode($resposta->dados, \JSON_THROW_ON_ERROR);
        foreach (['SEGREDO.PDF', 'segredo.pdf', 'aba-oculta', 'ABA-OCULTA', '000.111.222-33', '00011122233'] as $proibido) {
            self::assertStringNotContainsString($proibido, $json);
        }
        self::assertSame(2, $resposta->contagens['documentos']);
    }

    #[TestDox('documentos_do_cliente: cliente de outro escritório é "não encontrado" e sem o módulo clientes é sem permissão')]
    public function testListaRecusas(): void
    {
        $alheio = $this->cliente($this->escritorio('Outro Escritório'));
        $proprio = $this->cliente();
        $semModulo = $this->pessoa();
        $this->vinculo($semModulo, $this->escritorio, permissoes: ['resources.cliente.view']);
        $this->doConector->ligar($semModulo, new \DateTimeImmutable());
        $this->em()->flush();

        $this->assertMotivo(fn () => $this->ler(['acao' => 'documentos_do_cliente', 'cliente_id' => $alheio->getId()]), ErroDaFerramenta::MOTIVO_NAO_ENCONTRADO);
        $this->assertMotivo(fn () => $this->ler(['acao' => 'documentos_do_cliente', 'cliente_id' => $proprio->getId()], $semModulo), ErroDaFerramenta::MOTIVO_SEM_PERMISSAO);
    }

    // ------------------------------------------------------------------ excluir_documento

    #[TestDox('excluir_documento próprio: a proposta não apaga; a confirmação tira a linha e, DEPOIS do COMMIT, o arquivo')]
    public function testExcluirDocumentoProprio(): void
    {
        $cliente = $this->cliente();
        $documento = $this->documentoProprio($cliente, 'rg.pdf');
        $outro = $this->documentoProprio($cliente, 'cpf.pdf');
        [$clienteId, $docId, $outroId] = [(int) $cliente->getId(), (int) $documento->getId(), (int) $outro->getId()];
        $chave = ChavesDeCliente::documento($documento);

        $proposta = $this->propor(['acao' => 'excluir_documento', 'cliente_id' => $clienteId, 'documento_id' => $docId]);

        self::assertStringContainsStringIgnoringCase('Excluir o documento "rg.pdf"', $proposta['resumo']);
        self::assertStringContainsString('Não há como desfazer', $proposta['resumo']);
        self::assertSame(1, $this->linhas('cliente_documento', $docId));
        self::assertTrue($this->disco->memoria->existe($chave));

        $nivel = $this->em()->getConnection()->getTransactionNestingLevel();
        $noMomento = null;
        $this->disco->memoria->aoExcluir = function () use (&$noMomento, $docId): void {
            $noMomento = [$this->em()->getConnection()->getTransactionNestingLevel(), $this->linhas('cliente_documento', $docId)];
        };
        $resposta = $this->confirmar($proposta);

        self::assertSame('confirmada', $resposta->dados['estado']);
        self::assertSame(['operacao' => 'excluir_documento', 'cliente' => $resposta->dados['resultado']['cliente'], 'documento_id' => $docId], $resposta->dados['resultado']);
        self::assertSame([$nivel, 0], $noMomento, 'o arquivo saiu com a transação já confirmada e a linha já fora do banco');
        self::assertSame(0, $this->linhas('cliente_documento', $docId));
        self::assertFalse($this->disco->memoria->existe($chave));
        self::assertSame(1, $this->linhas('cliente_documento', $outroId), 'só sai o pedido');
        self::assertSame(1, $this->linhas('cliente', $clienteId));
    }

    #[TestDox('excluir_documento vinculado de pasta: sai só o vínculo; o arquivo da pasta fica')]
    public function testExcluirDocumentoVinculado(): void
    {
        $cliente = $this->cliente();
        $pasta = $this->pasta($cliente);
        $vinculado = $this->vinculado($cliente, $pasta, 'da-pasta.pdf');
        [$clienteId, $vinculadoId, $origemId] = [(int) $cliente->getId(), (int) $vinculado->getId(), (int) $vinculado->getPastaDocumento()?->getId()];

        $proposta = $this->propor(['acao' => 'excluir_documento', 'cliente_id' => $clienteId, 'documento_id' => $vinculadoId]);
        self::assertStringContainsString('o arquivo continua na pasta', $proposta['resumo']);
        $this->confirmar($proposta);

        self::assertSame(0, $this->linhas('cliente_documento', $vinculadoId));
        self::assertSame(1, $this->linhas('pasta_documento', $origemId));
        self::assertSame([], $this->disco->memoria->excluidas, 'nenhum arquivo removido');
    }

    #[TestDox('excluir_documento: quem só vê o cliente, documento de outro cliente, de outro escritório e vinculado em segredo são recusados sem gravar')]
    public function testExcluirDocumentoRecusas(): void
    {
        $cliente = $this->cliente();
        $documento = $this->documentoProprio($cliente, 'rg.pdf');
        $doOutroCliente = $this->documentoProprio($this->cliente(), 'alheio.pdf');
        $escritorioB = $this->escritorio('Outro Escritório');
        $docAlheio = $this->documentoProprio($this->cliente($escritorioB), 'b.pdf');
        $secreta = $this->pasta($cliente);
        $this->vincularProcessoSigiloso($secreta);
        $emSegredo = $this->vinculado($cliente, $secreta, 'segredo.pdf');
        $leitor = $this->pessoa();
        $this->vinculo($leitor, $this->escritorio, permissoes: self::SO_VE);
        $this->doConector->ligar($leitor, new \DateTimeImmutable());
        $this->em()->flush();
        $c = (int) $cliente->getId();

        $this->assertMotivo(fn () => $this->atualizar(['acao' => 'excluir_documento', 'cliente_id' => $c, 'documento_id' => $documento->getId()], $leitor), ErroDaFerramenta::MOTIVO_SEM_PERMISSAO);
        $this->assertMotivo(fn () => $this->atualizar(['acao' => 'excluir_documento', 'cliente_id' => $c, 'documento_id' => $doOutroCliente->getId()]), ErroDaFerramenta::MOTIVO_NAO_ENCONTRADO);
        $this->assertMotivo(fn () => $this->atualizar(['acao' => 'excluir_documento', 'cliente_id' => $c, 'documento_id' => $docAlheio->getId()]), ErroDaFerramenta::MOTIVO_NAO_ENCONTRADO);
        $this->assertMotivo(fn () => $this->atualizar(['acao' => 'excluir_documento', 'cliente_id' => $c, 'documento_id' => $emSegredo->getId()]), ErroDaFerramenta::MOTIVO_EM_SEGREDO);

        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM conector_ia_proposta'));
        self::assertSame(4, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM cliente_documento WHERE id IN (?, ?, ?, ?)', [$documento->getId(), $doOutroCliente->getId(), $docAlheio->getId(), $emSegredo->getId()]));
    }

    #[TestDox('excluir_documento: o vinculado cuja pasta foi para o segredo entre a proposta e o sim é recusado na confirmação')]
    public function testExcluirDocumentoReconferirSegredo(): void
    {
        $cliente = $this->cliente();
        $pasta = $this->pasta($cliente);
        $vinculado = $this->vinculado($cliente, $pasta, 'da-pasta.pdf');
        $proposta = $this->propor(['acao' => 'excluir_documento', 'cliente_id' => $cliente->getId(), 'documento_id' => $vinculado->getId()]);
        $this->vincularProcessoSigiloso($this->em()->find(Pasta::class, $pasta->getId()) ?? self::fail('pasta'));

        $this->assertMotivo(fn () => $this->confirmar($proposta), ErroDaFerramenta::MOTIVO_EM_SEGREDO);

        self::assertSame(1, $this->linhas('cliente_documento', (int) $vinculado->getId()));
    }

    // ------------------------------------------------------------------ excluir_cliente

    #[TestDox('excluir_cliente: a proposta avisa que não tem volta; a confirmação apaga cliente, documentos e vínculos, solta o principal e tira os arquivos depois do COMMIT')]
    public function testExcluirCliente(): void
    {
        $cliente = $this->cliente();
        $proprio = $this->documentoProprio($cliente, 'rg.pdf');
        $pasta = $this->pasta($cliente);
        $vinculado = $this->vinculado($cliente, $pasta, 'da-pasta.pdf');
        [$clienteId, $pastaId, $proprioId, $vinculadoId, $origemId] = [(int) $cliente->getId(), (int) $pasta->getId(), (int) $proprio->getId(), (int) $vinculado->getId(), (int) $vinculado->getPastaDocumento()?->getId()];
        $chave = ChavesDeCliente::documento($proprio);
        self::assertSame($clienteId, $this->principalDaPasta($pastaId));

        $proposta = $this->propor(['acao' => 'excluir_cliente', 'cliente_id' => $clienteId]);

        self::assertStringContainsString('Não há como desfazer', $proposta['resumo']);
        self::assertStringContainsString('documentos próprios do cadastro saem junto', $proposta['resumo']);
        self::assertStringContainsString('sai de todas as pastas', $proposta['resumo']);
        self::assertSame(1, $this->linhas('cliente', $clienteId));

        $nivel = $this->em()->getConnection()->getTransactionNestingLevel();
        $noMomento = null;
        $this->disco->memoria->aoExcluir = function () use (&$noMomento, $clienteId): void {
            $noMomento = [$this->em()->getConnection()->getTransactionNestingLevel(), $this->linhas('cliente', $clienteId)];
        };
        $resposta = $this->confirmar($proposta);

        self::assertSame('confirmada', $resposta->dados['estado']);
        self::assertSame($clienteId, $resposta->dados['resultado']['cliente']['id']);
        self::assertSame([$nivel, 0], $noMomento, 'o arquivo saiu depois do COMMIT');
        self::assertSame(0, $this->linhas('cliente', $clienteId));
        self::assertSame(0, $this->linhas('cliente_documento', $proprioId));
        self::assertSame(0, $this->linhas('cliente_documento', $vinculadoId));
        self::assertFalse($this->disco->memoria->existe($chave));
        self::assertSame(1, $this->linhas('pasta', $pastaId), 'a pasta fica');
        self::assertSame(1, $this->linhas('pasta_documento', $origemId), 'o documento da pasta fica');
        self::assertNull($this->principalDaPasta($pastaId));
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM pasta_cliente WHERE cliente_id = ?', [$clienteId]));
    }

    #[TestDox('excluir_cliente: sem o recurso excluir, de outro escritório e com carteira de cobrança são recusados ao propor, sem gravar')]
    public function testExcluirClienteRecusas(): void
    {
        $cliente = $this->cliente();
        $comCarteira = $this->cliente();
        CarteiraFactory::createOne(['tenant' => $this->escritorio, 'cliente' => $comCarteira]);
        $alheio = $this->cliente($this->escritorio('Outro Escritório'));
        $editor = $this->pessoa();
        $this->vinculo($editor, $this->escritorio, permissoes: [...self::SO_VE, 'resources.cliente.edit']);
        $this->doConector->ligar($editor, new \DateTimeImmutable());
        $this->em()->flush();

        $this->assertMotivo(fn () => $this->atualizar(['acao' => 'excluir_cliente', 'cliente_id' => $cliente->getId()], $editor), ErroDaFerramenta::MOTIVO_SEM_PERMISSAO);
        $this->assertMotivo(fn () => $this->atualizar(['acao' => 'excluir_cliente', 'cliente_id' => $alheio->getId()]), ErroDaFerramenta::MOTIVO_NAO_ENCONTRADO);
        try {
            $this->atualizar(['acao' => 'excluir_cliente', 'cliente_id' => $comCarteira->getId()]);
            self::fail('deveria recusar');
        } catch (ErroDaFerramenta $e) {
            self::assertSame(AtualizarClienteExclusoes::COM_CARTEIRA, $e->getMessage());
        }

        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM conector_ia_proposta'));
        self::assertSame(3, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM cliente WHERE id IN (?, ?, ?)', [$cliente->getId(), $comCarteira->getId(), $alheio->getId()]));
    }

    #[TestDox('excluir_cliente: carteira criada entre a proposta e o sim recusa a confirmação sem estourar a FK; nada some e o arquivo fica')]
    public function testExcluirClienteCarteiraDepoisDaProposta(): void
    {
        $cliente = $this->cliente();
        $proprio = $this->documentoProprio($cliente, 'rg.pdf');
        $clienteId = (int) $cliente->getId();
        $chave = ChavesDeCliente::documento($proprio);
        $proposta = $this->propor(['acao' => 'excluir_cliente', 'cliente_id' => $clienteId]);
        CarteiraFactory::createOne(['tenant' => $this->doEscritorio(), 'cliente' => $this->em()->find(Cliente::class, $clienteId)]);

        try {
            $this->confirmar($proposta);
            self::fail('a confirmação deveria recusar');
        } catch (ErroDaFerramenta $e) {
            self::assertSame(AtualizarClienteExclusoes::COM_CARTEIRA, $e->getMessage());
        }

        self::assertTrue($this->em()->isOpen(), 'a FK não estourou: o EntityManager segue aberto');
        self::assertSame(1, $this->linhas('cliente', $clienteId));
        self::assertTrue($this->disco->memoria->existe($chave));
        self::assertSame([], $this->disco->memoria->excluidas);
    }

    // ------------------------------------------------------------------ apoio

    /**
     * @param array<string, mixed> $argumentos
     *
     * @return array{proposta_id: string, resumo: string}
     */
    private function propor(array $argumentos): array
    {
        $resposta = $this->atualizar($argumentos);
        self::assertSame('aguardando_confirmacao', $resposta->dados['estado']);

        return $resposta->dados['proposta'];
    }

    /** @param array<string, mixed> $argumentos */
    private function atualizar(array $argumentos, ?User $pessoa = null): RespostaDaFerramenta
    {
        try {
            return static::getContainer()->get(AtualizarClienteFerramenta::class)->executar($argumentos, $this->chamada($pessoa));
        } finally {
            $this->reanexar();
        }
    }

    /** @param array<string, mixed> $argumentos */
    private function ler(array $argumentos, ?User $pessoa = null): RespostaDaFerramenta
    {
        try {
            return static::getContainer()->get(LerClientesFerramenta::class)->executar($argumentos, $this->chamada($pessoa, ['mcp:leitura']));
        } finally {
            $this->reanexar();
        }
    }

    /** @param array{proposta_id: string, resumo: string} $proposta */
    private function confirmar(array $proposta): RespostaDaFerramenta
    {
        $chamada = $this->chamada();
        try {
            return static::getContainer()->get(ConfirmarAcaoFerramenta::class)->executar(['proposta_id' => $proposta['proposta_id'], 'resumo' => $proposta['resumo']], $chamada);
        } finally {
            $this->reanexar();
        }
    }

    /** @param \Closure(): mixed $acao */
    private function assertMotivo(\Closure $acao, string $motivo): void
    {
        try {
            $acao();
        } catch (ErroDaFerramenta $e) {
            self::assertSame($motivo, $e->motivo, $e->getMessage());

            return;
        }
        self::fail('deveria recusar com ' . $motivo);
    }

    /** @param list<string> $escopos */
    private function chamada(?User $pessoa = null, array $escopos = ['mcp:leitura', 'mcp:escrita']): ChamadaDoConector
    {
        static::getContainer()->get('services_resetter')->reset();
        $this->reanexar();
        $pessoa ??= $this->pessoa;
        $contexto = $this->portao()->abrir((int) $pessoa->getId(), (int) $this->escritorio->getId());

        return new ChamadaDoConector($contexto, 41, (int) $this->consentimento->getId(), 'claude.ai', $escopos, new \DateTimeImmutable('+7 days'));
    }

    /** Relê do banco o cenário do setUp: a chamada e a confirmação soltam as entidades do EntityManager. */
    private function reanexar(): void
    {
        foreach (['escritorio', 'pessoa', 'doConector', 'consentimento'] as $campo) {
            $entidade = $this->{$campo};
            $this->{$campo} = $this->em()->find($entidade::class, $entidade->getId()) ?? self::fail('entidade sumiu');
        }
    }

    private function doEscritorio(): Tenant
    {
        return $this->em()->find(Tenant::class, $this->escritorio->getId()) ?? self::fail('escritório sumiu');
    }

    private function cliente(?Tenant $escritorio = null): ClientePF
    {
        $cliente = (new ClientePF())->setNomeCompleto('Maria do Banco ' . substr(uniqid(), -5))->setCpf('000.111.222-33')->setRg('')->setRgOrgaoExpedidor('');
        $cliente->setEmail('cx.' . uniqid() . '@exemplo.com.br')->setTelefoneCelular('(61) 98765-4321')->setCep('')->setEndereco('')->setCidade('Brasília')->setEstado('DF')->setTenant($escritorio ?? $this->escritorio);
        $this->em()->persist($cliente);
        $this->em()->flush();

        return $cliente;
    }

    private function documentoProprio(Cliente $cliente, string $nome): ClienteDocumento
    {
        $documento = (new ClienteDocumento())->setCliente($cliente)->setTenant($cliente->getTenant() ?? self::fail('sem escritório'))
            ->setTitulo($nome)->setCategoria(ClienteDocumento::CATEGORIA_IDENTIFICACAO)->setCaminhoArquivo(bin2hex(random_bytes(8)) . '.pdf')
            ->setNomeOriginal($nome)->setMimeType('application/pdf')->setTamanhoBytes(10)->setDescricao('Descrição de ' . $nome);
        $cliente->addDocumento($documento);
        $this->em()->persist($documento);
        $this->em()->flush();
        $this->disco->memoria->semear(ChavesDeCliente::documento($documento), '%PDF-1.7 ' . $nome);

        return $documento;
    }

    private function pasta(Cliente $cliente): Pasta
    {
        $pasta = (new Pasta())->setNup('CLX-' . uniqid());
        $pasta->setTenant($this->escritorio);
        $pasta->addCliente($cliente);
        $this->em()->persist($pasta);
        $this->em()->flush();

        return $pasta;
    }

    private function vinculado(Cliente $cliente, Pasta $pasta, string $nome): ClienteDocumento
    {
        $origem = (new PastaDocumento())->setPasta($pasta)->setTenant($this->escritorio)->setCategoria(PastaDocumento::CATEGORIA_IDENTIFICACAO)
            ->setTitulo($nome)->setNomeOriginal($nome)->setCaminhoArquivo('cx_' . bin2hex(random_bytes(6)) . '.pdf')
            ->setMimeType('application/pdf')->setTamanhoBytes(1234);
        $pasta->getDocumentos()->add($origem);
        $this->em()->persist($origem);
        $vinculo = ClienteDocumento::vinculadoA($origem, $cliente, $this->escritorio);
        $cliente->addDocumento($vinculo);
        $this->em()->persist($vinculo);
        $this->em()->flush();

        return $vinculo;
    }

    private function vincularProcessoSigiloso(Pasta $pasta): void
    {
        $processo = new Processo();
        $processo->setNumeroProcesso(sprintf('0701134572025807%04d', random_int(0, 9999)));
        $processo->setSiglaTribunal('TJDFT');
        $processo->setClasseProcessual('Procedimento Comum');
        $processo->setAssuntoProcessual('Assunto');
        $processo->setTenant($pasta->getTenant() ?? self::fail('sem escritório'));
        $processo->setNivelSigilo(1);
        $this->em()->persist($processo);
        $vinculo = new PastaProcesso($pasta, $processo);
        $this->em()->persist($vinculo);
        $pasta->getPastaProcessos()->add($vinculo);
        $this->em()->flush();
    }

    private function linhas(string $tabela, int $id): int
    {
        return (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM ' . $tabela . ' WHERE id = ?', [$id]);
    }

    private function principalDaPasta(int $pastaId): ?int
    {
        $id = $this->em()->getConnection()->fetchOne('SELECT cliente_principal_id FROM pasta WHERE id = ?', [$pastaId]);

        return $id === null || $id === false ? null : (int) $id;
    }
}
