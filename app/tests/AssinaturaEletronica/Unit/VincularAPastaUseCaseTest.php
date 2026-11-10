<?php

declare(strict_types=1);

namespace App\Tests\AssinaturaEletronica\Unit;

use App\AssinaturaEletronica\Enum\TipoDeEvento;
use App\AssinaturaEletronica\Exception\AcaoRecusada;
use App\AssinaturaEletronica\Exception\DocumentoNaoEncontrado;
use App\AssinaturaEletronica\Exception\PastaRecusadaNoVinculo;
use App\AssinaturaEletronica\UseCase\VincularAPastaUseCase;
use App\Entity\Tenant\Tenant;
use App\Pasta\Enum\OrigemDaAssinatura;
use App\Pasta\Entity\PastaDocumento;
use App\Tests\AssinaturaEletronica\Support\OficinaDoVinculo;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * "Vincular à pasta" (spec assinatura-documentos-etapa-3, E3-10 e E3-11): a pasta pelo id, do escritório, fora da
 * lápide e que quem vincula edita; aguardando fica pendente, concluído vai na hora.
 */
#[CoversClass(VincularAPastaUseCase::class)]
final class VincularAPastaUseCaseTest extends TestCase
{
    use OficinaDoVinculo;

    protected function setUp(): void
    {
        $this->montarVinculo();
    }

    #[TestDox('aguardando: o vínculo fica pendente com quem vinculou, a trilha diz "ao concluir" e nada vai para a pasta')]
    public function testAguardandoFicaPendente(): void
    {
        $documento = $this->documentoEnviado();
        $pasta = $this->pasta();

        $saida = $this->useCase()->executar(self::ator(), $this->tenant, 1, 42);

        self::assertFalse($saida['aplicado']);
        self::assertSame($pasta, $documento->getPasta());
        self::assertTrue($documento->isVinculoPendente());
        self::assertSame(7, $documento->getVinculadoPor()?->getId());
        self::assertSame(['vinculo_definido'], $this->tiposGravados());
        self::assertSame(['nup' => 'P-0042', 'chave' => 1], $this->ultimoGravado(TipoDeEvento::VinculoDefinido)->getDados());
        self::assertSame([], $this->naPasta);
        self::assertSame(0, $this->pdfsGarantidos, 'o PDF final só é pedido no concluído');
        self::assertTrue($saida['documento']['vinculado']);
    }

    #[TestDox('concluído: vai na hora, um evento só ("vinculado à pasta"), o arquivo é cópia do PDF final e entra assinado')]
    public function testConcluidoAplicaNaHora(): void
    {
        $documento = $this->concluido();
        $this->pasta();

        $saida = $this->useCase()->executar(self::ator(), $this->tenant, 1, 42);

        self::assertTrue($saida['aplicado']);
        self::assertSame(['vinculo_feito'], $this->tiposGravados(), 'sem "ao concluir" no documento já concluído (desenho, vincExec)');
        self::assertFalse($documento->isVinculoPendente());
        self::assertCount(1, $this->naPasta);
        $arquivo = $this->naPasta[0];
        self::assertSame($arquivo, $documento->getPastaDocumento());
        self::assertSame('assinado_Contrato_de_honorarios.pdf', $arquivo->getNomeOriginal());
        self::assertSame(PastaDocumento::CATEGORIA_DEMAIS, $arquivo->getCategoria(), 'sem modelo: Demais');
        self::assertSame(OrigemDaAssinatura::Eletronica, $arquivo->getAssinaturaOrigem());
        self::assertSame(1, preg_match('/^DOC-[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}$/', (string) $arquivo->getArquivo()?->getCodigo()));
        self::assertSame(self::PDF_FINAL, $this->disco->ler(\App\Pasta\Armazenamento\ChavesDePasta::documento($arquivo)));
        self::assertSame(['assinatura: Assinatura: assinatura_eletronica_tela.js.vinculo.historico'], $this->textosDoHistorico());
    }

    #[TestDox('pasta de outro escritório ou inexistente: não encontrada, sem dizer qual, e nada é gravado')]
    public function testPastaDeOutroEscritorio(): void
    {
        $documento = $this->documentoEnviado();
        $this->pasta()->setTenant((new Tenant())->setName('Outro'));

        foreach ([42, 99] as $id) {
            try {
                $this->useCase()->executar(self::ator(), $this->tenant, 1, $id);
                self::fail('deveria recusar');
            } catch (PastaRecusadaNoVinculo $e) {
                self::assertSame(404, $e->status);
                self::assertSame('assinatura_eletronica.erro.vinculo_pasta_nao_encontrada', $e->getMessage());
            }
        }
        self::assertNull($documento->getPasta());
        self::assertSame([], $this->gravados);
    }

    #[TestDox('pasta que a pessoa nem vê (cadeado): 404; vê e não edita (só visualizar, ou sem permissão): 403')]
    public function testSemPermissaoNaPasta(): void
    {
        $documento = $this->documentoEnviado();
        $this->pasta(42, ver: false, editar: false);
        $this->pasta(43, 'P-0043', ver: true, editar: false);

        $status = [];
        foreach ([42, 43] as $id) {
            try {
                $this->useCase()->executar(self::ator(), $this->tenant, 1, $id);
            } catch (PastaRecusadaNoVinculo $e) {
                $status[] = $e->status;
            }
        }

        self::assertSame([404, 403], $status);
        self::assertNull($documento->getPasta());
        self::assertSame([], $this->gravados);
    }

    #[TestDox('pasta na lápide: recusada, mesmo para quem edita')]
    public function testPastaNaLapide(): void
    {
        $documento = $this->documentoEnviado();
        $this->pasta()->marcarExcluida(self::ator(), new \DateTimeImmutable('2026-11-08'));

        $this->expectExceptionObject(new AcaoRecusada('assinatura_eletronica.erro.vinculo_pasta_excluida'));
        try {
            $this->useCase()->executar(self::ator(), $this->tenant, 1, 42);
        } finally {
            self::assertNull($documento->getPasta());
        }
    }

    #[TestDox('rascunho, Lixeira e documento já vinculado: a entidade recusa e nada muda')]
    public function testRecusasDaEntidade(): void
    {
        $this->rascunho();
        $this->pasta();
        $this->assertRecusa('assinatura_eletronica.erro.vinculo_fora_do_envio');

        $this->naBase = [];
        $na = $this->documentoEnviado();
        $na->moverParaLixeira(self::ator(), new \DateTimeImmutable('2026-11-09 09:30:00'));
        $this->assertRecusa('assinatura_eletronica.erro.vinculo_fora_do_envio');

        $this->naBase = [];
        $this->documentoEnviado();
        $this->useCase()->executar(self::ator(), $this->tenant, 1, 42);
        $this->assertRecusa('assinatura_eletronica.erro.vinculo_ja_definido');
    }

    #[TestDox('documento de outro escritório: não encontrado')]
    public function testDocumentoDeOutroEscritorio(): void
    {
        $this->documentoEnviado();
        $this->pasta();

        $this->expectException(DocumentoNaoEncontrado::class);
        $this->useCase()->executar(self::ator(), (new Tenant())->setName('Outro'), 1, 42);
    }

    #[TestDox('concluído com a aplicação recusada (PDF final indisponível): o vínculo fica gravado e pendente, sem arquivo')]
    public function testConcluidoSemPdf(): void
    {
        $documento = $this->concluido();
        $this->pasta();
        $this->pdfFalha = true;

        $saida = $this->useCase()->executar(self::ator(), $this->tenant, 1, 42);

        self::assertFalse($saida['aplicado']);
        self::assertTrue($documento->isVinculoPendente());
        self::assertSame(['vinculo_nao_aplicado'], $this->tiposGravados());
        self::assertSame(
            ['nup' => 'P-0042', 'motivo' => 'assinatura_eletronica.vinculo_motivo.pdf_indisponivel', 'chave' => 1],
            $this->ultimoGravado(TipoDeEvento::VinculoNaoAplicado)->getDados(),
        );
        self::assertSame([], $this->naPasta);
    }

    private function assertRecusa(string $chave): void
    {
        $antes = \count($this->gravados);
        try {
            $this->useCase()->executar(self::ator(), $this->tenant, 1, 42);
            self::fail('deveria recusar: ' . $chave);
        } catch (AcaoRecusada $e) {
            self::assertSame($chave, $e->getMessage());
        }
        self::assertCount($antes, $this->gravados);
    }
}
