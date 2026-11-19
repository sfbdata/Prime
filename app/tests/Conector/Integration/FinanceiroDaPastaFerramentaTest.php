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
use App\Conector\Ferramenta\FinanceiroDaPastaFerramenta;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\AbaDaPasta;
use App\Pasta\Entity\AbaOcultaDaPasta;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaAndamento;
use App\Pasta\Entity\PastaDocumento;
use App\Pasta\Entity\PastaPagamento;
use App\Pasta\Entity\PastaProcesso;
use App\Processo\Entity\Processo;
use App\Tests\Conector\Support\CenarioDoConectorTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * `financeiro_da_pasta` com o banco e o portão de verdade (spec mcp-remoto-oauth 6.6, PR 12): as ações diretas gravam
 * pelos UseCases da tela; gravar por cima, corrigir valor, excluir pagamento e excluir documento só gravam depois do
 * `confirmar_acao`; sem o Financeiro, sem editar, pasta de outro escritório, na lixeira ou em segredo: recusa.
 */
#[CoversClass(FinanceiroDaPastaFerramenta::class)]
final class FinanceiroDaPastaFerramentaTest extends KernelTestCase
{
    use CenarioDoConectorTrait;

    private const EDITOR = ['modules.pastas.view', 'resources.pasta.view', 'resources.pasta.edit'];
    private const LEITOR = ['modules.pastas.view', 'resources.pasta.view'];

    private Tenant $escritorio;
    private User $pessoa;
    private ConsentimentoDoConector $consentimento;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->escritorio = $this->escritorio();
        $this->pessoa = $this->pessoa();
        $this->vinculo($this->pessoa, $this->escritorio, permissoes: self::EDITOR);
        $doConector = new EscritorioDoConector($this->escritorio);
        $doConector->liberarPeloMaster();
        $doConector->ligar($this->pessoa, new \DateTimeImmutable());
        $this->em()->persist($doConector);
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

    #[TestDox('contrato: regular e pendente gravam direto, dizem se mudou e o regular leva o andamento até o Contrato')]
    public function testContrato(): void
    {
        $pasta = $this->pasta();

        $regular = $this->executar(['acao' => 'contrato', 'pasta_id' => $pasta->getId(), 'situacao' => 'regular']);

        self::assertSame('gravado', $regular->dados['estado']);
        self::assertTrue($regular->dados['mudou']);
        self::assertSame('REGULAR', $this->recarregar(Pasta::class, (int) $pasta->getId())->getSituacaoContrato());
        $andamento = $this->em()->getRepository(PastaAndamento::class)->findOneBy(['pasta' => $pasta->getId()]);
        self::assertInstanceOf(PastaAndamento::class, $andamento, 'o UseCase da tela move o andamento');
        self::assertSame(['pasta' => [$pasta->getId()]], $regular->ids);

        self::assertFalse($this->executar(['acao' => 'contrato', 'pasta_id' => $pasta->getId(), 'situacao' => 'regular'])->dados['mudou']);
        $this->executar(['acao' => 'contrato', 'pasta_id' => $pasta->getId(), 'situacao' => 'pendente']);
        self::assertSame('PENDENTE', $this->recarregar(Pasta::class, (int) $pasta->getId())->getSituacaoContrato());
    }

    #[TestDox('pro_bono: marca e desmarca direto')]
    public function testProBono(): void
    {
        $pasta = $this->pasta();

        $ligado = $this->executar(['acao' => 'pro_bono', 'pasta_id' => $pasta->getId(), 'ligado' => true]);
        self::assertTrue($ligado->dados['pro_bono']);
        self::assertTrue($ligado->dados['mudou']);
        self::assertTrue($this->recarregar(Pasta::class, (int) $pasta->getId())->isProBono());

        $this->executar(['acao' => 'pro_bono', 'pasta_id' => $pasta->getId(), 'ligado' => false]);
        self::assertFalse($this->recarregar(Pasta::class, (int) $pasta->getId())->isProBono());
    }

    #[TestDox('valor_da_causa: vazio grava direto; já preenchido vira proposta e só grava depois do confirmar')]
    public function testValorDaCausa(): void
    {
        $pasta = $this->pasta();

        $direta = $this->executar(['acao' => 'valor_da_causa', 'pasta_id' => $pasta->getId(), 'valor' => '12.860,00']);
        self::assertSame('gravado', $direta->dados['estado']);
        self::assertSame('12860.00', $this->recarregar(Pasta::class, (int) $pasta->getId())->getValorCausa());

        $proposta = $this->executar(['acao' => 'valor_da_causa', 'pasta_id' => $pasta->getId(), 'valor' => '20.000,00']);
        self::assertSame('aguardando_confirmacao', $proposta->dados['estado']);
        self::assertStringContainsString('de R$ 12.860,00 para R$ 20.000,00', $proposta->dados['proposta']['resumo']);
        self::assertSame('12860.00', $this->recarregar(Pasta::class, (int) $pasta->getId())->getValorCausa(), 'a proposta não grava');

        $confirmada = $this->confirmar($proposta);
        self::assertSame('confirmada', $confirmada->dados['estado']);
        self::assertSame('20000.00', $this->recarregar(Pasta::class, (int) $pasta->getId())->getValorCausa());
    }

    #[TestDox('valor_da_causa: o valor mudou na pasta depois da proposta, a confirmação é recusada e nada grava')]
    public function testValorDaCausaQueMudou(): void
    {
        $pasta = $this->pasta();
        $this->executar(['acao' => 'valor_da_causa', 'pasta_id' => $pasta->getId(), 'valor' => '12.860,00']);
        $proposta = $this->executar(['acao' => 'valor_da_causa', 'pasta_id' => $pasta->getId(), 'valor' => '20.000,00']);

        $this->em()->getConnection()->executeStatement('UPDATE pasta SET valor_causa = 15000 WHERE id = ?', [$pasta->getId()]);

        try {
            $this->confirmar($proposta);
            self::fail('deveria recusar');
        } catch (ErroDaFerramenta $e) {
            self::assertSame(FinanceiroDaPastaFerramenta::MOTIVO_MUDOU, $e->motivo);
            self::assertSame(FinanceiroDaPastaFerramenta::MUDOU, $e->getMessage());
        }
        self::assertSame('15000.00', $this->recarregar(Pasta::class, (int) $pasta->getId())->getValorCausa());
    }

    #[TestDox('sem o escopo mcp:escrita a ferramenta recusa, mesmo na ação direta, e nada grava')]
    public function testSemEscopoDeEscrita(): void
    {
        $pasta = $this->pasta();
        $chamada = $this->chamada(['mcp:leitura']);

        try {
            static::getContainer()->get(FinanceiroDaPastaFerramenta::class)->executar(['acao' => 'pro_bono', 'pasta_id' => $pasta->getId(), 'ligado' => true], $chamada);
            self::fail('deveria recusar');
        } catch (ErroDaFerramenta $e) {
            self::assertSame(ErroDaFerramenta::MOTIVO_SEM_PERMISSAO, $e->motivo);
        }
        self::assertFalse($this->recarregar(Pasta::class, (int) $pasta->getId())->isProBono());
    }

    #[TestDox('campos de texto do esquema: número no lugar de texto e texto acima do maxLength são pedido inválido')]
    public function testTamanhoDosTextos(): void
    {
        $pasta = $this->pasta();

        $this->assertMotivo(['acao' => 'valor_da_causa', 'pasta_id' => $pasta->getId(), 'valor' => 1500], ErroDaFerramenta::MOTIVO_ENTRADA_INVALIDA);
        $this->assertMotivo(['acao' => 'valor_da_causa', 'pasta_id' => $pasta->getId(), 'valor' => str_repeat('1', 21)], ErroDaFerramenta::MOTIVO_ENTRADA_INVALIDA);
        $this->assertMotivo(['acao' => 'pagamento', 'operacao' => 'parcelar', 'pasta_id' => $pasta->getId(), 'tipo' => 'contrato', 'valor' => '10,00', 'parcelas' => '1000', 'vencimento' => '2026-12-10'], ErroDaFerramenta::MOTIVO_ENTRADA_INVALIDA);
    }

    #[TestDox('valor_da_causa: texto que não é dinheiro e valor em branco são recusados sem gravar')]
    public function testValorInvalido(): void
    {
        $pasta = $this->pasta();

        $this->assertMotivo(['acao' => 'valor_da_causa', 'pasta_id' => $pasta->getId(), 'valor' => 'abc'], ErroDaFerramenta::MOTIVO_ENTRADA_INVALIDA);
        $this->assertMotivo(['acao' => 'valor_da_causa', 'pasta_id' => $pasta->getId(), 'valor' => ' '], ErroDaFerramenta::MOTIVO_FILTRO_INVALIDO);
        self::assertNull($this->recarregar(Pasta::class, (int) $pasta->getId())->getValorCausa());
    }

    #[TestDox('pagamento: registrar grava direto, com o escritório e a pessoa do token')]
    public function testRegistrar(): void
    {
        $pasta = $this->pasta();

        $resposta = $this->executar(['acao' => 'pagamento', 'operacao' => 'registrar', 'pasta_id' => $pasta->getId(), 'descricao' => 'Entrada', 'valor' => '1.500,00', 'vencimento' => '2026-12-10']);

        $pagamento = $this->recarregar(PastaPagamento::class, (int) $resposta->dados['pagamento_id']);
        self::assertSame('Entrada', $pagamento->getDescricao());
        self::assertSame('1500.00', $pagamento->getValor());
        self::assertSame('2026-12-10', $pagamento->getVencimento()?->format('Y-m-d'));
        self::assertSame($this->escritorio->getId(), $pagamento->getTenant()?->getId());
        self::assertSame($this->pessoa->getId(), $pagamento->getAutor()?->getId());
        self::assertSame(['pasta' => [$pasta->getId()], 'pagamento' => [$pagamento->getId()]], $resposta->ids);
    }

    #[TestDox('pagamento: registrar com valor zero ou data inexistente é recusado pela regra do UseCase, sem gravar')]
    public function testRegistrarInvalido(): void
    {
        $pasta = $this->pasta();
        $base = ['acao' => 'pagamento', 'operacao' => 'registrar', 'pasta_id' => $pasta->getId(), 'descricao' => 'Entrada', 'vencimento' => '2026-12-10'];

        $this->assertMotivo($base + ['valor' => '0,00'], ErroDaFerramenta::MOTIVO_ENTRADA_INVALIDA);
        $this->assertMotivo(['vencimento' => '2026-02-31', 'valor' => '10,00'] + $base, ErroDaFerramenta::MOTIVO_ENTRADA_INVALIDA);
        self::assertSame([], $this->em()->getRepository(PastaPagamento::class)->findBy(['pasta' => $pasta->getId()]));
    }

    #[TestDox('pagamento: parcelar grava a entrada e as parcelas, todas pendentes')]
    public function testParcelar(): void
    {
        $pasta = $this->pasta();

        $resposta = $this->executar([
            'acao' => 'pagamento', 'operacao' => 'parcelar', 'pasta_id' => $pasta->getId(),
            'tipo' => 'contrato', 'base' => 'valor', 'valor' => '3.000,00', 'parcelas' => '3', 'vencimento' => '2026-12-10',
        ]);

        self::assertSame(3, $resposta->dados['quantidade']);
        self::assertSame('R$ 3.000,00', $resposta->dados['total_lancado']);
        self::assertCount(3, $resposta->dados['pagamento_ids']);
        $gravados = $this->em()->getRepository(PastaPagamento::class)->findBy(['pasta' => $pasta->getId()]);
        self::assertCount(3, $gravados);
        foreach ($gravados as $gravado) {
            self::assertFalse($gravado->estaPago());
        }
    }

    #[TestDox('pagamento: marcar recebido e desfazer alternam a quitação; repetir o mesmo gesto é recusado')]
    public function testQuitacao(): void
    {
        $pasta = $this->pasta();
        $pagamento = $this->pagamento($pasta, 'Parcela 1', '500.00');
        $base = ['acao' => 'pagamento', 'pasta_id' => $pasta->getId(), 'pagamento_id' => $pagamento->getId()];

        $recebido = $this->executar($base + ['operacao' => 'marcar_recebido']);
        self::assertTrue($recebido->dados['recebido']);
        self::assertTrue($this->recarregar(PastaPagamento::class, (int) $pagamento->getId())->estaPago());
        $this->assertMotivo($base + ['operacao' => 'marcar_recebido'], ErroDaFerramenta::MOTIVO_ENTRADA_INVALIDA);

        $desfeito = $this->executar($base + ['operacao' => 'desfazer_recebimento']);
        self::assertFalse($desfeito->dados['recebido']);
        self::assertFalse($this->recarregar(PastaPagamento::class, (int) $pagamento->getId())->estaPago());
        $this->assertMotivo($base + ['operacao' => 'desfazer_recebimento'], ErroDaFerramenta::MOTIVO_ENTRADA_INVALIDA);
    }

    #[TestDox('pagamento: corrigir o valor é proposta; só grava depois do confirmar')]
    public function testCorrigirValor(): void
    {
        $pasta = $this->pasta();
        $pagamento = $this->pagamento($pasta, 'Parcela 1', '500.00');

        $proposta = $this->executar(['acao' => 'pagamento', 'operacao' => 'corrigir_valor', 'pasta_id' => $pasta->getId(), 'pagamento_id' => $pagamento->getId(), 'valor' => '650,00']);

        self::assertSame('aguardando_confirmacao', $proposta->dados['estado']);
        self::assertStringContainsString('de R$ 500,00 para R$ 650,00', $proposta->dados['proposta']['resumo']);
        self::assertSame('500.00', $this->recarregar(PastaPagamento::class, (int) $pagamento->getId())->getValor());

        $confirmada = $this->confirmar($proposta);
        self::assertTrue($confirmada->dados['resultado']['alterado']);
        self::assertSame('650.00', $this->recarregar(PastaPagamento::class, (int) $pagamento->getId())->getValor());
    }

    #[TestDox('pagamento: o valor mudou depois da proposta, a confirmação é recusada e nada grava')]
    public function testCorrigirValorQueMudou(): void
    {
        $pasta = $this->pasta();
        $pagamento = $this->pagamento($pasta, 'Parcela 1', '500.00');
        $proposta = $this->executar(['acao' => 'pagamento', 'operacao' => 'corrigir_valor', 'pasta_id' => $pasta->getId(), 'pagamento_id' => $pagamento->getId(), 'valor' => '650,00']);

        $this->em()->getConnection()->executeStatement('UPDATE pasta_pagamento SET valor = 700 WHERE id = ?', [$pagamento->getId()]);

        try {
            $this->confirmar($proposta);
            self::fail('deveria recusar');
        } catch (ErroDaFerramenta $e) {
            self::assertSame(FinanceiroDaPastaFerramenta::MOTIVO_MUDOU, $e->motivo);
            self::assertSame(FinanceiroDaPastaFerramenta::MUDOU, $e->getMessage());
        }
        self::assertSame('700.00', $this->recarregar(PastaPagamento::class, (int) $pagamento->getId())->getValor());
    }

    #[TestDox('pagamento: corrigir com valor zero é recusado antes de propor')]
    public function testCorrigirComZero(): void
    {
        $pasta = $this->pasta();
        $pagamento = $this->pagamento($pasta, 'Parcela 1', '500.00');

        $this->assertMotivo(['acao' => 'pagamento', 'operacao' => 'corrigir_valor', 'pasta_id' => $pasta->getId(), 'pagamento_id' => $pagamento->getId(), 'valor' => '0,00'], ErroDaFerramenta::MOTIVO_ENTRADA_INVALIDA);
    }

    #[TestDox('pagamento: excluir é proposta, diz que não desfaz e só apaga depois do confirmar')]
    public function testExcluirPagamento(): void
    {
        $pasta = $this->pasta();
        $pagamento = $this->pagamento($pasta, 'Parcela 1', '500.00');

        $proposta = $this->executar(['acao' => 'pagamento', 'operacao' => 'excluir', 'pasta_id' => $pasta->getId(), 'pagamento_id' => $pagamento->getId()]);

        self::assertSame('aguardando_confirmacao', $proposta->dados['estado']);
        self::assertStringContainsString('Não há como desfazer', $proposta->dados['proposta']['resumo']);
        self::assertNotNull($this->recarregar(PastaPagamento::class, (int) $pagamento->getId()));

        $confirmada = $this->confirmar($proposta);
        self::assertSame([['pagamento_id' => $pagamento->getId()]], $confirmada->dados['resultado']['excluidos']);
        self::assertNull($this->recarregar(PastaPagamento::class, (int) $pagamento->getId()));
    }

    #[TestDox('pagamento: de outra pasta, de outro escritório ou que não existe: não encontrado')]
    public function testPagamentoAlheio(): void
    {
        $pasta = $this->pasta();
        $outraPasta = $this->pasta();
        $outroEscritorio = $this->escritorio();
        $daOutraPasta = $this->pagamento($outraPasta, 'Outra', '10.00');
        $doOutroEscritorio = $this->pagamento($this->pasta($outroEscritorio), 'Alheia', '10.00', $outroEscritorio);

        foreach ([(int) $daOutraPasta->getId(), (int) $doOutroEscritorio->getId(), 999_999_999] as $id) {
            foreach (['marcar_recebido', 'corrigir_valor', 'excluir'] as $operacao) {
                $this->assertMotivo(['acao' => 'pagamento', 'operacao' => $operacao, 'pasta_id' => $pasta->getId(), 'pagamento_id' => $id] + ($operacao === 'corrigir_valor' ? ['valor' => '1,00'] : []), ErroDaFerramenta::MOTIVO_NAO_ENCONTRADO);
            }
        }
    }

    #[TestDox('documento_assinado: marcar e desmarcar gravam direto; repetir é recusado pela regra do UseCase')]
    public function testAssinatura(): void
    {
        $pasta = $this->pasta();
        $documento = $this->contrato($pasta, 'contrato.pdf');
        $base = ['acao' => 'documento_assinado', 'pasta_id' => $pasta->getId(), 'documento_id' => $documento->getId()];

        $marcado = $this->executar($base + ['operacao' => 'marcar']);
        self::assertTrue($marcado->dados['assinado']);
        self::assertTrue($this->recarregar(PastaDocumento::class, (int) $documento->getId())->estaAssinado());
        $this->assertMotivo($base + ['operacao' => 'marcar'], ErroDaFerramenta::MOTIVO_ENTRADA_INVALIDA);

        $desmarcado = $this->executar($base + ['operacao' => 'desmarcar']);
        self::assertFalse($desmarcado->dados['assinado']);
        self::assertFalse($this->recarregar(PastaDocumento::class, (int) $documento->getId())->estaAssinado());
    }

    #[TestDox('documento_assinado: excluir é proposta e só manda para a lixeira depois do confirmar')]
    public function testExcluirDocumento(): void
    {
        $pasta = $this->pasta();
        $documento = $this->contrato($pasta, 'contrato.pdf');

        $proposta = $this->executar(['acao' => 'documento_assinado', 'operacao' => 'excluir', 'pasta_id' => $pasta->getId(), 'documento_id' => $documento->getId()]);

        self::assertSame('aguardando_confirmacao', $proposta->dados['estado']);
        self::assertFalse($this->recarregar(PastaDocumento::class, (int) $documento->getId())->estaNaLixeira());

        $confirmada = $this->confirmar($proposta);
        self::assertSame('lixeira', $confirmada->dados['resultado']['destino']);
        $this->em()->clear();
        $linha = $this->em()->getConnection()->fetchOne('SELECT excluido_em FROM pasta_documento WHERE id = ?', [$documento->getId()]);
        self::assertNotFalse($linha);
        self::assertNotNull($linha, 'a linha fica, com a lápide, até a purga');
    }

    #[TestDox('documento_assinado: documento que não é contrato do Financeiro, de outra pasta, da lixeira ou de outro escritório: não encontrado')]
    public function testDocumentoQueNaoServe(): void
    {
        $pasta = $this->pasta();
        $outroEscritorio = $this->escritorio();
        $demais = $this->contrato($pasta, 'peticao.pdf', PastaDocumento::CATEGORIA_DEMAIS);
        $daOutraPasta = $this->contrato($this->pasta(), 'outro.pdf');
        $naLixeira = $this->contrato($pasta, 'velho.pdf');
        $naLixeira->marcarExcluido($this->pessoa, new \DateTimeImmutable());
        $this->em()->flush();
        $alheio = $this->contrato($this->pasta($outroEscritorio), 'alheio.pdf', PastaDocumento::CATEGORIA_CONTRATO, $outroEscritorio);

        foreach ([$demais, $daOutraPasta, $naLixeira, $alheio] as $documento) {
            foreach (['marcar', 'excluir'] as $operacao) {
                $this->assertMotivo(['acao' => 'documento_assinado', 'operacao' => $operacao, 'pasta_id' => $pasta->getId(), 'documento_id' => $documento->getId()], ErroDaFerramenta::MOTIVO_NAO_ENCONTRADO);
            }
        }
    }

    #[TestDox('renomear_documento: é proposta, o resumo leva o nome antigo mascarado e o novo; só grava depois do confirmar')]
    public function testRenomearDocumento(): void
    {
        $pasta = $this->pasta();
        $documento = $this->contrato($pasta, 'Contrato do 123.456.789-09.pdf');

        $proposta = $this->executar(['acao' => 'renomear_documento', 'pasta_id' => $pasta->getId(), 'documento_id' => $documento->getId(), 'titulo' => '  Contrato assinado.pdf ']);

        self::assertSame('aguardando_confirmacao', $proposta->dados['estado']);
        $resumo = $proposta->dados['proposta']['resumo'];
        self::assertStringContainsString('para "Contrato assinado.pdf"', $resumo);
        self::assertStringNotContainsString('123.456.789-09', $resumo, 'o nome antigo sai mascarado');
        self::assertSame('Contrato do 123.456.789-09.pdf', $this->recarregar(PastaDocumento::class, (int) $documento->getId())->getTitulo(), 'a proposta não grava');

        $confirmada = $this->confirmar($proposta);
        self::assertSame('confirmada', $confirmada->dados['estado']);
        self::assertSame('Contrato assinado.pdf', $this->recarregar(PastaDocumento::class, (int) $documento->getId())->getTitulo());
    }

    #[TestDox('renomear_documento: o nome mudou depois da proposta, a confirmação é recusada e nada grava')]
    public function testRenomearDocumentoQueMudou(): void
    {
        $pasta = $this->pasta();
        $documento = $this->contrato($pasta, 'antigo.pdf');
        $proposta = $this->executar(['acao' => 'renomear_documento', 'pasta_id' => $pasta->getId(), 'documento_id' => $documento->getId(), 'titulo' => 'novo.pdf']);

        $this->em()->getConnection()->executeStatement('UPDATE pasta_documento SET titulo = ? WHERE id = ?', ['outro.pdf', $documento->getId()]);

        try {
            $this->confirmar($proposta);
            self::fail('deveria recusar');
        } catch (ErroDaFerramenta $e) {
            self::assertSame(FinanceiroDaPastaFerramenta::MOTIVO_MUDOU, $e->motivo);
        }
        self::assertSame('outro.pdf', $this->recarregar(PastaDocumento::class, (int) $documento->getId())->getTitulo());
    }

    #[TestDox('renomear_documento: nome vazio, longo, igual ao atual e documento que não serve são recusados')]
    public function testRenomearDocumentoRecusas(): void
    {
        $pasta = $this->pasta();
        $documento = $this->contrato($pasta, 'a.pdf');
        $base = ['acao' => 'renomear_documento', 'pasta_id' => $pasta->getId(), 'documento_id' => $documento->getId()];

        $this->assertMotivo($base + ['titulo' => '   '], ErroDaFerramenta::MOTIVO_ENTRADA_INVALIDA);
        $this->assertMotivo($base + ['titulo' => str_repeat('a', 256)], ErroDaFerramenta::MOTIVO_ENTRADA_INVALIDA);
        $this->assertMotivo($base + ['titulo' => 'a.pdf'], ErroDaFerramenta::MOTIVO_ENTRADA_INVALIDA);
        $this->assertMotivo(['acao' => 'renomear_documento', 'pasta_id' => $pasta->getId(), 'documento_id' => $documento->getId()], ErroDaFerramenta::MOTIVO_ENTRADA_INVALIDA);

        $demais = $this->contrato($pasta, 'peticao.pdf', PastaDocumento::CATEGORIA_DEMAIS);
        $daOutraPasta = $this->contrato($this->pasta(), 'outro.pdf');
        $outroEscritorio = $this->escritorio();
        $alheio = $this->contrato($this->pasta($outroEscritorio), 'alheio.pdf', PastaDocumento::CATEGORIA_CONTRATO, $outroEscritorio);
        foreach ([$demais, $daOutraPasta, $alheio] as $naoServe) {
            $this->assertMotivo(['acao' => 'renomear_documento', 'pasta_id' => $pasta->getId(), 'documento_id' => $naoServe->getId(), 'titulo' => 'novo.pdf'], ErroDaFerramenta::MOTIVO_NAO_ENCONTRADO);
        }
        self::assertSame('a.pdf', $this->recarregar(PastaDocumento::class, (int) $documento->getId())->getTitulo());
    }

    #[TestDox('renomear_documento: quem só vê a pasta (sem editar) recebe não encontrado')]
    public function testRenomearDocumentoSemEditar(): void
    {
        $pasta = $this->pasta();
        $documento = $this->contrato($pasta, 'a.pdf');
        $leitor = $this->pessoa();
        $this->vinculo($leitor, $this->escritorio, permissoes: self::LEITOR);
        $this->pessoa = $leitor;
        $this->consentimentoDe($leitor);

        $this->assertMotivo(['acao' => 'renomear_documento', 'pasta_id' => $pasta->getId(), 'documento_id' => $documento->getId(), 'titulo' => 'b.pdf'], ErroDaFerramenta::MOTIVO_NAO_ENCONTRADO);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function pedidosInvalidos(): iterable
    {
        yield 'ação fora da lista' => [['acao' => 'apagar_tudo']];
        yield 'sem situacao' => [['acao' => 'contrato']];
        yield 'situacao fora da lista' => [['acao' => 'contrato', 'situacao' => 'cancelado']];
        yield 'campo de outra ação' => [['acao' => 'contrato', 'situacao' => 'regular', 'valor' => '1,00']];
        yield 'operacao em ação sem operação' => [['acao' => 'pro_bono', 'ligado' => true, 'operacao' => 'marcar']];
        yield 'pro_bono com texto' => [['acao' => 'pro_bono', 'ligado' => 'sim']];
        yield 'pagamento sem operacao' => [['acao' => 'pagamento']];
        yield 'operacao de documento no pagamento' => [['acao' => 'pagamento', 'operacao' => 'marcar', 'pagamento_id' => 1]];
        yield 'excluir sem pagamento_id' => [['acao' => 'pagamento', 'operacao' => 'excluir']];
        yield 'registrar sem vencimento' => [['acao' => 'pagamento', 'operacao' => 'registrar', 'descricao' => 'a', 'valor' => '1,00']];
        yield 'vencimento fora do formato' => [['acao' => 'pagamento', 'operacao' => 'registrar', 'descricao' => 'a', 'valor' => '1,00', 'vencimento' => '10/12/2026']];
        yield 'documento com campo de pagamento' => [['acao' => 'documento_assinado', 'operacao' => 'marcar', 'documento_id' => 1, 'pagamento_id' => 1]];
        yield 'documento_id zero' => [['acao' => 'documento_assinado', 'operacao' => 'marcar', 'documento_id' => 0]];
    }

    /** @param array<string, mixed> $argumentos */
    #[DataProvider('pedidosInvalidos')]
    #[TestDox('entrada fechada: $_dataName é pedido inválido')]
    public function testPedidoInvalido(array $argumentos): void
    {
        $pasta = $this->pasta();

        $this->assertMotivo(['pasta_id' => $pasta->getId()] + $argumentos, ErroDaFerramenta::MOTIVO_ENTRADA_INVALIDA);
    }

    #[TestDox('o esquema tem as seis ações da tabela e fecha os campos')]
    public function testEsquema(): void
    {
        $esquema = static::getContainer()->get(FinanceiroDaPastaFerramenta::class)->esquemaDeEntrada();

        self::assertSame(['contrato', 'pro_bono', 'valor_da_causa', 'pagamento', 'documento_assinado', 'renomear_documento'], $esquema['properties']['acao']['enum']);
        self::assertFalse($esquema['additionalProperties']);
        self::assertSame(['acao', 'pasta_id'], $esquema['required']);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function gravacoes(): iterable
    {
        yield 'contrato' => [['acao' => 'contrato', 'situacao' => 'regular']];
        yield 'pro_bono' => [['acao' => 'pro_bono', 'ligado' => true]];
        yield 'valor_da_causa' => [['acao' => 'valor_da_causa', 'valor' => '1,00']];
        yield 'registrar pagamento' => [['acao' => 'pagamento', 'operacao' => 'registrar', 'descricao' => 'a', 'valor' => '1,00', 'vencimento' => '2026-12-10']];
        yield 'parcelar' => [['acao' => 'pagamento', 'operacao' => 'parcelar', 'tipo' => 'contrato', 'valor' => '10,00', 'parcelas' => '2', 'vencimento' => '2026-12-10']];
        yield 'assinatura' => [['acao' => 'documento_assinado', 'operacao' => 'marcar', 'documento_id' => 1]];
        yield 'renomear documento' => [['acao' => 'renomear_documento', 'documento_id' => 1, 'titulo' => 'novo.pdf']];
    }

    /** @param array<string, mixed> $argumentos */
    #[DataProvider('gravacoes')]
    #[TestDox('pasta de outro escritório, inexistente ou na lixeira: não encontrado e nada gravado: $_dataName')]
    public function testPastaQueNaoServe(array $argumentos): void
    {
        $outroEscritorio = $this->escritorio();
        $alheia = $this->pasta($outroEscritorio);
        $naLixeira = $this->pasta();
        $naLixeira->marcarExcluida($this->pessoa, new \DateTimeImmutable());
        $this->em()->flush();

        foreach ([(int) $alheia->getId(), (int) $naLixeira->getId(), 999_999_999] as $id) {
            $this->assertMotivo(['pasta_id' => $id] + $argumentos, ErroDaFerramenta::MOTIVO_NAO_ENCONTRADO);
        }
        // Pelo SQL: com o filtro do escritório ligado pelo portão, a pasta alheia nem carrega pelo ORM.
        $conexao = $this->em()->getConnection();
        self::assertSame('PENDENTE', $conexao->fetchOne('SELECT situacao_contrato FROM pasta WHERE id = ?', [$alheia->getId()]));
        self::assertNull($conexao->fetchOne('SELECT valor_causa FROM pasta WHERE id = ?', [$alheia->getId()]) ?: null);
        self::assertSame(0, (int) $conexao->fetchOne('SELECT COUNT(*) FROM pasta_pagamento WHERE pasta_id IN (?, ?)', [$alheia->getId(), $naLixeira->getId()]));
    }

    #[TestDox('aba Financeiro oculta para a pessoa: não encontrado em direta e em proposta, sem gravar')]
    #[DataProvider('gravacoes')]
    public function testFinanceiroOculto(array $argumentos): void
    {
        $pasta = $this->pasta();
        $this->em()->persist(AbaOcultaDaPasta::paraTodaAEquipe($this->escritorio, $pasta, AbaDaPasta::Financeiro, $this->pessoa(), new \DateTimeImmutable()));
        $this->em()->flush();

        $this->assertMotivo(['pasta_id' => $pasta->getId()] + $argumentos, ErroDaFerramenta::MOTIVO_NAO_ENCONTRADO);
        $this->assertMotivo(['acao' => 'valor_da_causa', 'pasta_id' => $pasta->getId(), 'valor' => '1,00'], ErroDaFerramenta::MOTIVO_NAO_ENCONTRADO);
        self::assertSame('PENDENTE', $this->recarregar(Pasta::class, (int) $pasta->getId())->getSituacaoContrato());
    }

    #[TestDox('quem só vê a pasta, sem o recurso de editar: não encontrado e nada grava')]
    public function testSemEditar(): void
    {
        $leitor = $this->pessoa();
        $this->vinculo($leitor, $this->escritorio, permissoes: self::LEITOR);
        $pasta = $this->pasta();
        $this->pessoa = $leitor;
        $this->consentimentoDe($leitor);

        $this->assertMotivo(['acao' => 'pro_bono', 'pasta_id' => $pasta->getId(), 'ligado' => true], ErroDaFerramenta::MOTIVO_NAO_ENCONTRADO);
        self::assertFalse($this->recarregar(Pasta::class, (int) $pasta->getId())->isProBono());
    }

    #[TestDox('sem o módulo Pastas: sem permissão')]
    public function testSemModulo(): void
    {
        $semModulo = $this->pessoa();
        $this->vinculo($semModulo, $this->escritorio, permissoes: ['resources.pasta.view', 'resources.pasta.edit']);
        $pasta = $this->pasta();
        $this->pessoa = $semModulo;
        $this->consentimentoDe($semModulo);

        $this->assertMotivo(['acao' => 'pro_bono', 'pasta_id' => $pasta->getId(), 'ligado' => true], ErroDaFerramenta::MOTIVO_SEM_PERMISSAO);
    }

    #[TestDox('pasta em segredo de justiça: recusa a gravação com a frase fixa')]
    public function testEmSegredo(): void
    {
        $pasta = $this->pastaEmSegredo();

        $this->assertMotivo(['acao' => 'pro_bono', 'pasta_id' => $pasta->getId(), 'ligado' => true], ErroDaFerramenta::MOTIVO_EM_SEGREDO);
        self::assertFalse($this->recarregar(Pasta::class, (int) $pasta->getId())->isProBono());
    }

    #[TestDox('a confirmação confere de novo: pasta que foi para a lixeira ou Financeiro que ficou oculto não grava e a proposta fica pendente')]
    public function testReconferenciaNaConfirmacao(): void
    {
        $pasta = $this->pasta();
        $pagamento = $this->pagamento($pasta, 'Parcela 1', '500.00');
        $proposta = $this->executar(['acao' => 'pagamento', 'operacao' => 'excluir', 'pasta_id' => $pasta->getId(), 'pagamento_id' => $pagamento->getId()]);

        $quem = $this->pessoa();
        $viva = $this->recarregar(Pasta::class, (int) $pasta->getId());
        $escritorio = $this->em()->find(Tenant::class, (int) $this->escritorio->getId());
        $quem = $this->em()->find(User::class, (int) $quem->getId());
        $this->em()->persist(AbaOcultaDaPasta::paraTodaAEquipe($escritorio, $viva, AbaDaPasta::Financeiro, $quem, new \DateTimeImmutable()));
        $this->em()->flush();
        try {
            $this->confirmar($proposta);
            self::fail('deveria recusar com o Financeiro oculto');
        } catch (ErroDaFerramenta $e) {
            self::assertSame(ErroDaFerramenta::MOTIVO_NAO_ENCONTRADO, $e->motivo);
        }
        self::assertNotNull($this->recarregar(PastaPagamento::class, (int) $pagamento->getId()));
    }

    #[TestDox('a confirmação com a pasta na lixeira é recusada e o pagamento continua')]
    public function testConfirmacaoComPastaNaLixeira(): void
    {
        $pasta = $this->pasta();
        $pagamento = $this->pagamento($pasta, 'Parcela 1', '500.00');
        $proposta = $this->executar(['acao' => 'pagamento', 'operacao' => 'excluir', 'pasta_id' => $pasta->getId(), 'pagamento_id' => $pagamento->getId()]);

        $quem = $this->recarregar(User::class, (int) $this->pessoa->getId());
        $viva = $this->em()->find(Pasta::class, (int) $pasta->getId());
        $viva->marcarExcluida($quem, new \DateTimeImmutable());
        $this->em()->flush();
        try {
            $this->confirmar($proposta);
            self::fail('deveria recusar com a pasta na lixeira');
        } catch (ErroDaFerramenta $e) {
            self::assertSame(ErroDaFerramenta::MOTIVO_NAO_ENCONTRADO, $e->motivo);
        }
        self::assertNotNull($this->recarregar(PastaPagamento::class, (int) $pagamento->getId()));
    }

    /** @param array<string, mixed> $argumentos */
    private function executar(array $argumentos): RespostaDaFerramenta
    {
        return static::getContainer()->get(FinanceiroDaPastaFerramenta::class)->executar($argumentos, $this->chamada());
    }

    private function confirmar(RespostaDaFerramenta $proposta): RespostaDaFerramenta
    {
        $chamada = $this->chamada();

        return static::getContainer()->get(ConfirmarAcaoFerramenta::class)->executar([
            'proposta_id' => $proposta->dados['proposta']['proposta_id'],
            'resumo' => $proposta->dados['proposta']['resumo'],
        ], $chamada);
    }

    /** @param array<string, mixed> $argumentos */
    private function assertMotivo(array $argumentos, string $motivo): void
    {
        try {
            $this->executar($argumentos);
        } catch (ErroDaFerramenta $e) {
            self::assertSame($motivo, $e->motivo, $e->getMessage());

            return;
        }
        self::fail('deveria recusar com ' . $motivo);
    }

    /**
     * Uma chamada nova, com o modo do conector ligado pelo portão para a pessoa e o escritório do cenário.
     *
     * @param list<string> $escopos
     */
    private function chamada(array $escopos = ['mcp:leitura', 'mcp:escrita']): ChamadaDoConector
    {
        static::getContainer()->get('services_resetter')->reset();
        $contexto = $this->portao()->abrir((int) $this->pessoa->getId(), (int) $this->escritorio->getId());
        $consentimento = $this->consentimentoDe($this->pessoa);

        return new ChamadaDoConector($contexto, 41, (int) $consentimento->getId(), 'claude.ai', $escopos, new \DateTimeImmutable('+7 days'));
    }

    private function consentimentoDe(User $pessoa): ConsentimentoDoConector
    {
        if ($pessoa->getId() === $this->consentimento->getUser()?->getId()) {
            return $this->consentimento;
        }
        $app = $this->consentimento->getCliente();
        $consentimento = new ConsentimentoDoConector($pessoa, $this->escritorio, $app, ['mcp:leitura', 'mcp:escrita'], new \DateTimeImmutable());
        $this->em()->persist($consentimento);
        $this->em()->flush();
        $this->consentimento = $consentimento;

        return $consentimento;
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $classe
     *
     * @return T|null
     */
    private function recarregar(string $classe, int $id): ?object
    {
        $this->em()->clear();

        return $this->em()->find($classe, $id);
    }

    private function pasta(?Tenant $escritorio = null): Pasta
    {
        $pasta = (new Pasta())->setNup('FIN-' . uniqid());
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

    private function pagamento(Pasta $pasta, string $descricao, string $valor, ?Tenant $escritorio = null): PastaPagamento
    {
        $pagamento = new PastaPagamento();
        $pagamento->setPasta($pasta);
        $pagamento->setTenant($escritorio ?? $this->escritorio);
        $pagamento->setAutor($this->pessoa);
        $pagamento->setDescricao($descricao);
        $pagamento->setValor($valor);
        $pagamento->setVencimento(new \DateTimeImmutable('2026-12-10'));
        $this->em()->persist($pagamento);
        $this->em()->flush();

        return $pagamento;
    }

    private function contrato(Pasta $pasta, string $nome, string $categoria = PastaDocumento::CATEGORIA_CONTRATO, ?Tenant $escritorio = null): PastaDocumento
    {
        $doc = new PastaDocumento();
        $doc->setTenant($escritorio ?? $this->escritorio);
        $doc->setPasta($pasta);
        $doc->setTitulo($nome);
        $doc->setCategoria($categoria);
        $doc->setCaminhoArquivo(uniqid('doc', true) . '.bin');
        $doc->setNomeOriginal($nome);
        $doc->setMimeType('application/pdf');
        $doc->setTamanhoBytes(10);
        $this->em()->persist($doc);
        $this->em()->flush();

        return $doc;
    }
}
