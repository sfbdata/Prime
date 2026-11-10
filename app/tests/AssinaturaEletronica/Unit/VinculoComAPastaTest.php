<?php

declare(strict_types=1);

namespace App\Tests\AssinaturaEletronica\Unit;

use App\AssinaturaEletronica\Entity\DocumentoParaAssinar;
use App\AssinaturaEletronica\Enum\TipoDeEvento;
use App\AssinaturaEletronica\Event\PdfFinalGuardado;
use App\AssinaturaEletronica\EventListener\VinculoPendenteListener;
use App\AssinaturaEletronica\Message\GerarPdfFinalDoDocumento;
use App\AssinaturaEletronica\MessageHandler\GerarPdfFinalDoDocumentoHandler;
use App\AssinaturaEletronica\Service\ArquivoAssinadoNaPasta;
use App\AssinaturaEletronica\Service\VinculoComAPasta;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaDocumento;
use App\Repository\TenantRepository;
use App\Shared\Doctrine\Filter\AcessoALixeira;
use App\Tests\AssinaturaEletronica\Support\OficinaDoVinculo;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * A aplicação do vínculo pendente (spec assinatura-documentos-etapa-3, E3-11 e E3-12): tudo conferido de novo com quem
 * vinculou; na recusa nada vai para a pasta, a trilha diz o motivo e o vínculo pendente se desfaz (o "Vincular à pasta"
 * volta), salvo quando outro processo já o aplicou ou trocou no meio. O PDF final nunca é gerado aqui: sem ele guardado,
 * o pedido vai ao worker e o vínculo segue pendente até o evento do PDF guardado.
 */
#[CoversClass(VinculoComAPasta::class)]
#[CoversClass(ArquivoAssinadoNaPasta::class)]
final class VinculoComAPastaTest extends TestCase
{
    use OficinaDoVinculo;

    protected function setUp(): void
    {
        $this->montarVinculo();
    }

    #[TestDox('aguardando que conclui: o vínculo pendente vai para a pasta com quem vinculou e a trilha diz "vinculado"')]
    public function testPendenteAplicadoAoConcluir(): void
    {
        $documento = $this->pendenteQueConcluiu();

        self::assertTrue($this->vinculo()->aplicar($documento));

        self::assertFalse($documento->isVinculoPendente());
        self::assertCount(1, $this->naPasta);
        self::assertSame(self::ator()->getId(), $this->naPasta[0]->getEnviadoPor()?->getId());
        self::assertSame(['vinculo_definido', 'vinculo_feito'], $this->tiposGravados());
        self::assertSame(7, $this->ultimoGravado(TipoDeEvento::VinculoFeito)->getUsuario()?->getId(), 'em nome de quem vinculou');
        self::assertSame([], $this->despachadas, 'com o PDF final guardado, nada vai para a fila');
    }

    #[TestDox('concluído sem o PDF final guardado: pede ao worker, não gera nada aqui e o vínculo segue pendente, sem "não aplicado"')]
    public function testSemPdfGuardadoPedeAoWorker(): void
    {
        $documento = $this->pendenteQueConcluiu(comPdf: false);
        $antes = $this->tiposGravados();

        self::assertFalse($this->vinculo()->aplicar($documento));

        self::assertEquals([new GerarPdfFinalDoDocumento(1, 9)], $this->despachadas, 'o documento e o escritório dele');
        self::assertSame(0, $this->pdfsGarantidos, 'o PDF final não é pedido nesta requisição');
        self::assertTrue($documento->isVinculoPendente());
        self::assertSame(42, $documento->getPasta()?->getId());
        self::assertSame(7, $documento->getVinculadoPor()?->getId());
        self::assertSame($antes, $this->tiposGravados(), 'sem "vínculo não aplicado"');
        self::assertSame([], $this->naPasta);
        self::assertSame([], $this->disco->chavesGravadas, 'nada gerado nem copiado');
    }

    #[TestDox('concluído sem o PDF final e a fila fora do ar: não aplica, a trilha diz "PDF indisponível" e o vínculo se desfaz')]
    public function testFilaCaidaAoPedirOPdf(): void
    {
        $documento = $this->pendenteQueConcluiu(comPdf: false);
        $this->filaCaida = new \RuntimeException('fila fora do ar');

        self::assertFalse($this->vinculo()->aplicar($documento));

        $this->assertNaoAplicado($documento, 'pdf_indisponivel');
        self::assertSame(0, $this->pdfsGarantidos);
        self::assertSame([], $this->despachadas);
        self::assertCount(1, $this->logger->doNivel('error'), 'a falha da fila vai para o log');
    }

    #[TestDox('"Vincular à pasta": pendente só no concluído que espera o PDF final; aguardando e aplicado não')]
    public function testPendenteNaSaidaDoUseCase(): void
    {
        $this->documentoEnviado();
        $this->pasta();
        $aguardando = $this->useCase()->executar(self::ator(), $this->tenant, 1, 42);
        self::assertFalse($aguardando['aplicado']);
        self::assertFalse($aguardando['pendente'], 'aguardando: a tela já diz "quando concluído"');

        $this->naBase = [];
        $this->gravados = [];
        $semPdf = $this->concluido(comPdf: false);
        $esperando = $this->useCase()->executar(self::ator(), $this->tenant, 1, 42);
        self::assertFalse($esperando['aplicado']);
        self::assertTrue($esperando['pendente']);
        self::assertTrue($esperando['documento']['vinculado'], 'o vínculo fica: o "Vincular à pasta" não volta');
        self::assertTrue($semPdf->isVinculoPendente());
        self::assertSame([], $this->tiposGravados(), 'sem "ao concluir" nem "não aplicado"');
        self::assertEquals([new GerarPdfFinalDoDocumento(1, 9)], $this->despachadas);

        $this->naBase = [];
        $this->concluido();
        $aplicado = $this->useCase()->executar(self::ator(), $this->tenant, 1, 42);
        self::assertTrue($aplicado['aplicado']);
        self::assertFalse($aplicado['pendente']);
        self::assertCount(1, $this->despachadas, 'com o PDF final guardado, nada novo na fila');
    }

    #[TestDox('quem vinculou perdeu o acesso (permissão ou cadeado): não aplica, a trilha diz o motivo e o vínculo se desfaz')]
    public function testPerdeuOAcesso(): void
    {
        $documento = $this->pendenteQueConcluiu();
        $this->acesso[42]['editar'] = false;

        self::assertFalse($this->vinculo()->aplicar($documento));

        $this->assertNaoAplicado($documento, 'sem_permissao');
        self::assertSame(0, $this->pdfsGarantidos, 'nem pede o PDF final');
        self::assertSame([], $this->despachadas, 'nem ao worker');
    }

    #[TestDox('pasta foi para a lápide depois do vínculo: não aplica')]
    public function testPastaNaLapide(): void
    {
        $documento = $this->pendenteQueConcluiu();
        $this->pastasNaBase[42]->marcarExcluida(self::ator(), new \DateTimeImmutable('2026-11-09 09:50:00'));

        self::assertFalse($this->vinculo()->aplicar($documento));

        $this->assertNaoAplicado($documento, 'pasta_excluida');
    }

    #[TestDox('pasta apagada de vez (SET NULL): não aplica, e o número vem do evento do vínculo')]
    public function testPastaApagada(): void
    {
        $documento = $this->pendenteQueConcluiu();
        (new \ReflectionProperty(DocumentoParaAssinar::class, 'pasta'))->setValue($documento, null);

        self::assertFalse($this->vinculo()->aplicar($documento));

        $this->assertNaoAplicado($documento, 'pasta_apagada');
    }

    #[TestDox('quem vinculou foi removido (SET NULL) ou desativado: não aplica')]
    public function testQuemVinculouSaiu(): void
    {
        $documento = $this->pendenteQueConcluiu();
        $documento->getVinculadoPor()?->setIsActive(false);
        self::assertFalse($this->vinculo()->aplicar($documento));
        $this->assertNaoAplicado($documento, 'sem_quem_vinculou');

        $this->naBase = [];
        $this->gravados = [];
        $removido = $this->pendenteQueConcluiu();
        (new \ReflectionProperty(DocumentoParaAssinar::class, 'vinculadoPor'))->setValue($removido, null);
        self::assertFalse($this->vinculo()->aplicar($removido));
        self::assertSame('assinatura_eletronica.vinculo_motivo.sem_quem_vinculou', $this->ultimoGravado(TipoDeEvento::VinculoNaoAplicado)->getDados()['motivo']);
        self::assertNull($this->ultimoGravado(TipoDeEvento::VinculoNaoAplicado)->getUsuario());
        self::assertNull($removido->getPasta());
        self::assertFalse($removido->isVinculoPendente());
    }

    #[TestDox('falhou e se desfez: pode vincular de novo, e o segundo vínculo vai para a pasta')]
    public function testVinculaDeNovoDepoisDaFalha(): void
    {
        $documento = $this->pendenteQueConcluiu();
        $this->acesso[42]['editar'] = false;
        self::assertFalse($this->vinculo()->aplicar($documento));
        $this->assertNaoAplicado($documento, 'sem_permissao');

        $this->pasta(43, 'P-0043');
        $saida = $this->useCase()->executar(self::ator(), $this->tenant, 1, 43);

        self::assertTrue($saida['aplicado']);
        self::assertSame(43, $documento->getPasta()?->getId());
        self::assertCount(1, $this->naPasta);
        self::assertSame(['vinculo_definido', 'vinculo_nao_aplicado', 'vinculo_feito'], $this->tiposGravados());
    }

    /** @return iterable<string, array{\Closure(self, DocumentoParaAssinar): void, bool}> */
    public static function outroProcessoNoMeio(): iterable
    {
        yield 'já aplicado por outro processo' => [
            static function (self $teste, DocumentoParaAssinar $d): void {
                $d->marcarVinculoFeito((new PastaDocumento())->setTenant($d->getTenant())->setPasta($d->getPasta()));
            },
            true,
        ];
        yield 'desfeito e vinculado a outra pasta por outro processo' => [
            static function (self $teste, DocumentoParaAssinar $d): void {
                $d->descartarVinculoNaoAplicado();
                $d->definirVinculo($teste->pasta(43, 'P-0043'), self::ator(), new \DateTimeImmutable('2026-11-09 09:50:00'));
            },
            false,
        ];
    }

    /** @param \Closure(self, DocumentoParaAssinar): void $noMeio */
    #[DataProvider('outroProcessoNoMeio')]
    #[TestDox('falha com o vínculo mudado no meio ($_dataName): relido na trava, não desfaz nem grava evento')]
    public function testFalhaComOutroProcessoNoMeio(\Closure $noMeio, bool $naPasta): void
    {
        $documento = $this->pendenteQueConcluiu();
        $this->acesso[42]['editar'] = false;
        $this->aoTravar = fn (DocumentoParaAssinar $d) => $noMeio($this, $d);
        $antes = $this->tiposGravados();

        self::assertSame($naPasta, $this->vinculo()->aplicar($documento), 'verdadeiro só quando o documento já está na pasta');

        self::assertSame($antes, $this->tiposGravados(), 'sem "não aplicado"');
        self::assertNotNull($documento->getPasta(), 'o vínculo do outro processo fica');
        self::assertSame(7, $documento->getVinculadoPor()?->getId());
        self::assertSame(!$naPasta, $documento->isVinculoPendente());
        self::assertSame([], $this->naPasta);
    }

    #[TestDox('páginas: a cópia na pasta leva o número de páginas do PDF final, contado do arquivo como no upload comum')]
    public function testContaAsPaginas(): void
    {
        $documento = $this->pendenteQueConcluiu();
        $this->paginasDoPdf = 4;

        self::assertTrue($this->vinculo()->aplicar($documento));

        self::assertSame(4, $this->naPasta[0]->getPaginas());
        self::assertSame([self::PDF_FINAL], $this->pdfsContados, 'o contador leu a cópia, por um caminho local');
    }

    #[TestDox('páginas: a contagem falhou, o vínculo vai para a pasta e as páginas ficam sem contagem (como no upload)')]
    public function testContagemDePaginasFalhou(): void
    {
        $documento = $this->pendenteQueConcluiu();
        $this->paginasDoPdf = null;

        self::assertTrue($this->vinculo()->aplicar($documento));

        self::assertCount(1, $this->naPasta);
        self::assertNull($this->naPasta[0]->getPaginas());
    }

    #[TestDox('modelo de contrato: categoria Contrato e a situação do contrato da pasta vira Assinado (regular)')]
    public function testContratoMarcaAPasta(): void
    {
        $documento = $this->concluido('contrato');
        $pasta = $this->pasta();
        $documento->definirVinculo($pasta, self::ator(), new \DateTimeImmutable('2026-11-09 09:40:00'));

        self::assertTrue($this->vinculo()->aplicar($documento));

        self::assertSame(PastaDocumento::CATEGORIA_CONTRATO, $this->naPasta[0]->getCategoria());
        self::assertSame('REGULAR', $pasta->getSituacaoContrato());
    }

    #[TestDox('idempotente: vínculo já feito não grava de novo')]
    public function testIdempotente(): void
    {
        $documento = $this->pendenteQueConcluiu();
        self::assertTrue($this->vinculo()->aplicar($documento));
        $eventos = \count($this->gravados);

        self::assertTrue($this->vinculo()->aplicar($documento));

        self::assertCount(1, $this->naPasta);
        self::assertCount($eventos, $this->gravados);
    }

    #[TestDox('cadeia do worker: ele guarda o PDF final, o evento relê o documento com a chave e aplica uma vez só, sem pedir o PDF de novo')]
    public function testWorkerGuardaEOEventoAplica(): void
    {
        $documento = $this->pendenteQueConcluiu(comPdf: false);
        self::assertFalse($this->vinculo()->aplicar($documento));
        $pedido = $this->despachadas[0] ?? null;
        self::assertInstanceOf(GerarPdfFinalDoDocumento::class, $pedido);

        $this->documentos->method('find')->willReturnCallback(fn (mixed $id): ?DocumentoParaAssinar => $this->achar(
            static fn (DocumentoParaAssinar $d): bool => $d->getId() === $id,
        ));
        $ouvinte = new VinculoPendenteListener($this->documentos, new AcessoALixeira($this->createStub(EntityManagerInterface::class)), $this->vinculo(), $this->logger);
        $this->aoGarantir = static function (DocumentoParaAssinar $d) use ($ouvinte): void {
            $ouvinte->aoGuardarPdfFinal(new PdfFinalGuardado((int) $d->getId()));
        };
        $tenants = $this->createStub(TenantRepository::class);
        $tenants->method('find')->willReturnCallback(fn (mixed $id): ?Tenant => $id === 9 ? $this->tenant : null);
        $worker = new GerarPdfFinalDoDocumentoHandler($tenants, $this->documentos, $this->pdfFinal(), $this->barramento());

        $worker($pedido);
        $worker($pedido); // a mesma mensagem entregue de novo: o PDF já está guardado e nada muda

        self::assertFalse($documento->isVinculoPendente());
        self::assertCount(1, $this->naPasta);
        self::assertSame($this->naPasta[0], $documento->getPastaDocumento());
        self::assertCount(1, $this->despachadas, 'o evento achou a chave no documento: não pediu o PDF de novo');
        self::assertSame(['vinculo_definido', 'vinculo_feito'], $this->tiposGravados());
        self::assertCount(2, $this->disco->chavesGravadas, 'o PDF final e uma cópia só');
        self::assertSame([], $this->logger->doNivel('error'));
    }

    #[TestDox('falha ao gravar a cópia na pasta: não aplica, a trilha diz o motivo e o vínculo se desfaz')]
    public function testFalhaAoGravar(): void
    {
        $documento = $this->pendenteQueConcluiu();
        $this->disco->falhaAoGravar = new \RuntimeException('disco cheio');

        self::assertFalse($this->vinculo()->aplicar($documento));

        $this->assertNaoAplicado($documento, 'falha_ao_salvar');
        self::assertCount(1, $this->disco->chavesGravadas, 'só o PDF final');
    }

    #[TestDox('sem vínculo, ou vínculo pendente de documento ainda aguardando: nada acontece')]
    public function testNadaAFazer(): void
    {
        $semVinculo = $this->concluido();
        self::assertFalse($this->vinculo()->aplicar($semVinculo));

        $this->naBase = [];
        $aguardando = $this->documentoEnviado();
        $aguardando->definirVinculo($this->pasta(), self::ator(), new \DateTimeImmutable('2026-11-09 09:40:00'));
        self::assertFalse($this->vinculo()->aplicar($aguardando));

        self::assertSame([], $this->gravados);
        self::assertSame([], $this->naPasta);
    }

    /** @return iterable<string, array{?string, string}> */
    public static function categorias(): iterable
    {
        yield 'procuração' => ['proc-geral', PastaDocumento::CATEGORIA_PROCURACAO];
        yield 'procuração de menor' => ['proc-menor', PastaDocumento::CATEGORIA_PROCURACAO];
        yield 'substabelecimento' => ['subst', PastaDocumento::CATEGORIA_PROCURACAO];
        yield 'contrato' => ['contrato', PastaDocumento::CATEGORIA_CONTRATO];
        yield 'hipossuficiência' => ['hipo', PastaDocumento::CATEGORIA_GRATUIDADE_JUSTICA];
        yield 'residência' => ['resid', PastaDocumento::CATEGORIA_COMPROVANTE_RESIDENCIA];
        yield 'renúncia' => ['renuncia', PastaDocumento::CATEGORIA_DEMAIS];
        yield 'modelo do escritório' => ['esc-12', PastaDocumento::CATEGORIA_DEMAIS];
        yield 'upload' => [null, PastaDocumento::CATEGORIA_DEMAIS];
    }

    #[DataProvider('categorias')]
    #[TestDox('E3-12, categoria pelo modelo: $_dataName')]
    public function testCategoria(?string $modelo, string $categoria): void
    {
        self::assertSame($categoria, ArquivoAssinadoNaPasta::categoria($modelo));
    }

    #[TestDox('nome do arquivo do desenho: sem acento nem símbolo, espaços em sublinhado, até 60 caracteres')]
    public function testNomeDoArquivo(): void
    {
        self::assertSame('assinado_Procuracao_Ad_Judicia_Joao.pdf', ArquivoAssinadoNaPasta::nomeDoArquivo('  Procuração "Ad Judicia" - João  '));
        self::assertSame('assinado_' . str_repeat('a', 60) . '.pdf', ArquivoAssinadoNaPasta::nomeDoArquivo(str_repeat('á', 70)));
    }

    /**
     * Vínculo definido pelo "Vincular à pasta" com o documento aguardando; depois todos assinam e o worker guarda o PDF
     * final (sem ele quando `$comPdf` é falso: o worker ainda não guardou).
     */
    private function pendenteQueConcluiu(bool $comPdf = true): DocumentoParaAssinar
    {
        $documento = $this->documentoEnviado();
        $this->pasta();
        $this->useCase()->executar(self::ator(), $this->tenant, 1, 42);
        foreach ($documento->getSignatarios() as $s) {
            $documento->assinar($s, $s->getNome(), self::CPF_ANA, 'rubrica.png', 'simples', null, null, new \DateTimeImmutable('2026-11-09 09:45:00'));
        }
        self::assertTrue($documento->isVinculoPendente());
        if ($comPdf) {
            $this->guardarPdfFinal($documento);
        }

        return $documento;
    }

    /** A falha desfaz o vínculo pendente (o "Vincular à pasta" volta) e a trilha diz o motivo em nome de quem vinculou. */
    private function assertNaoAplicado(DocumentoParaAssinar $documento, string $motivo): void
    {
        self::assertFalse($documento->isVinculoPendente(), 'o vínculo pendente se desfaz');
        self::assertNull($documento->getPasta(), 'sem pasta: o "Vincular à pasta" volta');
        self::assertNull($documento->getVinculadoPor());
        self::assertNull($documento->getVinculadoEm());
        self::assertNull($documento->getPastaDocumento());
        self::assertSame([], $this->naPasta, 'nada vai para a pasta');
        self::assertSame([], $this->historico);
        $evento = $this->ultimoGravado(TipoDeEvento::VinculoNaoAplicado);
        self::assertSame(['nup' => 'P-0042', 'motivo' => 'assinatura_eletronica.vinculo_motivo.' . $motivo, 'chave' => 1], $evento->getDados());
        self::assertSame(7, $evento->getUsuario()?->getId(), 'em nome de quem vinculou, lido antes de desfazer');
    }
}
