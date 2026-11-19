<?php

declare(strict_types=1);

namespace App\Tests\Conector\Integration;

use App\Conector\DTO\ChamadaDoConector;
use App\Conector\DTO\RespostaDaFerramenta;
use App\Conector\Entity\EscritorioDoConector;
use App\Conector\Exception\ErroDaFerramenta;
use App\Conector\Ferramenta\LerDocumentosEModelosFerramenta;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Armazenamento\ChavesDePasta;
use App\Pasta\Entity\AbaDaPasta;
use App\Pasta\Entity\AbaOcultaDaPasta;
use App\Pasta\Entity\CadeadoDaPasta;
use App\Pasta\Entity\ModeloDePeca;
use App\Pasta\Entity\ModoDoCadeado;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaChecklistModelo;
use App\Pasta\Entity\PastaChecklistModeloItem;
use App\Pasta\Entity\PastaDocumento;
use App\Pasta\Entity\PastaSecao;
use App\Pasta\Enum\MateriaDeModelo;
use App\Pasta\Enum\TipoDePeca;
use App\Processo\Entity\Processo;
use App\Pasta\Entity\PastaProcesso;
use App\Tests\Conector\Support\CenarioDoConectorTrait;
use App\Tests\Shared\Doubles\ArmazenamentoEmMemoriaNoContainer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * `ler_documentos_e_modelos` com o banco e o portão de verdade (spec mcp-remoto-oauth 6.4, 6.5 e decisões 24 e 26): cada
 * ação com a sua forma, a aba Documentos ou Detalhes oculta barrando, a pasta da lixeira, de outro escritório ou
 * trancada como "não encontrado", o segredo de justiça, a porta do aceite do conector, os tipos que ainda não saem e o
 * teto `conector_documento`. O arquivo vai no bloco à parte e o `dados` leva só metadados.
 */
#[CoversClass(LerDocumentosEModelosFerramenta::class)]
final class LerDocumentosEModelosFerramentaTest extends KernelTestCase
{
    use CenarioDoConectorTrait;

    private const PDF = "%PDF-1.7\nconteudo do pdf";
    private const PNG = "\x89PNG\r\n\x1A\nimagem";
    private const CPF = '529.982.247-25';
    private const LEITOR = ['modules.pastas.view', 'resources.pasta.view'];
    private const EDITOR = ['modules.pastas.view', 'resources.pasta.view', 'resources.pasta.edit'];

    private Tenant $escritorio;
    private User $pessoa;
    private ArmazenamentoEmMemoriaNoContainer $disco;
    private EscritorioDoConector $doConector;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->disco = ArmazenamentoEmMemoriaNoContainer::instalarEm(static::getContainer());
        $this->escritorio = $this->escritorio();
        $this->pessoa = $this->pessoa();
        $this->vinculo($this->pessoa, $this->escritorio, permissoes: self::EDITOR);
        $this->doConector = new EscritorioDoConector($this->escritorio);
        $this->doConector->liberarPeloMaster();
        $this->doConector->ligar($this->pessoa, new \DateTimeImmutable());
        $this->em()->persist($this->doConector);
        $this->em()->flush();
    }

    protected function tearDown(): void
    {
        static::getContainer()->get('services_resetter')->reset();
        parent::tearDown();
    }

    #[TestDox('listar: documentos e subpastas só com metadados, sem caminho nem hash, sem o que está na lixeira')]
    public function testListar(): void
    {
        $pasta = $this->pasta();
        $secao = $this->secao($pasta, 'Petições');
        $pdf = $this->documento($pasta, 'peticao.pdf', self::PDF, secao: $secao);
        $this->documento($pasta, 'rg.png', self::PNG);
        $excluido = $this->documento($pasta, 'velho.pdf', self::PDF);
        $excluido->marcarExcluido($this->pessoa, new \DateTimeImmutable());
        $this->em()->flush();

        $resposta = $this->executar('listar', $pasta);

        self::assertCount(2, $resposta->dados['documentos']);
        self::assertSame(2, $resposta->dados['total']);
        self::assertNull($resposta->proximaPagina);
        self::assertSame(['id', 'titulo', 'nome', 'categoria', 'subpasta', 'data', 'tamanho_bytes', 'tipo'], array_keys($resposta->dados['documentos'][0]));
        $porId = array_column($resposta->dados['documentos'], null, 'id');
        self::assertSame('PETIÇÕES', $porId[$pdf->getId()]['subpasta']);
        self::assertSame('application/pdf', $porId[$pdf->getId()]['tipo']);
        self::assertSame([['id' => $secao->getId(), 'nome' => 'PETIÇÕES']], $resposta->dados['subpastas']);
        self::assertStringNotContainsString('velho.pdf', json_encode($resposta->dados, \JSON_THROW_ON_ERROR));
        self::assertNull($resposta->arquivo);
        self::assertSame(['pasta' => [$pasta->getId()]], $resposta->ids);
    }

    #[TestDox('listar: 55 documentos dão a página 1 com 50 e proxima_pagina 2; a página 2 traz os 5 restantes e as subpastas só vêm na primeira')]
    public function testListarPaginado(): void
    {
        $pasta = $this->pasta();
        $this->secao($pasta, 'Única');
        for ($i = 1; $i <= 55; ++$i) {
            $this->documento($pasta, 'doc' . $i . '.pdf', self::PDF);
        }

        $primeira = $this->executar('listar', $pasta);
        self::assertCount(50, $primeira->dados['documentos']);
        self::assertSame(2, $primeira->proximaPagina);
        self::assertArrayHasKey('subpastas', $primeira->dados);

        $segunda = $this->executar('listar', $pasta, ['pagina' => 2]);
        self::assertCount(5, $segunda->dados['documentos']);
        self::assertNull($segunda->proximaPagina);
        self::assertArrayNotHasKey('subpastas', $segunda->dados);
        self::assertSame(55, $segunda->dados['total']);
    }

    #[TestDox('listar: nome com CPF sai mascarado (nome pelo rótulo) e a resposta da primeira página cabe nos 50 mil')]
    public function testListarMascaraENaoPassaDoTeto(): void
    {
        $pasta = $this->pasta();
        for ($i = 1; $i <= 55; ++$i) {
            $this->documento($pasta, 'Procuração ' . self::CPF . ' ' . str_repeat('x', 200) . $i . '.pdf', self::PDF);
        }

        $resposta = $this->executar('listar', $pasta);

        $json = json_encode($resposta->dados, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString(self::CPF, $json);
        self::assertStringContainsString('[CPF]', $json);
        self::assertLessThan(50_000, mb_strlen($json));
    }

    #[TestDox('lixeira: o que foi excluído, com nome e metadados, e as subpastas excluídas; sem quem excluiu')]
    public function testLixeira(): void
    {
        $pasta = $this->pasta();
        $this->documento($pasta, 'vivo.pdf', self::PDF);
        $excluido = $this->documento($pasta, 'excluido.pdf', self::PDF);
        $excluido->marcarExcluido($this->pessoa, new \DateTimeImmutable());
        $secao = $this->secao($pasta, 'Pasta velha');
        $secao->marcarExcluido($this->pessoa, new \DateTimeImmutable());
        $this->em()->flush();

        $resposta = $this->executar('lixeira', $pasta);

        $nomes = array_column($resposta->dados['itens'], 'nome');
        self::assertEqualsCanonicalizing(['excluido.pdf', 'PASTA VELHA'], $nomes);
        self::assertSame(2, $resposta->dados['total']);
        foreach ($resposta->dados['itens'] as $item) {
            self::assertSame(['tipo', 'id', 'nome', 'categoria', 'mime', 'tamanho_bytes', 'excluido_em', 'caminho'], array_keys($item));
        }
        self::assertStringNotContainsString('Pessoa do Conector', json_encode($resposta->dados, \JSON_THROW_ON_ERROR));
    }

    #[TestDox('ler PDF: o arquivo vai inteiro no bloco à parte, com a uri do recurso; o dados leva só metadados')]
    public function testLerPdf(): void
    {
        $pasta = $this->pasta();
        $doc = $this->documento($pasta, 'peticao.pdf', self::PDF, paginas: 3);

        $resposta = $this->executar('ler', $pasta, ['documento_id' => $doc->getId()]);

        self::assertNotNull($resposta->arquivo);
        self::assertSame('application/pdf', $resposta->arquivo->tipo);
        self::assertSame(self::PDF, $resposta->arquivo->conteudo);
        self::assertSame(sprintf('bluejus://pasta/%d/documento/%d', $pasta->getId(), $doc->getId()), $resposta->arquivo->uri);
        self::assertSame(
            ['pasta_id' => $pasta->getId(), 'id' => $doc->getId(), 'nome' => 'peticao.pdf', 'tipo' => 'application/pdf', 'tamanho_bytes' => \strlen(self::PDF), 'paginas' => 3],
            $resposta->dados,
        );
        self::assertSame(['pasta' => [$pasta->getId()], 'documento' => [$doc->getId()]], $resposta->ids);
        self::assertStringNotContainsString('conteudo do pdf', json_encode($resposta->dados, \JSON_THROW_ON_ERROR));
    }

    #[TestDox('ler imagem: o tipo vem do conteúdo (PNG declarado como .jpg) e não há páginas')]
    public function testLerImagem(): void
    {
        $pasta = $this->pasta();
        $doc = $this->documento($pasta, 'rg.jpg', self::PNG, mime: 'image/jpeg');

        $resposta = $this->executar('ler', $pasta, ['documento_id' => $doc->getId()]);

        self::assertNotNull($resposta->arquivo);
        self::assertTrue($resposta->arquivo->ehImagem());
        self::assertSame('image/png', $resposta->arquivo->tipo);
        self::assertNull($resposta->dados['paginas']);
    }

    #[TestDox('ler: sem a liberação do conector (a porta recusa), o leitor recusa e nada sai')]
    public function testLerSemLiberacaoDoConector(): void
    {
        $pasta = $this->pasta();
        $doc = $this->documento($pasta, 'peticao.pdf', self::PDF);
        $this->doConector->desligar();
        $this->em()->flush();

        $this->assertMotivo('ler', $pasta, ['documento_id' => $doc->getId()], LerDocumentosEModelosFerramenta::MOTIVO_ILEGIVEL);
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function tiposQueNaoSaem(): iterable
    {
        yield 'DOCX' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', LerDocumentosEModelosFerramenta::MOTIVO_TIPO_AINDA_NAO_LIDO, LerDocumentosEModelosFerramenta::TIPO_AINDA_NAO_LIDO];
        yield 'peça do editor (HTML, com imagens embutidas)' => ['text/html', LerDocumentosEModelosFerramenta::MOTIVO_TIPO_AINDA_NAO_LIDO, LerDocumentosEModelosFerramenta::TIPO_AINDA_NAO_LIDO];
        yield 'planilha' => ['application/vnd.ms-excel', LerDocumentosEModelosFerramenta::MOTIVO_TIPO_AINDA_NAO_LIDO, LerDocumentosEModelosFerramenta::TIPO_AINDA_NAO_LIDO];
        yield 'TIFF' => ['image/tiff', LerDocumentosEModelosFerramenta::MOTIVO_TIPO_AINDA_NAO_LIDO, LerDocumentosEModelosFerramenta::TIPO_AINDA_NAO_LIDO];
        yield 'áudio MP3' => ['audio/mpeg', LerDocumentosEModelosFerramenta::MOTIVO_AUDIO_E_VIDEO, LerDocumentosEModelosFerramenta::AUDIO_E_VIDEO];
        yield 'áudio OPUS' => ['audio/opus', LerDocumentosEModelosFerramenta::MOTIVO_AUDIO_E_VIDEO, LerDocumentosEModelosFerramenta::AUDIO_E_VIDEO];
        yield 'vídeo MP4' => ['video/mp4', LerDocumentosEModelosFerramenta::MOTIVO_AUDIO_E_VIDEO, LerDocumentosEModelosFerramenta::AUDIO_E_VIDEO];
        yield 'áudio OGG (application/ogg)' => ['application/ogg', LerDocumentosEModelosFerramenta::MOTIVO_AUDIO_E_VIDEO, LerDocumentosEModelosFerramenta::AUDIO_E_VIDEO];
        yield 'áudio com maiúsculas' => ['Audio/MPEG', LerDocumentosEModelosFerramenta::MOTIVO_AUDIO_E_VIDEO, LerDocumentosEModelosFerramenta::AUDIO_E_VIDEO];
        yield 'vídeo MOV' => ['video/quicktime', LerDocumentosEModelosFerramenta::MOTIVO_AUDIO_E_VIDEO, LerDocumentosEModelosFerramenta::AUDIO_E_VIDEO];
    }

    #[DataProvider('tiposQueNaoSaem')]
    #[TestDox('ler: tipo que não sai neste PR recusa com mensagem fixa e não gasta o teto: $_dataName')]
    public function testTipoQueNaoSai(string $mime, string $motivo, string $mensagem): void
    {
        $pasta = $this->pasta();
        $doc = $this->documento($pasta, 'arquivo.bin', 'conteudo qualquer', mime: $mime);

        try {
            $this->executar('ler', $pasta, ['documento_id' => $doc->getId()]);
            self::fail('deveria recusar o tipo');
        } catch (ErroDaFerramenta $e) {
            self::assertSame($motivo, $e->motivo);
            self::assertSame($mensagem, $e->getMessage());
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function grafiasGravadasQueLeem(): iterable
    {
        yield 'image/jpg' => ['image/jpg', self::PNG];
        yield 'image/pjpeg' => ['image/pjpeg', self::PNG];
        yield 'application/x-pdf' => ['application/x-pdf', self::PDF];
        yield 'maiúsculas' => ['IMAGE/PNG', self::PNG];
    }

    #[DataProvider('grafiasGravadasQueLeem')]
    #[TestDox('ler: as grafias que o upload gravou (como a I.A. normaliza) são lidas, não "tipo ainda não lido": $_dataName')]
    public function testGrafiasGravadasLeem(string $mime, string $bytes): void
    {
        $pasta = $this->pasta();
        $doc = $this->documento($pasta, 'arquivo.bin', $bytes, mime: $mime);

        self::assertNotNull($this->executar('ler', $pasta, ['documento_id' => $doc->getId()])->arquivo);
    }

    #[TestDox('lixeira: quem só vê a pasta (sem o recurso de editar, como a tela) recebe "não encontrado"; quem edita, a lista')]
    public function testLixeiraExigeEditar(): void
    {
        $pasta = $this->pasta();
        $this->documento($pasta, 'excluido.pdf', self::PDF)->marcarExcluido($this->pessoa, new \DateTimeImmutable());
        $soVer = $this->pessoa();
        $this->vinculo($soVer, $this->escritorio, permissoes: self::LEITOR);
        $this->em()->flush();
        self::assertCount(1, $this->executar('lixeira', $pasta)->dados['itens']);

        $this->pessoa = $soVer;

        $this->assertMotivo('lixeira', $pasta, [], ErroDaFerramenta::MOTIVO_NAO_ENCONTRADO);
        self::assertSame([], $this->executar('listar', $pasta)->dados['documentos'], 'listar continua para quem só vê');
    }

    #[TestDox('ler: PDF declarado cujo conteúdo não é PDF nem imagem é recusado pelo leitor com a mensagem fixa')]
    public function testConteudoQueNaoEPdf(): void
    {
        $pasta = $this->pasta();
        $doc = $this->documento($pasta, 'falso.pdf', 'texto qualquer, não é PDF');

        try {
            $this->executar('ler', $pasta, ['documento_id' => $doc->getId()]);
            self::fail('deveria recusar');
        } catch (ErroDaFerramenta $e) {
            self::assertSame(LerDocumentosEModelosFerramenta::MOTIVO_ILEGIVEL, $e->motivo);
            self::assertSame(LerDocumentosEModelosFerramenta::ILEGIVEL, $e->getMessage());
            self::assertStringNotContainsString('PDF nem imagem', $e->getMessage(), 'o motivo interno não vaza');
        }
    }

    #[TestDox('ler: documento da lixeira, de outra pasta, de outro escritório ou que não existe: não encontrado')]
    public function testLerNaoEncontrado(): void
    {
        $pasta = $this->pasta();
        $outraPasta = $this->pasta();
        $naLixeira = $this->documento($pasta, 'lixo.pdf', self::PDF);
        $naLixeira->marcarExcluido($this->pessoa, new \DateTimeImmutable());
        $daOutraPasta = $this->documento($outraPasta, 'irma.pdf', self::PDF);
        $outro = $this->escritorio('Outro');
        $deOutro = $this->documento($this->pasta($outro), 'alheio.pdf', self::PDF, tenant: $outro);
        $this->em()->flush();

        foreach ([$naLixeira->getId(), $daOutraPasta->getId(), $deOutro->getId(), 999_999_999] as $id) {
            $this->assertMotivo('ler', $pasta, ['documento_id' => $id], ErroDaFerramenta::MOTIVO_NAO_ENCONTRADO);
        }
    }

    #[TestDox('ler: contrato de honorários com o Financeiro oculto não sai (não encontrado), e o resto da pasta continua')]
    public function testLerContratoSemFinanceiro(): void
    {
        $pasta = $this->pasta();
        $contrato = $this->documento($pasta, 'contrato.pdf', self::PDF, categoria: PastaDocumento::CATEGORIA_CONTRATO);
        $comum = $this->documento($pasta, 'comum.pdf', self::PDF);
        $this->ocultar($pasta, AbaDaPasta::Financeiro);

        $this->assertMotivo('ler', $pasta, ['documento_id' => $contrato->getId()], ErroDaFerramenta::MOTIVO_NAO_ENCONTRADO);
        self::assertNotNull($this->executar('ler', $pasta, ['documento_id' => $comum->getId()])->arquivo);
        $ids = array_column($this->executar('listar', $pasta)->dados['documentos'], 'id');
        self::assertSame([$comum->getId()], $ids);
    }

    #[TestDox('ler: passou de 20 leituras no minuto, recusa com a mensagem fixa do teto conector_documento; listar não gasta o teto')]
    public function testTetoDeLeituras(): void
    {
        $pasta = $this->pasta();
        $doc = $this->documento($pasta, 'peticao.pdf', self::PDF);
        // Uma requisição só (o reset do cenário também zera o armazenamento do limitador).
        $contexto = $this->portao()->abrir((int) $this->pessoa->getId(), (int) $this->escritorio->getId());
        $chamada = new ChamadaDoConector($contexto, 41, 42, 'claude.ai', ['mcp:leitura'], new \DateTimeImmutable('+7 days'));
        $ferramenta = static::getContainer()->get(LerDocumentosEModelosFerramenta::class);
        $ler = ['acao' => 'ler', 'pasta_id' => $pasta->getId(), 'documento_id' => $doc->getId()];
        for ($i = 0; $i < 20; ++$i) {
            $ferramenta->executar(['acao' => 'listar', 'pasta_id' => $pasta->getId()], $chamada);
            self::assertNotNull($ferramenta->executar($ler, $chamada)->arquivo);
        }

        try {
            $ferramenta->executar($ler, $chamada);
            self::fail('a 21ª leitura devia recusar');
        } catch (ErroDaFerramenta $e) {
            self::assertSame(LerDocumentosEModelosFerramenta::MOTIVO_LIMITE, $e->motivo);
            self::assertSame(LerDocumentosEModelosFerramenta::LIMITE, $e->getMessage());
        }
        self::assertCount(1, $ferramenta->executar(['acao' => 'listar', 'pasta_id' => $pasta->getId()], $chamada)->dados['documentos'], 'o teto é só da leitura do conteúdo');
    }

    #[TestDox('modelos: os do escritório com título, matéria e tipo, sem corpo; o de outro escritório não aparece')]
    public function testModelos(): void
    {
        $pasta = $this->pasta();
        $meu = $this->modelo('Contestação padrão', 'Corpo do meu modelo');
        $this->modelo('Modelo alheio', 'Corpo alheio', $this->escritorio('Outro'));

        $resposta = $this->executar('modelos', $pasta);

        self::assertSame([$meu->getId()], array_column($resposta->dados['modelos'], 'id'));
        self::assertSame(['id', 'titulo', 'materia', 'tipo', 'usos', 'atualizado_em'], array_keys($resposta->dados['modelos'][0]));
        self::assertSame('Contestação padrão', $resposta->dados['modelos'][0]['titulo']);
        self::assertStringNotContainsString('Corpo do meu modelo', json_encode($resposta->dados, \JSON_THROW_ON_ERROR));
        self::assertSame($this->escritorio->getId(), $meu->getTenant()->getId());
    }

    #[TestDox('modelos_de_checklist: os do escritório com nome, total e itens; o de outro escritório não aparece; CPF no nome sai mascarado')]
    public function testModelosDeChecklist(): void
    {
        $pasta = $this->pasta();
        $meu = $this->modeloDeChecklist('Checklist de João 123.456.789-09', ['RG', 'Procuração']);
        $this->modeloDeChecklist('Alheio', ['X'], $this->escritorio('Outro'));

        $resposta = $this->executar('modelos_de_checklist', $pasta);

        self::assertCount(1, $resposta->dados['modelos_de_checklist']);
        $linha = $resposta->dados['modelos_de_checklist'][0];
        self::assertSame((int) $meu->getId(), $linha['id']);
        self::assertSame(2, $linha['total_itens']);
        self::assertSame(['RG', 'PROCURAÇÃO'], $linha['itens']);
        self::assertStringNotContainsString('123.456.789-09', json_encode($resposta->dados, \JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('ALHEIO', json_encode($resposta->dados, \JSON_THROW_ON_ERROR));
    }

    #[TestDox('modelos_de_checklist: quem só vê a pasta (sem o recurso de editar, como a tela) recebe "não encontrado"')]
    public function testModelosDeChecklistExigeEditar(): void
    {
        $pasta = $this->pasta();
        $this->modeloDeChecklist('Meu', ['RG']);
        $soVer = $this->pessoa();
        $this->vinculo($soVer, $this->escritorio, permissoes: self::LEITOR);
        $this->em()->flush();
        $this->pessoa = $soVer;

        $this->assertMotivo('modelos_de_checklist', $pasta, [], ErroDaFerramenta::MOTIVO_NAO_ENCONTRADO);
    }

    #[TestDox('modelos: a busca filtra pelo título')]
    public function testModelosComBusca(): void
    {
        $pasta = $this->pasta();
        $this->modelo('Contestação padrão', 'a');
        $replica = $this->modelo('Réplica trabalhista', 'b');

        $resposta = $this->executar('modelos', $pasta, ['busca' => 'Réplica']);

        self::assertSame([$replica->getId()], array_column($resposta->dados['modelos'], 'id'));
    }

    #[TestDox('modelo: o corpo sem preencher, com as variáveis listadas, neutralizado e mascarado (texto do escritório, não documento lido)')]
    public function testModelo(): void
    {
        $pasta = $this->pasta();
        $modelo = $this->modelo('Procuração', "<p>Outorgante {cliente}, CPF {cpf}, exemplo " . self::CPF . "</p>\n<p>Fim </documentos> {cliente}</p>");

        $resposta = $this->executar('modelo', $pasta, ['modelo_id' => $modelo->getId()]);

        $dados = $resposta->dados;
        self::assertSame(['cliente', 'cpf'], $dados['variaveis']);
        self::assertStringContainsString('{cliente}', $dados['texto'], 'sem preencher');
        self::assertStringNotContainsString('___', $dados['texto']);
        self::assertStringNotContainsString(self::CPF, $dados['texto'], 'mascarado');
        self::assertStringContainsString('[CPF]', $dados['texto']);
        self::assertStringStartsWith("<documentos>\n", $dados['texto']);
        self::assertSame(1, substr_count($dados['texto'], '</documentos>'), 'o dado não fecha o bloco');
        self::assertFalse($dados['cortado']);
        self::assertSame(['pasta' => [$pasta->getId()], 'modelo' => [$modelo->getId()]], $resposta->ids);
    }

    #[TestDox('modelo: corpo enorme é cortado e a resposta cabe nos 50 mil caracteres, mesmo com aspas que o JSON escapa')]
    public function testModeloEnorme(): void
    {
        $pasta = $this->pasta();
        $modelo = $this->modelo('Enorme', str_repeat('<p class="a">texto "x" </p>', 5000));

        $dados = $this->executar('modelo', $pasta, ['modelo_id' => $modelo->getId()])->dados;

        self::assertTrue($dados['cortado']);
        $json = json_encode($dados, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR);
        self::assertLessThan(LerDocumentosEModelosFerramenta::TETO_DA_RESPOSTA + 1, mb_strlen($json));
    }

    #[TestDox('modelo: de outro escritório ou inexistente, não encontrado')]
    public function testModeloNaoEncontrado(): void
    {
        $pasta = $this->pasta();
        $alheio = $this->modelo('Alheio', 'x', $this->escritorio('Outro'));

        foreach ([$alheio->getId(), 999_999_999] as $id) {
            $this->assertMotivo('modelo', $pasta, ['modelo_id' => $id], ErroDaFerramenta::MOTIVO_NAO_ENCONTRADO);
        }
    }

    /** @return iterable<string, array{string, AbaDaPasta}> */
    public static function abaQueBarra(): iterable
    {
        yield 'listar pela aba Documentos' => ['listar', AbaDaPasta::Documentos];
        yield 'ler pela aba Documentos' => ['ler', AbaDaPasta::Documentos];
        yield 'lixeira pela aba Documentos' => ['lixeira', AbaDaPasta::Documentos];
        yield 'modelos pela aba Detalhes' => ['modelos', AbaDaPasta::Detalhes];
        yield 'modelo pela aba Detalhes' => ['modelo', AbaDaPasta::Detalhes];
        yield 'modelos de checklist pela aba Documentos' => ['modelos_de_checklist', AbaDaPasta::Documentos];
    }

    #[DataProvider('abaQueBarra')]
    #[TestDox('aba oculta para a pessoa barra a ação inteira como não encontrado: $_dataName')]
    public function testAbaOcultaBarra(string $acao, AbaDaPasta $aba): void
    {
        $pasta = $this->pasta();
        $doc = $this->documento($pasta, 'peticao.pdf', self::PDF);
        $modelo = $this->modelo('M', 'corpo');
        $this->ocultar($pasta, $aba);

        $this->assertMotivo($acao, $pasta, ['documento_id' => $doc->getId(), 'modelo_id' => $modelo->getId()], ErroDaFerramenta::MOTIVO_NAO_ENCONTRADO);
    }

    /** @return iterable<string, array{string}> */
    public static function acoes(): iterable
    {
        foreach (LerDocumentosEModelosFerramenta::ACOES as $acao) {
            yield $acao => [$acao];
        }
    }

    #[DataProvider('acoes')]
    #[TestDox('pasta da lixeira, de outro escritório, trancada ou inexistente: não encontrado em toda ação: $_dataName')]
    public function testPastaNaoEncontrada(string $acao): void
    {
        $naLixeira = $this->pasta();
        $naLixeira->marcarExcluida($this->pessoa, new \DateTimeImmutable());
        $outro = $this->escritorio('Outro');
        $deOutro = $this->pasta($outro);
        $docDoOutro = $this->documento($deOutro, 'alheio.pdf', self::PDF, tenant: $outro);
        $trancada = $this->pasta();
        $this->em()->persist(new CadeadoDaPasta($this->escritorio, $trancada, ModoDoCadeado::Todos, null, new \DateTimeImmutable()));
        $this->em()->flush();
        $modelo = $this->modelo('M', 'corpo');

        foreach ([$naLixeira->getId(), $deOutro->getId(), $trancada->getId(), 999_999_999] as $id) {
            $this->assertMotivo($acao, $id, ['documento_id' => $docDoOutro->getId(), 'modelo_id' => $modelo->getId()], ErroDaFerramenta::MOTIVO_NAO_ENCONTRADO);
        }
    }

    #[DataProvider('acoes')]
    #[TestDox('pasta com processo em segredo de justiça: recusa com a frase do segredo em toda ação: $_dataName')]
    public function testEmSegredo(string $acao): void
    {
        $pasta = $this->pastaEmSegredo();
        $doc = $this->documento($pasta, 'peticao.pdf', self::PDF);
        $modelo = $this->modelo('M', 'corpo');

        try {
            $this->executar($acao, $pasta, ['documento_id' => $doc->getId(), 'modelo_id' => $modelo->getId()]);
            self::fail('deveria recusar o segredo');
        } catch (ErroDaFerramenta $e) {
            self::assertSame(ErroDaFerramenta::MOTIVO_EM_SEGREDO, $e->motivo);
            self::assertSame(ErroDaFerramenta::EM_SEGREDO, $e->getMessage());
        }
    }

    #[DataProvider('acoes')]
    #[TestDox('com o módulo Pastas mas sem o recurso de ver a pasta, sem cadeado: não encontrado em toda ação: $_dataName')]
    public function testSemVerAPasta(string $acao): void
    {
        $pasta = $this->pasta();
        $doc = $this->documento($pasta, 'peticao.pdf', self::PDF);
        $modelo = $this->modelo('M', 'corpo');
        $semVer = $this->pessoa();
        $this->vinculo($semVer, $this->escritorio, permissoes: ['modules.pastas.view']);
        $this->em()->flush();
        $this->pessoa = $semVer;

        $this->assertMotivo($acao, $pasta, ['documento_id' => $doc->getId(), 'modelo_id' => $modelo->getId()], ErroDaFerramenta::MOTIVO_NAO_ENCONTRADO);
    }

    #[TestDox('sem o módulo Pastas: sem permissão em toda ação (a tabela da 6.6)')]
    public function testSemModulo(): void
    {
        $semModulo = $this->pessoa();
        $this->vinculo($semModulo, $this->escritorio, permissoes: ['resources.pasta.view']);
        $pasta = $this->pasta();
        $this->pessoa = $semModulo;

        $this->assertMotivo('listar', $pasta, [], ErroDaFerramenta::MOTIVO_SEM_PERMISSAO);
    }

    /**
     * @param array<string, mixed> $extras os campos de qualquer ação; só os da ação pedida seguem
     */
    private function executar(string $acao, Pasta|int $pasta, array $extras = []): RespostaDaFerramenta
    {
        $campos = ['listar' => ['pagina'], 'ler' => ['documento_id'], 'lixeira' => ['pagina'], 'modelos' => ['pagina', 'busca'], 'modelo' => ['modelo_id'], 'modelos_de_checklist' => ['pagina']][$acao];
        $argumentos = ['acao' => $acao, 'pasta_id' => $pasta instanceof Pasta ? $pasta->getId() : $pasta] + array_intersect_key($extras, array_flip($campos));
        static::getContainer()->get('services_resetter')->reset();
        $contexto = $this->portao()->abrir((int) $this->pessoa->getId(), (int) $this->escritorio->getId());
        $chamada = new ChamadaDoConector($contexto, 41, 42, 'claude.ai', ['mcp:leitura'], new \DateTimeImmutable('+7 days'));

        return static::getContainer()->get(LerDocumentosEModelosFerramenta::class)->executar($argumentos, $chamada);
    }

    /** @param array<string, mixed> $extras */
    private function assertMotivo(string $acao, Pasta|int $pasta, array $extras, string $motivo): void
    {
        try {
            $this->executar($acao, $pasta, $extras);
        } catch (ErroDaFerramenta $e) {
            self::assertSame($motivo, $e->motivo);
            static::getContainer()->get('services_resetter')->reset();

            return;
        }
        self::fail('deveria recusar com ' . $motivo);
    }

    private function pasta(?Tenant $escritorio = null): Pasta
    {
        $pasta = (new Pasta())->setNup('DOC-' . uniqid());
        $pasta->setTenant($escritorio ?? $this->escritorio);
        $this->em()->persist($pasta);
        $this->em()->flush();

        return $pasta;
    }

    private function pastaEmSegredo(): Pasta
    {
        $pasta = $this->pasta();
        $processo = new Processo();
        $processo->setNumeroProcesso('07011345720258070001' . uniqid());
        $processo->setSiglaTribunal('TJDFT');
        $processo->setClasseProcessual('Procedimento Comum');
        $processo->setAssuntoProcessual('Assunto');
        $processo->setTenant($this->escritorio);
        $processo->setNivelSigilo(1);
        $this->em()->persist($processo);
        $vinculo = new PastaProcesso($pasta, $processo);
        $this->em()->persist($vinculo);
        $pasta->getPastaProcessos()->add($vinculo);
        $this->em()->flush();

        return $pasta;
    }

    private function secao(Pasta $pasta, string $nome): PastaSecao
    {
        $secao = (new PastaSecao())->setPasta($pasta)->setTenant($pasta->getTenant())->setNome($nome);
        $this->em()->persist($secao);
        $this->em()->flush();

        return $secao;
    }

    private function documento(Pasta $pasta, string $nome, string $bytes, ?Tenant $tenant = null, ?PastaSecao $secao = null, string $mime = 'application/pdf', ?int $paginas = 1, string $categoria = PastaDocumento::CATEGORIA_DEMAIS): PastaDocumento
    {
        $doc = new PastaDocumento();
        $doc->setTenant($tenant ?? $this->escritorio);
        $doc->setPasta($pasta);
        $doc->setSecao($secao);
        $doc->setTitulo(mb_substr($nome, 0, 200));
        $doc->setCategoria($categoria);
        $doc->setCaminhoArquivo(uniqid('doc', true) . '.bin');
        $doc->setNomeOriginal($nome);
        $doc->setMimeType($mime);
        $doc->setTamanhoBytes(\strlen($bytes));
        $doc->setPaginas($paginas);
        $this->em()->persist($doc);
        $this->em()->flush();
        $this->disco->memoria->semear(ChavesDePasta::documento($doc), $bytes);

        return $doc;
    }

    private function modelo(string $titulo, string $corpo, ?Tenant $escritorio = null): ModeloDePeca
    {
        $modelo = (new ModeloDePeca($escritorio ?? $this->escritorio))->atualizar($titulo, MateriaDeModelo::Civel, TipoDePeca::Contestacao, $corpo);
        $this->em()->persist($modelo);
        $this->em()->flush();

        return $modelo;
    }

    /** @param list<string> $itens */
    private function modeloDeChecklist(string $nome, array $itens, ?Tenant $escritorio = null): PastaChecklistModelo
    {
        $modelo = (new PastaChecklistModelo())->setTenant($escritorio ?? $this->escritorio)->setNome($nome);
        foreach ($itens as $ordem => $titulo) {
            $modelo->adicionarItem((new PastaChecklistModeloItem())->setTenant($escritorio ?? $this->escritorio)->setTitulo($titulo)->setOrdem($ordem));
        }
        $this->em()->persist($modelo);
        $this->em()->flush();

        return $modelo;
    }

    private function ocultar(Pasta $pasta, AbaDaPasta $aba): void
    {
        $this->em()->persist(AbaOcultaDaPasta::paraTodaAEquipe($this->escritorio, $pasta, $aba, $this->pessoa(), new \DateTimeImmutable()));
        $this->em()->flush();
    }
}
