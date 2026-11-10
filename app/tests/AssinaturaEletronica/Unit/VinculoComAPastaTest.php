<?php

declare(strict_types=1);

namespace App\Tests\AssinaturaEletronica\Unit;

use App\AssinaturaEletronica\Entity\DocumentoParaAssinar;
use App\AssinaturaEletronica\Enum\TipoDeEvento;
use App\AssinaturaEletronica\Service\ArquivoAssinadoNaPasta;
use App\AssinaturaEletronica\Service\VinculoComAPasta;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaDocumento;
use App\Tests\AssinaturaEletronica\Support\OficinaDoVinculo;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * A aplicação do vínculo pendente (spec assinatura-documentos-etapa-3, E3-11 e E3-12): tudo conferido de novo com quem
 * vinculou; na recusa nada vai para a pasta, o vínculo fica pendente e a trilha diz o motivo.
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
    }

    #[TestDox('quem vinculou perdeu o acesso (permissão ou cadeado): não aplica, fica pendente e a trilha diz o motivo')]
    public function testPerdeuOAcesso(): void
    {
        $documento = $this->pendenteQueConcluiu();
        $this->acesso[42]['editar'] = false;

        self::assertFalse($this->vinculo()->aplicar($documento));

        $this->assertNaoAplicado($documento, 'sem_permissao');
        self::assertSame(0, $this->pdfsGarantidos, 'nem pede o PDF final');
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

        (new \ReflectionProperty(DocumentoParaAssinar::class, 'vinculadoPor'))->setValue($documento, null);
        self::assertFalse($this->vinculo()->aplicar($documento));
        self::assertSame('assinatura_eletronica.vinculo_motivo.sem_quem_vinculou', $this->ultimoGravado(TipoDeEvento::VinculoNaoAplicado)->getDados()['motivo']);
        self::assertNull($this->ultimoGravado(TipoDeEvento::VinculoNaoAplicado)->getUsuario());
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

    #[TestDox('o PDF final guardado chama de volta (o evento): um arquivo só na pasta')]
    public function testReentradaPeloEvento(): void
    {
        $documento = $this->pendenteQueConcluiu();
        $voltas = [];
        $this->aoGarantir = function (DocumentoParaAssinar $d) use (&$voltas): void {
            $voltas[] = $this->vinculo()->aplicar($d);
        };

        self::assertTrue($this->vinculo()->aplicar($documento));

        self::assertSame([false], $voltas, 'a volta não aplica: a primeira chamada está em andamento');
        self::assertCount(1, $this->naPasta);
        self::assertCount(2, $this->disco->chavesGravadas, 'o PDF final e uma cópia só');
    }

    #[TestDox('falha ao gravar a cópia na pasta: não aplica, fica pendente e a trilha diz o motivo')]
    public function testFalhaAoGravar(): void
    {
        $documento = $this->pendenteQueConcluiu();
        $this->aoGarantir = function (): void {
            $this->disco->falhaAoGravar = new \RuntimeException('disco cheio');
        };

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

    /** Vínculo definido pelo "Vincular à pasta" com o documento aguardando; depois todos assinam. */
    private function pendenteQueConcluiu(): DocumentoParaAssinar
    {
        $documento = $this->documentoEnviado();
        $this->pasta();
        $this->useCase()->executar(self::ator(), $this->tenant, 1, 42);
        foreach ($documento->getSignatarios() as $s) {
            $documento->assinar($s, $s->getNome(), self::CPF_ANA, 'rubrica.png', 'simples', null, null, new \DateTimeImmutable('2026-11-09 09:45:00'));
        }
        self::assertTrue($documento->isVinculoPendente());

        return $documento;
    }

    private function assertNaoAplicado(DocumentoParaAssinar $documento, string $motivo): void
    {
        self::assertTrue($documento->isVinculoPendente(), 'o vínculo continua pendente');
        self::assertNull($documento->getPastaDocumento());
        self::assertSame([], $this->naPasta, 'nada vai para a pasta');
        self::assertSame([], $this->historico);
        $evento = $this->ultimoGravado(TipoDeEvento::VinculoNaoAplicado);
        self::assertSame(['nup' => 'P-0042', 'motivo' => 'assinatura_eletronica.vinculo_motivo.' . $motivo, 'chave' => 1], $evento->getDados());
        self::assertSame($documento->getVinculadoPor(), $evento->getUsuario());
    }
}
