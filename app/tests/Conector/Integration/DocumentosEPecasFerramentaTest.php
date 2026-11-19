<?php

declare(strict_types=1);

namespace App\Tests\Conector\Integration;

use App\Conector\DTO\ChamadaDoConector;
use App\Conector\DTO\RespostaDaFerramenta;
use App\Conector\Entity\ClienteDoConector;
use App\Conector\Entity\ConsentimentoDoConector;
use App\Conector\Entity\EscritorioDoConector;
use App\Conector\Enum\TipoDeClienteDoConector;
use App\Conector\Exception\ErroDaFerramenta;
use App\Conector\Ferramenta\ConfirmarAcaoFerramenta;
use App\Conector\Ferramenta\DocumentosEPecasFerramenta;
use App\Conector\Ferramenta\TabelaDeAbaEChave;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Armazenamento\ChavesDePasta;
use App\Pasta\Entity\AbaDaPasta;
use App\Pasta\Entity\AbaOcultaDaPasta;
use App\Pasta\Entity\ModeloDePeca;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaChecklistItem;
use App\Pasta\Entity\PastaChecklistModelo;
use App\Pasta\Entity\PastaChecklistModeloItem;
use App\Pasta\Entity\PastaDocumento;
use App\Pasta\Entity\PastaProcesso;
use App\Pasta\Entity\PastaSecao;
use App\Pasta\Enum\MateriaDeModelo;
use App\Pasta\Enum\TipoDePeca;
use App\Processo\Entity\Processo;
use App\Tests\Conector\Support\CenarioDoConectorTrait;
use App\Tests\Shared\Doubles\ArmazenamentoEmMemoriaNoContainer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * `documentos_e_pecas` com o banco e o portão de verdade (spec mcp-remoto-oauth 6.6 e 6.7, PR 12): cada ação direta
 * grava pelo UseCase da tela; cada ação por proposta devolve `aguardando_confirmacao` SEM gravar e só muda algo depois da
 * `confirmar_acao`; documento de outra pasta, pasta de outro escritório, quem só vê, o segredo de justiça e a aba
 * oculta recusam sem gravar; e a confirmação confere tudo de novo.
 */
#[CoversClass(DocumentosEPecasFerramenta::class)]
final class DocumentosEPecasFerramentaTest extends KernelTestCase
{
    use CenarioDoConectorTrait;

    private const EDITOR = ['modules.pastas.view', 'resources.pasta.view', 'resources.pasta.edit'];
    private const LEITOR = ['modules.pastas.view', 'resources.pasta.view'];
    private const PDF = "%PDF-1.7\nconteudo do pdf";

    private Tenant $escritorio;
    private User $pessoa;
    private ArmazenamentoEmMemoriaNoContainer $disco;
    private EscritorioDoConector $doConector;
    private ConsentimentoDoConector $consentimento;

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

    // ── Esquema ──────────────────────────────────────────────────────────────

    #[TestDox('o esquema: acao é a lista da tabela da 6.6, entrada fechada e sem oneOf; cada campo de cada operação está declarado')]
    public function testEsquema(): void
    {
        $ferramenta = static::getContainer()->get(DocumentosEPecasFerramenta::class);
        $esquema = $ferramenta->esquemaDeEntrada();

        self::assertSame(TabelaDeAbaEChave::acoes('documentos_e_pecas'), $esquema['properties']['acao']['enum']);
        self::assertFalse($esquema['additionalProperties']);
        self::assertSame(['acao', 'pasta_id'], $esquema['required']);
        self::assertStringNotContainsString('oneOf', (string) json_encode($esquema));
        self::assertStringContainsString('Markdown não é convertido', $esquema['properties']['conteudo']['description']);
        self::assertStringContainsString('confirmar_acao', $ferramenta->descricao());
    }

    // ── Direto: documentos e subpastas ───────────────────────────────────────

    #[TestDox('subpasta criar: nasce na raiz e dentro de outra subpasta, na pasta da chamada')]
    public function testCriarSubpasta(): void
    {
        $pasta = $this->pasta();

        $raiz = $this->executar(['acao' => 'subpasta', 'operacao' => 'criar', 'pasta_id' => $pasta->getId(), 'nome' => 'Provas']);
        $filha = $this->executar(['acao' => 'subpasta', 'operacao' => 'criar', 'pasta_id' => $pasta->getId(), 'nome' => 'Fotos', 'pai_id' => $raiz->dados['resultado']['subpasta']['id']]);

        self::assertSame('gravado', $raiz->dados['estado']);
        $mae = $this->em()->find(PastaSecao::class, $raiz->dados['resultado']['subpasta']['id']);
        $secao = $this->em()->find(PastaSecao::class, $filha->dados['resultado']['subpasta']['id']);
        self::assertSame($pasta->getId(), $mae?->getPasta()?->getId());
        self::assertNull($mae->getPai());
        self::assertSame($mae->getId(), $secao?->getPai()?->getId());
        self::assertSame($this->escritorio->getId(), $secao->getTenant()?->getId());
        self::assertSame(['pasta' => [$pasta->getId()], 'subpasta' => [$mae->getId()]], $raiz->ids);
    }

    #[TestDox('mover: documentos para uma subpasta e de volta para a raiz; subpasta para dentro de outra')]
    public function testMover(): void
    {
        $pasta = $this->pasta();
        $destino = $this->secao($pasta, 'Destino');
        $outra = $this->secao($pasta, 'Outra');
        $doc = $this->documento($pasta, 'a.pdf');

        $this->executar(['acao' => 'mover', 'pasta_id' => $pasta->getId(), 'documentos' => [$doc->getId()], 'subpastas' => [$outra->getId()], 'destino_id' => $destino->getId()]);

        $doc = $this->recarregar($doc);
        $outra = $this->recarregar($outra);
        self::assertSame($destino->getId(), $doc->getSecao()?->getId());
        self::assertSame($destino->getId(), $outra->getPai()?->getId());

        $volta = $this->executar(['acao' => 'mover', 'pasta_id' => $pasta->getId(), 'documentos' => [$doc->getId()]]);

        $doc = $this->recarregar($doc);
        self::assertNull($doc->getSecao());
        self::assertSame(['documentos' => 1, 'subpastas' => 0], $volta->dados['resultado']['movidos']);
        self::assertNull($volta->dados['resultado']['destino_id']);
    }

    #[TestDox('copiar: a cópia nasce na pasta e na subpasta de destino, e o original fica')]
    public function testCopiar(): void
    {
        $pasta = $this->pasta();
        $destino = $this->secao($pasta, 'Cópias');
        $doc = $this->documento($pasta, 'original.pdf');

        $resposta = $this->executar(['acao' => 'copiar', 'pasta_id' => $pasta->getId(), 'documentos' => [$doc->getId()], 'destino_id' => $destino->getId()]);

        $copias = $resposta->dados['resultado']['copias'];
        self::assertCount(1, $copias);
        self::assertNotSame($doc->getId(), $copias[0]['id']);
        $copia = $this->em()->find(PastaDocumento::class, $copias[0]['id']);
        self::assertSame($pasta->getId(), $copia?->getPasta()?->getId());
        self::assertSame($destino->getId(), $copia->getSecao()?->getId());
        self::assertSame($pasta->getId(), $doc->getPasta()?->getId());
    }

    #[TestDox('favoritar: marca e desmarca um documento e uma subpasta, pelo estado final pedido')]
    public function testFavoritar(): void
    {
        $pasta = $this->pasta();
        $doc = $this->documento($pasta, 'a.pdf');
        $secao = $this->secao($pasta, 'Fav');
        $base = ['acao' => 'favoritar', 'pasta_id' => $pasta->getId()];

        $marcou = $this->executar($base + ['item' => 'documento', 'alvo_id' => $doc->getId(), 'marcado' => true]);
        $desmarcou = $this->executar($base + ['item' => 'documento', 'alvo_id' => $doc->getId(), 'marcado' => false]);
        $subpasta = $this->executar($base + ['item' => 'subpasta', 'alvo_id' => $secao->getId(), 'marcado' => true]);

        self::assertTrue($marcou->dados['resultado']['marcado']);
        self::assertFalse($desmarcou->dados['resultado']['marcado']);
        self::assertTrue($subpasta->dados['resultado']['marcado']);
    }

    #[TestDox('reordenar: documentos e subpastas na ordem pedida; id de outra pasta recusa sem mexer em nada')]
    public function testReordenar(): void
    {
        $pasta = $this->pasta();
        [$a, $b, $c] = [$this->documento($pasta, 'a.pdf'), $this->documento($pasta, 'b.pdf'), $this->documento($pasta, 'c.pdf')];
        [$s1, $s2] = [$this->secao($pasta, 'Um'), $this->secao($pasta, 'Dois')];
        $alheio = $this->documento($this->pasta(), 'alheio.pdf');

        $this->executar(['acao' => 'reordenar', 'operacao' => 'de_documentos', 'pasta_id' => $pasta->getId(), 'ids' => [$c->getId(), $a->getId(), $b->getId()]]);
        $this->executar(['acao' => 'reordenar', 'operacao' => 'de_subpastas', 'pasta_id' => $pasta->getId(), 'ids' => [$s2->getId(), $s1->getId()]]);

        [$a, $b, $c, $s1, $s2] = array_map($this->recarregar(...), [$a, $b, $c, $s1, $s2]);
        self::assertSame([2, 3, 1], [$a->getOrdem(), $b->getOrdem(), $c->getOrdem()]);
        self::assertSame([2, 1], [$s1->getOrdem(), $s2->getOrdem()]);
        $this->assertMotivo(['acao' => 'reordenar', 'operacao' => 'de_documentos', 'pasta_id' => $pasta->getId(), 'ids' => [$a->getId(), $alheio->getId()]], ErroDaFerramenta::MOTIVO_NAO_ENCONTRADO);
    }

    #[TestDox('restaurar: o documento e a subpasta que estavam na lixeira voltam; o que está vivo não é "restaurável"')]
    public function testRestaurar(): void
    {
        $pasta = $this->pasta();
        $doc = $this->documento($pasta, 'a.pdf');
        $secao = $this->secao($pasta, 'Velha');
        $doc->marcarExcluido($this->pessoa, new \DateTimeImmutable());
        $secao->marcarExcluido($this->pessoa, new \DateTimeImmutable());
        $vivo = $this->documento($pasta, 'vivo.pdf');
        $this->em()->flush();

        $resposta = $this->executar(['acao' => 'restaurar', 'operacao' => 'restaurar', 'pasta_id' => $pasta->getId(), 'documentos' => [$doc->getId()], 'subpastas' => [$secao->getId()]]);

        self::assertSame(['documentos' => 1, 'subpastas' => 1], $resposta->dados['resultado']['restaurados']);
        self::assertNull($this->naLixeira(PastaDocumento::class, (int) $doc->getId()));
        self::assertNull($this->naLixeira(PastaSecao::class, (int) $secao->getId()));
        $this->assertMotivo(['acao' => 'restaurar', 'operacao' => 'restaurar', 'pasta_id' => $pasta->getId(), 'documentos' => [$vivo->getId()]], ErroDaFerramenta::MOTIVO_NAO_ENCONTRADO);
    }

    // ── Direto: peças e modelos ──────────────────────────────────────────────

    #[TestDox('escrever_peca com HTML: o texto vai pelo saneador (sem script) e vira documento PECA da pasta, na subpasta pedida')]
    public function testEscreverPecaComHtml(): void
    {
        $pasta = $this->pasta();
        $secao = $this->secao($pasta, 'Peças');

        $resposta = $this->executar(['acao' => 'escrever_peca', 'pasta_id' => $pasta->getId(), 'titulo' => 'Contestação', 'conteudo' => '<p>Texto da peça</p><script>alert(1)</script>', 'subpasta_id' => $secao->getId()]);

        $id = $resposta->dados['resultado']['documento']['id'];
        $doc = $this->em()->find(PastaDocumento::class, $id);
        self::assertSame(PastaDocumento::CATEGORIA_PECA, $doc?->getCategoria());
        self::assertSame('text/html', $doc->getMimeType());
        self::assertSame($pasta->getId(), $doc->getPasta()?->getId());
        self::assertSame($secao->getId(), $doc->getSecao()?->getId());
        $html = $this->disco->memoria->ler(ChavesDePasta::documento($doc));
        self::assertStringContainsString('Texto da peça', $html);
        self::assertStringNotContainsString('<script', $html);
        self::assertSame(['id' => $id, 'titulo' => 'CONTESTAÇÃO', 'categoria' => 'PECA'], $resposta->dados['resultado']['documento']);
    }

    #[TestDox('escrever_peca com texto simples: escapa, cada bloco separado por linha em branco vira um parágrafo e a quebra simples vira <br>')]
    public function testEscreverPecaComTextoSimples(): void
    {
        $pasta = $this->pasta();

        $resposta = $this->executar(['acao' => 'escrever_peca', 'pasta_id' => $pasta->getId(), 'titulo' => 'Nota', 'conteudo' => "Primeiro & mais\nsegunda linha\n\nSegundo bloco", 'categoria' => 'DEMAIS']);

        $doc = $this->em()->find(PastaDocumento::class, $resposta->dados['resultado']['documento']['id']);
        self::assertSame('DEMAIS', $doc?->getCategoria());
        $html = $this->disco->memoria->ler(ChavesDePasta::documento($doc));
        self::assertStringContainsString('<p>Primeiro &amp; mais<br />segunda linha</p>', $html);
        self::assertStringContainsString('<p>Segundo bloco</p>', $html);
    }

    #[TestDox('escrever_peca: categoria fora da lista e título longo demais são recusados sem gravar')]
    public function testEscreverPecaRecusas(): void
    {
        $pasta = $this->pasta();
        $base = ['acao' => 'escrever_peca', 'pasta_id' => $pasta->getId(), 'conteudo' => 'Texto'];

        $this->assertMotivo($base + ['titulo' => 'X', 'categoria' => 'INVENTADA'], ErroDaFerramenta::MOTIVO_ENTRADA_INVALIDA);
        $this->assertMotivo($base + ['titulo' => str_repeat('a', 252)], 'regra_de_negocio');
        self::assertSame(0, $this->contar('pasta_documento', $pasta));
    }

    #[TestDox('destino_da_peca: define o processo do vínculo e volta ao principal sem ele; vínculo de outra pasta é recusado')]
    public function testDestinoDaPeca(): void
    {
        $pasta = $this->pasta();
        $vinculo = $this->vincularProcesso($pasta);
        $alheio = $this->vincularProcesso($this->pasta());
        $peca = $this->documento($pasta, 'peca.html', mime: 'text/html');
        $base = ['acao' => 'destino_da_peca', 'pasta_id' => $pasta->getId(), 'documento_id' => $peca->getId()];

        $this->executar($base + ['vinculo_id' => $vinculo->getId()]);
        $peca = $this->recarregar($peca);
        self::assertSame($vinculo->getId(), $peca->getProcessoDestino()?->getId());

        $this->assertMotivo($base + ['vinculo_id' => $alheio->getId()], 'regra_de_negocio');

        $this->executar($base);
        $peca = $this->recarregar($peca);
        self::assertNull($peca->getProcessoDestino());
    }

    #[TestDox('usar_modelo: preenchido no servidor; vira documento PECA da pasta e a resposta leva só id e título, nunca o texto')]
    public function testUsarModelo(): void
    {
        $pasta = $this->pasta();
        $modelo = $this->modelo('Procuração geral', '<p>Texto padrão do modelo {nome_do_cliente}</p>');

        $resposta = $this->executar(['acao' => 'usar_modelo', 'pasta_id' => $pasta->getId(), 'modelo_id' => $modelo->getId()]);

        $documento = $resposta->dados['resultado']['documento'];
        self::assertSame(['id', 'titulo', 'categoria'], array_keys($documento));
        self::assertStringContainsStringIgnoringCase('Procuração geral', $documento['titulo']);
        self::assertStringNotContainsString('Texto padrão', json_encode($resposta->dados, \JSON_THROW_ON_ERROR));
        $doc = $this->em()->find(PastaDocumento::class, $documento['id']);
        self::assertSame($pasta->getId(), $doc?->getPasta()?->getId());
        self::assertStringContainsString('Texto padrão do modelo', $this->disco->memoria->ler(ChavesDePasta::documento($doc)));
        self::assertSame(1, $this->recarregar($modelo)->getUsos());
    }

    #[TestDox('salvar_modelo novo é direto e fica no escritório; favoritar_modelo marca o modelo e a matéria')]
    public function testSalvarModeloNovoEFavoritar(): void
    {
        $pasta = $this->pasta();

        $novo = $this->executar(['acao' => 'salvar_modelo', 'operacao' => 'salvar', 'pasta_id' => $pasta->getId(), 'titulo' => 'Réplica padrão', 'materia' => 'civel', 'tipo' => 'replica', 'corpo' => '<p>Corpo do modelo</p>']);

        self::assertSame('gravado', $novo->dados['estado']);
        self::assertTrue($novo->dados['resultado']['criado']);
        $modelo = $this->em()->find(ModeloDePeca::class, $novo->dados['resultado']['modelo']['id']);
        self::assertSame($this->escritorio->getId(), $modelo?->getTenant()?->getId());
        self::assertSame('Réplica padrão', $modelo->getTitulo());

        $base = ['acao' => 'favoritar_modelo', 'pasta_id' => $pasta->getId()];
        $pelaMateria = $this->executar($base + ['materia' => 'civel']);
        $peloModelo = $this->executar($base + ['modelo_id' => $modelo->getId()]);
        self::assertTrue($pelaMateria->dados['resultado']['favorita']);
        self::assertTrue($peloModelo->dados['resultado']['favorito']);
        $this->assertMotivo($base + ['modelo_id' => $modelo->getId(), 'materia' => 'civel'], ErroDaFerramenta::MOTIVO_ENTRADA_INVALIDA);
        $this->assertMotivo(['acao' => 'salvar_modelo', 'operacao' => 'salvar', 'pasta_id' => $pasta->getId(), 'titulo' => 'X', 'materia' => 'nada', 'tipo' => 'replica', 'corpo' => 'y'], ErroDaFerramenta::MOTIVO_ENTRADA_INVALIDA);
    }

    // ── Direto: checklist ────────────────────────────────────────────────────

    #[TestDox('checklist_item: adicionar, marcar pelo estado final (chamar duas vezes não desfaz) e reordenar')]
    public function testChecklistItem(): void
    {
        $pasta = $this->pasta();
        $base = ['acao' => 'checklist_item', 'pasta_id' => $pasta->getId()];

        $novo = $this->executar($base + ['operacao' => 'adicionar', 'titulo' => 'RG do cliente']);
        $outro = $this->executar($base + ['operacao' => 'adicionar', 'titulo' => 'Comprovante']);
        $id = $novo->dados['resultado']['item']['id'];
        $this->executar($base + ['operacao' => 'marcar', 'item_id' => $id, 'concluido' => true]);
        $segunda = $this->executar($base + ['operacao' => 'marcar', 'item_id' => $id, 'concluido' => true]);
        $this->executar($base + ['operacao' => 'reordenar', 'ids' => [$outro->dados['resultado']['item']['id'], $id]]);

        $item = $this->em()->find(PastaChecklistItem::class, $id);
        $item = $this->recarregar($item);
        self::assertTrue($item->isConcluido());
        self::assertTrue($segunda->dados['resultado']['item']['concluido']);
        self::assertSame(2, $item->getOrdem());
    }

    #[TestDox('checklist_estado: desligar pede um dos motivos da tela; ligar não pede')]
    public function testChecklistEstado(): void
    {
        $pasta = $this->pasta();
        $base = ['acao' => 'checklist_estado', 'pasta_id' => $pasta->getId()];

        $this->assertMotivo($base + ['ativo' => false], ErroDaFerramenta::MOTIVO_ENTRADA_INVALIDA);
        $this->assertMotivo($base + ['ativo' => false, 'motivo' => 'porque sim'], ErroDaFerramenta::MOTIVO_ENTRADA_INVALIDA);

        $desligou = $this->executar($base + ['ativo' => false, 'motivo' => 'encerrada']);
        self::assertSame(['ativo' => false, 'mudou' => true], $desligou->dados['resultado']);
        $pasta = $this->recarregar($pasta);
        self::assertFalse($pasta->isChecklistAtivo());

        $ligou = $this->executar($base + ['ativo' => true]);
        self::assertSame(['ativo' => true, 'mudou' => true], $ligou->dados['resultado']);
    }

    #[TestDox('checklist_modelo: salvar como modelo (nome repetido é recusado, não substitui) e aplicar numa pasta')]
    public function testChecklistModeloSalvarEAplicar(): void
    {
        $pasta = $this->pasta();
        $this->item($pasta, 'Procuração', 1);
        $this->item($pasta, 'RG', 2);
        $base = ['acao' => 'checklist_modelo', 'pasta_id' => $pasta->getId()];

        $salvo = $this->executar($base + ['operacao' => 'salvar', 'nome' => 'Inicial']);
        $this->assertMotivo($base + ['operacao' => 'salvar', 'nome' => 'Inicial'], 'ja_existe');

        $outra = $this->pasta();
        $aplicou = $this->executar(['acao' => 'checklist_modelo', 'operacao' => 'aplicar', 'pasta_id' => $outra->getId(), 'modelo_checklist_id' => $salvo->dados['resultado']['modelo_de_checklist']['id']]);

        self::assertSame(2, $salvo->dados['resultado']['modelo_de_checklist']['itens']);
        self::assertSame(2, $aplicou->dados['resultado']['criados']);
        self::assertSame(2, $this->contar('pasta_checklist_item', $outra));
    }

    // ── Por proposta: nada grava antes da confirmação ────────────────────────

    #[TestDox('editar_documento.editar: a proposta não grava; a confirmação troca só o que foi mandado e mantém o resto')]
    public function testEditarDocumentoPorProposta(): void
    {
        $pasta = $this->pasta();
        $doc = $this->documento($pasta, 'antigo.pdf');
        $doc->setDescricao('descrição que fica');
        $this->em()->flush();

        $proposta = $this->propor(['acao' => 'editar_documento', 'operacao' => 'editar', 'pasta_id' => $pasta->getId(), 'documento_id' => $doc->getId(), 'nome_base' => 'novo nome', 'numero' => '123']);

        $doc = $this->recarregar($doc);
        self::assertSame('antigo.pdf', $doc->getNomeOriginal());
        self::assertStringContainsString('antigo.pdf', $proposta['resumo']);
        self::assertStringContainsString('"novo nome"', $proposta['resumo']);

        $resposta = $this->confirmar($proposta);

        $doc = $this->recarregar($doc);
        self::assertSame('confirmada', $resposta->dados['estado']);
        self::assertSame('novo nome.pdf', $doc->getNomeOriginal());
        self::assertSame('123', $doc->getNumero());
        self::assertSame('descrição que fica', $doc->getDescricao());
        self::assertSame(PastaDocumento::CATEGORIA_DEMAIS, $doc->getCategoria());
    }

    #[TestDox('editar_documento.excluir: vai para a lixeira só na confirmação, e dá para restaurar')]
    public function testExcluirDocumentosPorProposta(): void
    {
        $pasta = $this->pasta();
        [$a, $b] = [$this->documento($pasta, 'a.pdf'), $this->documento($pasta, 'b.pdf')];

        $proposta = $this->propor(['acao' => 'editar_documento', 'operacao' => 'excluir', 'pasta_id' => $pasta->getId(), 'documentos' => [$a->getId(), $b->getId()]]);

        self::assertNull($this->naLixeira(PastaDocumento::class, (int) $a->getId()));
        self::assertStringContainsString('2 documento(s)', $proposta['resumo']);
        self::assertStringContainsString('"a.pdf", "b.pdf"', $proposta['resumo']);

        $resposta = $this->confirmar($proposta);

        self::assertSame(2, $resposta->dados['resultado']['para_a_lixeira']['documentos']);
        self::assertNotNull($this->naLixeira(PastaDocumento::class, (int) $a->getId()));
        self::assertNotNull($this->naLixeira(PastaDocumento::class, (int) $b->getId()));
    }

    #[TestDox('subpasta.renomear e subpasta.excluir: por proposta; excluir manda a subpasta e o que há nela para a lixeira')]
    public function testSubpastaPorProposta(): void
    {
        $pasta = $this->pasta();
        $secao = $this->secao($pasta, 'Antiga');
        $dentro = $this->documento($pasta, 'dentro.pdf', secao: $secao);

        $renomear = $this->propor(['acao' => 'subpasta', 'operacao' => 'renomear', 'pasta_id' => $pasta->getId(), 'subpasta_id' => $secao->getId(), 'nome' => 'Nova']);
        $secao = $this->recarregar($secao);
        self::assertSame('ANTIGA', $secao->getNome());
        $this->confirmar($renomear);
        $secao = $this->recarregar($secao);
        self::assertSame('NOVA', $secao->getNome());

        $excluir = $this->propor(['acao' => 'subpasta', 'operacao' => 'excluir', 'pasta_id' => $pasta->getId(), 'subpasta_id' => $secao->getId()]);
        self::assertNull($this->naLixeira(PastaSecao::class, (int) $secao->getId()));
        $this->confirmar($excluir);
        self::assertNotNull($this->naLixeira(PastaSecao::class, (int) $secao->getId()));
        self::assertNotNull($this->naLixeira(PastaDocumento::class, (int) $dentro->getId()));
    }

    #[TestDox('excluir de vez da lixeira não existe nesta ferramenta (PR 14): o pedido é recusado e nada sai da lixeira')]
    public function testExcluirDeVezEhRecusado(): void
    {
        $pasta = $this->pasta();
        $doc = $this->documento($pasta, 'a.pdf');
        $doc->marcarExcluido($this->pessoa, new \DateTimeImmutable());
        $this->em()->flush();
        $id = (int) $doc->getId();

        $this->assertMotivo(['acao' => 'restaurar', 'operacao' => 'excluir_de_vez', 'pasta_id' => $pasta->getId(), 'documentos' => [$id]], ErroDaFerramenta::MOTIVO_ENTRADA_INVALIDA);
        $this->assertMotivo(['acao' => 'restaurar', 'pasta_id' => $pasta->getId(), 'documentos' => [$id], 'excluir_de_vez' => true], ErroDaFerramenta::MOTIVO_ENTRADA_INVALIDA);

        self::assertSame(1, $this->linhasDoDocumento($id));
        self::assertNotNull($this->naLixeira(PastaDocumento::class, $id));
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM conector_ia_proposta'));
    }

    #[TestDox('editar_peca: regrava o HTML na mesma chave só na confirmação; documento que não é peça de texto é recusado')]
    public function testEditarPecaPorProposta(): void
    {
        $pasta = $this->pasta();
        $peca = $this->documento($pasta, 'peca.html', '<p>texto antigo</p>', mime: 'text/html');
        $pdf = $this->documento($pasta, 'a.pdf');

        $this->assertMotivo(['acao' => 'editar_peca', 'pasta_id' => $pasta->getId(), 'documento_id' => $pdf->getId(), 'conteudo' => 'x'], 'peca_nao_editavel');
        $proposta = $this->propor(['acao' => 'editar_peca', 'pasta_id' => $pasta->getId(), 'documento_id' => $peca->getId(), 'conteudo' => "Texto novo\n\nSegundo", 'titulo' => 'Peça revista']);

        self::assertStringContainsString('<p>texto antigo</p>', $this->disco->memoria->ler(ChavesDePasta::documento($peca)));
        self::assertStringContainsString('não guarda versão anterior', $proposta['resumo']);

        $this->confirmar($proposta);

        $peca = $this->recarregar($peca);
        $html = $this->disco->memoria->ler(ChavesDePasta::documento($peca));
        self::assertStringContainsString('<p>Texto novo</p>', $html);
        self::assertStringNotContainsString('texto antigo', $html);
        self::assertSame('PEÇA REVISTA', $peca->getTitulo());
    }

    #[TestDox('salvar_modelo com corpo em texto simples: dois parágrafos viram dois <p>, escapados')]
    public function testSalvarModeloComTextoSimples(): void
    {
        $pasta = $this->pasta();

        $resposta = $this->executar(['acao' => 'salvar_modelo', 'operacao' => 'salvar', 'pasta_id' => $pasta->getId(), 'titulo' => 'Texto puro', 'materia' => 'civel', 'tipo' => 'replica', 'corpo' => "Primeiro & parágrafo\n\nSegundo parágrafo"]);

        $modelo = $this->em()->find(ModeloDePeca::class, $resposta->dados['resultado']['modelo']['id']);
        self::assertStringContainsString('<p>Primeiro &amp; parágrafo</p>', $modelo?->getCorpo());
        self::assertStringContainsString('<p>Segundo parágrafo</p>', $modelo->getCorpo());
    }

    #[TestDox('salvar_modelo com modelo_id e excluir: por proposta; sem modelo_id é direto')]
    public function testModeloDePecaPorProposta(): void
    {
        $pasta = $this->pasta();
        $modelo = $this->modelo('Antigo', '<p>corpo velho</p>');

        $editar = $this->propor(['acao' => 'salvar_modelo', 'operacao' => 'salvar', 'pasta_id' => $pasta->getId(), 'modelo_id' => $modelo->getId(), 'titulo' => 'Novo título', 'materia' => 'familia', 'tipo' => 'acordo', 'corpo' => '<p>corpo novo</p>']);
        $modelo = $this->recarregar($modelo);
        self::assertSame('Antigo', $modelo->getTitulo());
        $this->confirmar($editar);
        $modelo = $this->recarregar($modelo);
        self::assertSame('Novo título', $modelo->getTitulo());
        self::assertSame(MateriaDeModelo::Familia, $modelo->getMateria());

        $excluir = $this->propor(['acao' => 'salvar_modelo', 'operacao' => 'excluir', 'pasta_id' => $pasta->getId(), 'modelo_id' => $modelo->getId()]);
        $id = (int) $modelo->getId();
        self::assertNotNull($this->em()->find(ModeloDePeca::class, $id));
        $this->confirmar($excluir);
        self::assertNull($this->em()->find(ModeloDePeca::class, $id));
    }

    #[TestDox('checklist_item editar o texto e excluir: por proposta; só na confirmação mudam')]
    public function testChecklistItemPorProposta(): void
    {
        $pasta = $this->pasta();
        $item = $this->item($pasta, 'Texto velho', 1);
        $base = ['acao' => 'checklist_item', 'pasta_id' => $pasta->getId(), 'item_id' => $item->getId()];

        $editar = $this->propor($base + ['operacao' => 'editar', 'titulo' => 'Texto novo']);
        $item = $this->recarregar($item);
        self::assertSame('TEXTO VELHO', $item->getTitulo());
        $this->confirmar($editar);
        $item = $this->recarregar($item);
        self::assertSame('TEXTO NOVO', $item->getTitulo());

        $excluir = $this->propor($base + ['operacao' => 'excluir']);
        self::assertSame(1, $this->contar('pasta_checklist_item', $pasta));
        $this->confirmar($excluir);
        self::assertSame(0, $this->contar('pasta_checklist_item', $pasta));
    }

    #[TestDox('checklist_modelo renomear e excluir: por proposta; só na confirmação mudam')]
    public function testChecklistModeloPorProposta(): void
    {
        $pasta = $this->pasta();
        $modelo = $this->modeloDeChecklist('Primeiro');
        $base = ['acao' => 'checklist_modelo', 'pasta_id' => $pasta->getId(), 'modelo_checklist_id' => $modelo->getId()];

        $renomear = $this->propor($base + ['operacao' => 'renomear', 'nome' => 'Segundo']);
        $modelo = $this->recarregar($modelo);
        self::assertSame('PRIMEIRO', $modelo->getNome());
        $this->confirmar($renomear);
        $modelo = $this->recarregar($modelo);
        self::assertSame('SEGUNDO', $modelo->getNome());

        $excluir = $this->propor($base + ['operacao' => 'excluir']);
        $id = (int) $modelo->getId();
        self::assertNotNull($this->em()->find(PastaChecklistModelo::class, $id));
        $this->confirmar($excluir);
        self::assertNull($this->em()->find(PastaChecklistModelo::class, $id));
    }

    // ── Guardas ──────────────────────────────────────────────────────────────

    #[TestDox('documento de outra pasta (do mesmo escritório) é "não encontrado", direto ou por proposta, e nada muda')]
    public function testDocumentoDeOutraPasta(): void
    {
        $pasta = $this->pasta();
        $alheio = $this->documento($this->pasta(), 'alheio.pdf');

        $this->assertMotivo(['acao' => 'mover', 'pasta_id' => $pasta->getId(), 'documentos' => [$alheio->getId()]], ErroDaFerramenta::MOTIVO_NAO_ENCONTRADO);
        $this->assertMotivo(['acao' => 'editar_documento', 'operacao' => 'editar', 'pasta_id' => $pasta->getId(), 'documento_id' => $alheio->getId(), 'numero' => '9'], ErroDaFerramenta::MOTIVO_NAO_ENCONTRADO);
        $this->assertMotivo(['acao' => 'editar_documento', 'operacao' => 'excluir', 'pasta_id' => $pasta->getId(), 'documentos' => [$alheio->getId()]], ErroDaFerramenta::MOTIVO_NAO_ENCONTRADO);
        $this->assertMotivo(['acao' => 'copiar', 'pasta_id' => $pasta->getId(), 'documentos' => [$alheio->getId()]], ErroDaFerramenta::MOTIVO_NAO_ENCONTRADO);

        $alheio = $this->recarregar($alheio);
        self::assertNull($alheio->getNumero());
        self::assertNull($this->naLixeira(PastaDocumento::class, (int) $alheio->getId()));
    }

    #[TestDox('subpasta, item de checklist e modelo de checklist de outra pasta ou de outro escritório: "não encontrado"')]
    public function testItensDeOutraPastaOuEscritorio(): void
    {
        $pasta = $this->pasta();
        $outroEscritorio = $this->escritorio('Outro');
        $outraPasta = $this->pasta($outroEscritorio);
        $secaoAlheia = $this->secao($this->pasta(), 'Alheia');
        $itemAlheio = $this->item($this->pasta(), 'alheio', 1);
        $modeloAlheio = $this->modeloDeChecklist('Alheio', $outroEscritorio);
        $modeloDePecaAlheio = $this->modelo('Alheio', '<p>x</p>', $outroEscritorio);
        $id = $pasta->getId();

        foreach ([
            ['acao' => 'subpasta', 'operacao' => 'excluir', 'pasta_id' => $id, 'subpasta_id' => $secaoAlheia->getId()],
            ['acao' => 'mover', 'pasta_id' => $id, 'subpastas' => [$secaoAlheia->getId()]],
            ['acao' => 'checklist_item', 'operacao' => 'marcar', 'pasta_id' => $id, 'item_id' => $itemAlheio->getId(), 'concluido' => true],
            ['acao' => 'checklist_item', 'operacao' => 'editar', 'pasta_id' => $id, 'item_id' => $itemAlheio->getId(), 'titulo' => 'x'],
            ['acao' => 'checklist_modelo', 'operacao' => 'aplicar', 'pasta_id' => $id, 'modelo_checklist_id' => $modeloAlheio->getId()],
            ['acao' => 'checklist_modelo', 'operacao' => 'excluir', 'pasta_id' => $id, 'modelo_checklist_id' => $modeloAlheio->getId()],
            ['acao' => 'usar_modelo', 'pasta_id' => $id, 'modelo_id' => $modeloDePecaAlheio->getId()],
            ['acao' => 'salvar_modelo', 'operacao' => 'excluir', 'pasta_id' => $id, 'modelo_id' => $modeloDePecaAlheio->getId()],
            ['acao' => 'subpasta', 'operacao' => 'criar', 'pasta_id' => $outraPasta->getId(), 'nome' => 'Invasora'],
        ] as $argumentos) {
            $this->assertMotivo($argumentos, ErroDaFerramenta::MOTIVO_NAO_ENCONTRADO);
        }
        self::assertSame(0, $this->contar('pasta_secao', $outraPasta));
    }

    #[TestDox('quem só vê a pasta não grava nada (nem a proposta nasce) e quem não tem mcp:escrita é recusado')]
    public function testSemEditarESemEscopo(): void
    {
        $pasta = $this->pasta();
        $doc = $this->documento($pasta, 'a.pdf');
        $leitor = $this->pessoa();
        $this->vinculo($leitor, $this->escritorio, permissoes: self::LEITOR);
        $this->doConector->ligar($leitor, new \DateTimeImmutable());
        $this->em()->flush();

        foreach ([
            ['acao' => 'subpasta', 'operacao' => 'criar', 'pasta_id' => $pasta->getId(), 'nome' => 'X'],
            ['acao' => 'editar_documento', 'operacao' => 'excluir', 'pasta_id' => $pasta->getId(), 'documentos' => [$doc->getId()]],
            ['acao' => 'checklist_item', 'operacao' => 'adicionar', 'pasta_id' => $pasta->getId(), 'titulo' => 'x'],
        ] as $argumentos) {
            $this->assertMotivo($argumentos, ErroDaFerramenta::MOTIVO_NAO_ENCONTRADO, $this->chamada($leitor));
        }
        $this->assertMotivo(['acao' => 'subpasta', 'operacao' => 'criar', 'pasta_id' => $pasta->getId(), 'nome' => 'X'], ErroDaFerramenta::MOTIVO_SEM_PERMISSAO, $this->chamada(escopos: ['mcp:leitura']));

        self::assertSame(0, $this->contar('pasta_secao', $pasta));
        self::assertSame(0, $this->contar('pasta_checklist_item', $pasta));
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM conector_ia_proposta'));
    }

    #[TestDox('pasta em segredo de justiça, na lixeira ou com a aba Documentos oculta: recusa sem gravar')]
    public function testSegredoLixeiraEAbaOculta(): void
    {
        $emSegredo = $this->pasta();
        $this->vincularProcesso($emSegredo, sigilo: 1);
        $naLixeira = $this->pasta();
        $naLixeira->marcarExcluida($this->pessoa, new \DateTimeImmutable());
        $oculta = $this->pasta();
        $this->em()->persist(AbaOcultaDaPasta::paraTodaAEquipe($this->escritorio, $oculta, AbaDaPasta::Documentos, $this->pessoa(), new \DateTimeImmutable()));
        $this->em()->flush();

        $this->assertMotivo(['acao' => 'subpasta', 'operacao' => 'criar', 'pasta_id' => $emSegredo->getId(), 'nome' => 'X'], ErroDaFerramenta::MOTIVO_EM_SEGREDO);
        $this->assertMotivo(['acao' => 'checklist_item', 'operacao' => 'adicionar', 'pasta_id' => $naLixeira->getId(), 'titulo' => 'x'], ErroDaFerramenta::MOTIVO_NAO_ENCONTRADO);
        $this->assertMotivo(['acao' => 'checklist_item', 'operacao' => 'adicionar', 'pasta_id' => $oculta->getId(), 'titulo' => 'x'], ErroDaFerramenta::MOTIVO_NAO_ENCONTRADO);
        self::assertSame(0, $this->contar('pasta_secao', $emSegredo));
        self::assertSame(0, $this->contar('pasta_checklist_item', $naLixeira));
    }

    #[TestDox('contrato de honorários com o Financeiro oculto responde como documento que não existe')]
    public function testContratoComFinanceiroOculto(): void
    {
        $pasta = $this->pasta();
        $contrato = $this->documento($pasta, 'contrato.pdf', categoria: PastaDocumento::CATEGORIA_CONTRATO);
        $this->em()->persist(AbaOcultaDaPasta::paraTodaAEquipe($this->escritorio, $pasta, AbaDaPasta::Financeiro, $this->pessoa(), new \DateTimeImmutable()));
        $this->em()->flush();

        $this->assertMotivo(['acao' => 'editar_documento', 'operacao' => 'excluir', 'pasta_id' => $pasta->getId(), 'documentos' => [$contrato->getId()]], ErroDaFerramenta::MOTIVO_NAO_ENCONTRADO);
        $this->assertMotivo(['acao' => 'mover', 'pasta_id' => $pasta->getId(), 'documentos' => [$contrato->getId()]], ErroDaFerramenta::MOTIVO_NAO_ENCONTRADO);
    }

    #[TestDox('6.2, regra 10: campo que a operação não usa, operação ausente quando há várias, lote acima de 50 e id repetido ou inválido são recusados')]
    public function testEntradaFechada(): void
    {
        $pasta = $this->pasta();
        $id = $pasta->getId();
        $muitos = range(1, 51);

        foreach ([
            ['acao' => 'subpasta', 'pasta_id' => $id, 'nome' => 'X'],
            ['acao' => 'subpasta', 'operacao' => 'criar', 'pasta_id' => $id, 'nome' => 'X', 'documento_id' => 1],
            ['acao' => 'subpasta', 'operacao' => 'criar', 'pasta_id' => $id],
            ['acao' => 'subpasta', 'operacao' => 'criar', 'pasta_id' => $id, 'nome' => '   '],
            ['acao' => 'subpasta', 'operacao' => 'apagar', 'pasta_id' => $id, 'nome' => 'X'],
            ['acao' => 'apagar_tudo', 'pasta_id' => $id],
            ['acao' => 'mover', 'pasta_id' => $id],
            ['acao' => 'mover', 'pasta_id' => $id, 'documentos' => $muitos],
            ['acao' => 'mover', 'pasta_id' => $id, 'documentos' => ['abc']],
            ['acao' => 'mover', 'pasta_id' => $id, 'documentos' => []],
            ['acao' => 'editar_documento', 'operacao' => 'editar', 'pasta_id' => $id, 'documento_id' => 1],
            ['acao' => 'favoritar', 'pasta_id' => $id, 'item' => 'documento', 'alvo_id' => 1, 'marcado' => 'sim'],
            ['acao' => 'checklist_estado', 'pasta_id' => 0, 'ativo' => true],
        ] as $argumentos) {
            $this->assertMotivo($argumentos, ErroDaFerramenta::MOTIVO_ENTRADA_INVALIDA);
        }
        self::assertSame(0, $this->contar('pasta_secao', $pasta));
    }

    #[TestDox('texto grande demais para a proposta é recusa da entrada, não erro do servidor')]
    public function testTextoGrandeDemais(): void
    {
        $pasta = $this->pasta();
        $peca = $this->documento($pasta, 'peca.html', '<p>x</p>', mime: 'text/html');

        $this->assertMotivo(['acao' => 'editar_peca', 'pasta_id' => $pasta->getId(), 'documento_id' => $peca->getId(), 'conteudo' => str_repeat('a', 250_000)], DocumentosEPecasFerramenta::MOTIVO_TEXTO_GRANDE);
    }

    // ── A confirmação reconfere tudo ─────────────────────────────────────────

    #[TestDox('reconferir: se o documento deixou de ser da pasta entre a proposta e o sim, a confirmação recusa, não grava e a proposta continua valendo')]
    public function testReconferirDocumentoQueSaiuDaPasta(): void
    {
        $pasta = $this->pasta();
        $outra = $this->pasta();
        $doc = $this->documento($pasta, 'a.pdf');
        $proposta = $this->propor(['acao' => 'editar_documento', 'operacao' => 'excluir', 'pasta_id' => $pasta->getId(), 'documentos' => [$doc->getId()]]);
        $doc = $this->recarregar($doc);
        $doc->setPasta($this->recarregar($outra));
        $this->em()->flush();

        $this->assertConfirmacaoRecusada($proposta, ErroDaFerramenta::MOTIVO_NAO_ENCONTRADO);
        self::assertNull($this->naLixeira(PastaDocumento::class, (int) $doc->getId()));

        $doc = $this->recarregar($doc);
        $doc->setPasta($this->recarregar($pasta));
        $this->em()->flush();
        $this->confirmar($proposta);
        self::assertNotNull($this->naLixeira(PastaDocumento::class, (int) $doc->getId()));
    }

    #[TestDox('reconferir: pasta que foi para a lixeira ou entrou em segredo de justiça entre a proposta e o sim não grava')]
    public function testReconferirPastaEmLixeiraOuSegredo(): void
    {
        $pasta = $this->pasta();
        $item = $this->item($pasta, 'x', 1);
        $proposta = $this->propor(['acao' => 'checklist_item', 'operacao' => 'excluir', 'pasta_id' => $pasta->getId(), 'item_id' => $item->getId()]);

        $this->vincularProcesso($this->recarregar($pasta), sigilo: 1);
        $this->assertConfirmacaoRecusada($proposta, ErroDaFerramenta::MOTIVO_EM_SEGREDO);

        $outra = $this->pasta();
        $outroItem = $this->item($outra, 'y', 1);
        $outraProposta = $this->propor(['acao' => 'checklist_item', 'operacao' => 'excluir', 'pasta_id' => $outra->getId(), 'item_id' => $outroItem->getId()]);
        $this->recarregar($outra)->marcarExcluida($this->recarregar($this->pessoa), new \DateTimeImmutable());
        $this->em()->flush();
        $this->assertConfirmacaoRecusada($outraProposta, ErroDaFerramenta::MOTIVO_NAO_ENCONTRADO);

        self::assertSame(1, $this->contar('pasta_checklist_item', $pasta));
        self::assertSame(1, $this->contar('pasta_checklist_item', $outra));
    }

    #[TestDox('reconferir: item de checklist que foi excluído entre a proposta e o sim não é executado de novo')]
    public function testReconferirItemQueSumiu(): void
    {
        $pasta = $this->pasta();
        $item = $this->item($pasta, 'x', 1);
        $proposta = $this->propor(['acao' => 'checklist_item', 'operacao' => 'editar', 'pasta_id' => $pasta->getId(), 'item_id' => $item->getId(), 'titulo' => 'novo']);
        $this->em()->remove($this->recarregar($item));
        $this->em()->flush();

        $this->assertConfirmacaoRecusada($proposta, ErroDaFerramenta::MOTIVO_NAO_ENCONTRADO);
    }

    // ── Apoio ────────────────────────────────────────────────────────────────

    /** @param array<string, mixed> $argumentos */
    private function executar(array $argumentos, ?ChamadaDoConector $chamada = null): RespostaDaFerramenta
    {
        try {
            return static::getContainer()->get(DocumentosEPecasFerramenta::class)->executar($argumentos, $chamada ?? $this->chamada());
        } finally {
            $this->reanexar();
        }
    }

    /**
     * @param array<string, mixed> $argumentos
     *
     * @return array{proposta_id: string, resumo: string, expira_em: string}
     */
    private function propor(array $argumentos): array
    {
        $resposta = $this->executar($argumentos);

        self::assertSame('aguardando_confirmacao', $resposta->dados['estado']);
        self::assertSame('confirmar_acao', $resposta->dados['como_confirmar']);

        return $resposta->dados['proposta'];
    }

    /** @param array{proposta_id: string, resumo: string} $proposta */
    private function confirmar(array $proposta): RespostaDaFerramenta
    {
        try {
            return static::getContainer()->get(ConfirmarAcaoFerramenta::class)->executar(['proposta_id' => $proposta['proposta_id'], 'resumo' => $proposta['resumo']], $this->chamada());
        } finally {
            $this->reanexar();
        }
    }

    /** @param array{proposta_id: string, resumo: string} $proposta */
    private function assertConfirmacaoRecusada(array $proposta, string $motivo): void
    {
        try {
            $this->confirmar($proposta);
        } catch (ErroDaFerramenta $e) {
            self::assertSame($motivo, $e->motivo);

            return;
        }
        self::fail('a confirmação deveria recusar com ' . $motivo);
    }

    /** @param array<string, mixed> $argumentos */
    private function assertMotivo(array $argumentos, string $motivo, ?ChamadaDoConector $chamada = null): void
    {
        try {
            $this->executar($argumentos, $chamada);
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

    /**
     * Cada chamada reinicia os serviços (o portão só abre uma vez por requisição), o que solta as entidades do
     * EntityManager: o teste relê pelo id.
     *
     * @template T of object
     *
     * @param T $entidade
     *
     * @return T
     */
    private function recarregar(object $entidade): object
    {
        return $this->em()->find($entidade::class, $entidade->getId()) ?? self::fail('entidade sumiu');
    }

    /** Relê do banco o cenário do setUp: a chamada e a confirmação soltam as entidades do EntityManager. */
    private function reanexar(): void
    {
        $this->escritorio = $this->recarregar($this->escritorio);
        $this->pessoa = $this->recarregar($this->pessoa);
        $this->doConector = $this->recarregar($this->doConector);
        $this->consentimento = $this->recarregar($this->consentimento);
    }

    private function pasta(?Tenant $escritorio = null): Pasta
    {
        $pasta = (new Pasta())->setNup('DOC-' . uniqid());
        $pasta->setTenant($escritorio ?? $this->escritorio);
        $this->em()->persist($pasta);
        $this->em()->flush();

        return $pasta;
    }

    private function vincularProcesso(Pasta $pasta, int $sigilo = 0): PastaProcesso
    {
        $processo = new Processo();
        $processo->setNumeroProcesso('07011345720258070001' . uniqid());
        $processo->setSiglaTribunal('TJDFT');
        $processo->setClasseProcessual('Procedimento Comum');
        $processo->setAssuntoProcessual('Assunto');
        $processo->setTenant($this->escritorio);
        $processo->setNivelSigilo($sigilo);
        $this->em()->persist($processo);
        $vinculo = new PastaProcesso($pasta, $processo);
        $this->em()->persist($vinculo);
        $pasta->getPastaProcessos()->add($vinculo);
        $this->em()->flush();

        return $vinculo;
    }

    private function secao(Pasta $pasta, string $nome): PastaSecao
    {
        $secao = (new PastaSecao())->setPasta($pasta)->setTenant($this->escritorio)->setNome($nome);
        $this->em()->persist($secao);
        $this->em()->flush();

        return $secao;
    }

    private function documento(Pasta $pasta, string $nome, string $bytes = self::PDF, ?PastaSecao $secao = null, string $mime = 'application/pdf', string $categoria = PastaDocumento::CATEGORIA_DEMAIS): PastaDocumento
    {
        $doc = new PastaDocumento();
        $doc->setTenant($this->escritorio);
        $doc->setPasta($pasta);
        $doc->setSecao($secao);
        $doc->setTitulo(mb_substr($nome, 0, 200));
        $doc->setCategoria($categoria);
        $doc->setCaminhoArquivo(uniqid('doc', true) . '.bin');
        $doc->setNomeOriginal($nome);
        $doc->setMimeType($mime);
        $doc->setTamanhoBytes(\strlen($bytes));
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

    private function item(Pasta $pasta, string $titulo, int $ordem): PastaChecklistItem
    {
        $item = (new PastaChecklistItem())->setPasta($pasta)->setTenant($this->escritorio)->setTitulo($titulo)->setOrdem($ordem);
        $this->em()->persist($item);
        $this->em()->flush();

        return $item;
    }

    private function modeloDeChecklist(string $nome, ?Tenant $escritorio = null): PastaChecklistModelo
    {
        $escritorio ??= $this->escritorio;
        $modelo = (new PastaChecklistModelo())->setTenant($escritorio)->setAutor($this->pessoa)->setNome($nome);
        $linha = (new PastaChecklistModeloItem())->setTenant($escritorio)->setTitulo('Item do modelo')->setOrdem(1);
        $modelo->adicionarItem($linha);
        $this->em()->persist($modelo);
        $this->em()->flush();

        return $modelo;
    }

    /** O `excluido_em` de uma linha direto do banco (o filtro da lixeira não a esconde); null se está viva. */
    private function naLixeira(string $entidade, int $id): ?string
    {
        $tabela = $entidade === PastaSecao::class ? 'pasta_secao' : 'pasta_documento';
        $valor = $this->em()->getConnection()->fetchOne('SELECT excluido_em FROM ' . $tabela . ' WHERE id = ?', [$id]);

        return \is_string($valor) ? $valor : null;
    }

    private function linhasDoDocumento(int $id): int
    {
        return (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM pasta_documento WHERE id = ?', [$id]);
    }

    private function contar(string $tabela, Pasta $pasta): int
    {
        return (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM ' . $tabela . ' WHERE pasta_id = ?', [$pasta->getId()]);
    }
}
