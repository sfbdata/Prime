<?php

declare(strict_types=1);

namespace App\Tests\AssinaturaEletronica\Unit;

use App\AssinaturaEletronica\DTO\DadosDoRascunho;
use App\AssinaturaEletronica\Entity\DocumentoParaAssinar;
use App\AssinaturaEletronica\Enum\SituacaoDoDocumento;
use App\AssinaturaEletronica\Exception\AcaoRecusada;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaDocumento;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * O que a etapa 3 acrescenta ao documento (contrato da etapa 3, seção 2, item 2): o código de validação e o PDF final,
 * uma vez só, e o vínculo à pasta, que fica pendente até o arquivo ir para a pasta.
 */
#[CoversClass(DocumentoParaAssinar::class)]
final class SeloEVinculoDoDocumentoTest extends TestCase
{
    private const CODIGO = 'BJA-AB23-CD45-EF67';

    private Tenant $tenant;

    protected function setUp(): void
    {
        $this->tenant = self::comId(new Tenant(), 9);
    }

    #[TestDox('rascunho nasce sem código, sem PDF final e sem vínculo')]
    public function testRascunhoSemNada(): void
    {
        $documento = $this->rascunho();

        self::assertNull($documento->getCodigo());
        self::assertNull($documento->getArquivoFinalChave());
        self::assertNull($documento->getHashFinal());
        self::assertNull($documento->getPasta());
        self::assertFalse($documento->isVinculoPendente());
        self::assertNull($documento->getVinculadoPor());
        self::assertNull($documento->getVinculadoEm());
        self::assertNull($documento->getPastaDocumento());
    }

    #[TestDox('código: definido uma vez, só no documento enviado')]
    public function testCodigoUmaVez(): void
    {
        $rascunho = $this->rascunho();
        self::assertLogica('Código de validação de documento não enviado.', static fn () => $rascunho->definirCodigo(self::CODIGO));

        $documento = $this->enviado();
        $documento->definirCodigo(self::CODIGO);
        self::assertSame(self::CODIGO, $documento->getCodigo());

        self::assertLogica('O documento já tem código de validação.', static fn () => $documento->definirCodigo('BJA-ZZZZ-ZZZZ-ZZZZ'));
        self::assertSame(self::CODIGO, $documento->getCodigo(), 'o código impresso não muda');
    }

    #[TestDox('a cópia do Duplicar não leva código, PDF final nem vínculo')]
    public function testCopiaSemSeloNemVinculo(): void
    {
        $documento = $this->concluido();
        $documento->definirCodigo(self::CODIGO);
        $documento->guardarArquivoFinal('final.pdf', str_repeat('a', 64));
        $documento->definirVinculo($this->pasta(), new User(), new \DateTimeImmutable());

        $copia = DocumentoParaAssinar::copiaDe($documento, 'Cópia', null, new \DateTimeImmutable());

        self::assertNull($copia->getCodigo());
        self::assertNull($copia->getArquivoFinalChave());
        self::assertNull($copia->getPasta());
        self::assertFalse($copia->isVinculoPendente());
    }

    #[TestDox('PDF final: só do concluído, uma vez, com o sha256 em 64 hexadecimais')]
    public function testArquivoFinal(): void
    {
        $aguardando = $this->enviado();
        self::assertLogica('Só o documento concluído guarda o PDF final.', static fn () => $aguardando->guardarArquivoFinal('final.pdf', str_repeat('a', 64)));

        $documento = $this->concluido();
        self::assertLogica('PDF final sem chave ou com sha256 fora do formato.', static fn () => $documento->guardarArquivoFinal('final.pdf', str_repeat('A', 64)));
        self::assertLogica('PDF final sem chave ou com sha256 fora do formato.', static fn () => $documento->guardarArquivoFinal('', str_repeat('a', 64)));

        $documento->guardarArquivoFinal('final.pdf', str_repeat('b', 64));
        self::assertSame('final.pdf', $documento->getArquivoFinalChave());
        self::assertSame(str_repeat('b', 64), $documento->getHashFinal());

        self::assertLogica('O PDF final do documento já foi guardado.', static fn () => $documento->guardarArquivoFinal('outro.pdf', str_repeat('c', 64)));
        self::assertSame('final.pdf', $documento->getArquivoFinalChave());
    }

    #[TestDox('vínculo: grava a pasta, quem vinculou e quando (em segundos), e fica pendente')]
    public function testDefinirVinculo(): void
    {
        $documento = $this->enviado();
        $pasta = $this->pasta();
        $quem = new User();

        $documento->definirVinculo($pasta, $quem, new \DateTimeImmutable('2026-11-09 10:00:00.750000'));

        self::assertSame($pasta, $documento->getPasta());
        self::assertSame($quem, $documento->getVinculadoPor());
        self::assertSame('2026-11-09 10:00:00.000000', $documento->getVinculadoEm()?->format('Y-m-d H:i:s.u'));
        self::assertTrue($documento->isVinculoPendente());
        self::assertNull($documento->getPastaDocumento());
    }

    #[TestDox('vínculo: um só por documento; o segundo é recusado com mensagem e o primeiro fica')]
    public function testVinculoUmaVez(): void
    {
        $documento = $this->enviado();
        $primeira = $this->pasta();
        $documento->definirVinculo($primeira, new User(), new \DateTimeImmutable());

        try {
            $documento->definirVinculo($this->pasta(2), new User(), new \DateTimeImmutable());
            self::fail('deveria recusar');
        } catch (AcaoRecusada $e) {
            self::assertSame('assinatura_eletronica.erro.vinculo_ja_definido', $e->getMessage());
        }
        self::assertSame($primeira, $documento->getPasta());
    }

    #[TestDox('vínculo: só documento aguardando ou concluído, fora da Lixeira (E3-10)')]
    public function testVinculoSoNoEnvioForaDaLixeira(): void
    {
        $naLixeira = $this->concluido();
        $naLixeira->moverParaLixeira(null, new \DateTimeImmutable('2026-11-09 10:00:00'));

        foreach (['rascunho' => $this->rascunho(), 'na Lixeira' => $naLixeira] as $caso => $documento) {
            try {
                $documento->definirVinculo($this->pasta(), new User(), new \DateTimeImmutable());
                self::fail('deveria recusar: ' . $caso);
            } catch (AcaoRecusada $e) {
                self::assertSame('assinatura_eletronica.erro.vinculo_fora_do_envio', $e->getMessage(), $caso);
            }
            self::assertNull($documento->getPasta(), $caso);
            self::assertFalse($documento->isVinculoPendente(), $caso);
        }
    }

    #[TestDox('vínculo: pasta de outro escritório nunca entra')]
    public function testVinculoDeOutroEscritorio(): void
    {
        $documento = $this->enviado();
        $deOutro = (new Pasta())->setTenant(self::comId(new Tenant(), 10));
        self::assertLogica('A pasta do vínculo é de outro escritório.', static fn () => $documento->definirVinculo($deOutro, new User(), new \DateTimeImmutable()));
        $semEscritorio = new Pasta();
        self::assertLogica('A pasta do vínculo é de outro escritório.', static fn () => $documento->definirVinculo($semEscritorio, new User(), new \DateTimeImmutable()));

        self::assertNull($documento->getPasta());
        self::assertFalse($documento->isVinculoPendente());
    }

    #[TestDox('vínculo feito: guarda o arquivo da pasta e desliga o pendente; sem pendente, recusa')]
    public function testMarcarVinculoFeito(): void
    {
        $documento = $this->concluido();
        $arquivoSemVinculo = $this->arquivoDa($this->pasta());
        self::assertLogica('O documento não tem vínculo pendente.', static fn () => $documento->marcarVinculoFeito($arquivoSemVinculo));

        $pasta = $this->pasta();
        $documento->definirVinculo($pasta, new User(), new \DateTimeImmutable());
        $arquivo = $this->arquivoDa($pasta);
        $documento->marcarVinculoFeito($arquivo);

        self::assertSame($arquivo, $documento->getPastaDocumento());
        self::assertFalse($documento->isVinculoPendente());
        self::assertSame($pasta, $documento->getPasta(), 'a pasta continua no documento');
        self::assertLogica('O documento não tem vínculo pendente.', static fn () => $documento->marcarVinculoFeito($arquivo));
    }

    #[TestDox('vínculo feito: arquivo de outra pasta ou de outro escritório é recusado e o vínculo segue pendente')]
    public function testVinculoFeitoComArquivoErrado(): void
    {
        $documento = $this->concluido();
        $documento->definirVinculo($this->pasta(1), new User(), new \DateTimeImmutable());

        $deOutraPasta = $this->arquivoDa($this->pasta(2));
        self::assertLogica('O arquivo não é da pasta do vínculo.', static fn () => $documento->marcarVinculoFeito($deOutraPasta));
        $deOutroEscritorio = $this->arquivoDa($this->pasta(1))->setTenant(self::comId(new Tenant(), 10));
        self::assertLogica('O arquivo não é da pasta do vínculo.', static fn () => $documento->marcarVinculoFeito($deOutroEscritorio));

        self::assertTrue($documento->isVinculoPendente());
        self::assertNull($documento->getPastaDocumento());
    }

    #[TestDox('vínculo não aplicado: o pendente se desfaz (pasta, quem e quando) e o documento pode ser vinculado de novo')]
    public function testDescartarVinculoNaoAplicado(): void
    {
        $documento = $this->concluido();
        $documento->definirVinculo($this->pasta(1), new User(), new \DateTimeImmutable('2026-11-09 10:00:00'));

        $documento->descartarVinculoNaoAplicado();

        self::assertNull($documento->getPasta());
        self::assertNull($documento->getVinculadoPor());
        self::assertNull($documento->getVinculadoEm());
        self::assertFalse($documento->isVinculoPendente());
        self::assertNull($documento->getPastaDocumento());

        $outra = $this->pasta(2);
        $documento->definirVinculo($outra, new User(), new \DateTimeImmutable('2026-11-09 10:05:00'));
        self::assertSame($outra, $documento->getPasta());
        self::assertTrue($documento->isVinculoPendente());
    }

    #[TestDox('vínculo não aplicado: sem vínculo, ou com o vínculo já feito, recusa e nada muda (sem "desvincular")')]
    public function testDescartarSoOPendente(): void
    {
        $semVinculo = $this->concluido();
        self::assertLogica('O documento não tem vínculo pendente.', static fn () => $semVinculo->descartarVinculoNaoAplicado());

        $feito = $this->concluido();
        $pasta = $this->pasta();
        $feito->definirVinculo($pasta, new User(), new \DateTimeImmutable());
        $arquivo = $this->arquivoDa($pasta);
        $feito->marcarVinculoFeito($arquivo);
        self::assertLogica('O documento não tem vínculo pendente.', static fn () => $feito->descartarVinculoNaoAplicado());

        self::assertSame($pasta, $feito->getPasta());
        self::assertSame($arquivo, $feito->getPastaDocumento());
        self::assertNotNull($feito->getVinculadoPor());
    }

    private function rascunho(): DocumentoParaAssinar
    {
        $documento = new DocumentoParaAssinar($this->tenant, null, new \DateTimeImmutable('2026-11-09 09:00:00'));
        $documento->aplicarRascunho(DadosDoRascunho::deJson(['nome' => 'Contrato', 'signatarios' => [['nome' => 'Ana Lima', 'email' => 'ana@exemplo.com']]]), [], new \DateTimeImmutable('2026-11-09 09:00:00'));

        return $documento;
    }

    private function enviado(): DocumentoParaAssinar
    {
        $documento = $this->rascunho();
        $documento->enviar(str_repeat('f', 64), new \DateTimeImmutable('2026-11-09 09:30:00'));

        return $documento;
    }

    private function concluido(): DocumentoParaAssinar
    {
        $documento = $this->enviado();
        $documento->assinar($documento->getSignatarios()[0], 'Ana Lima', '52998224725', 'rubrica.png', 'simples', null, null, new \DateTimeImmutable('2026-11-09 09:45:00'));
        self::assertSame(SituacaoDoDocumento::Concluido, $documento->getSituacao());

        return $documento;
    }

    /** Uma pasta do escritório do documento, com o id dado (o mesmo id vale como a mesma pasta). */
    private function pasta(int $id = 1): Pasta
    {
        return self::comId((new Pasta())->setTenant($this->tenant), $id);
    }

    private function arquivoDa(Pasta $pasta): PastaDocumento
    {
        return (new PastaDocumento())->setTenant($this->tenant)->setPasta($pasta);
    }

    /**
     * @template T of object
     *
     * @param T $objeto
     *
     * @return T
     */
    private static function comId(object $objeto, int $id): object
    {
        (new \ReflectionProperty($objeto, 'id'))->setValue($objeto, $id);

        return $objeto;
    }

    private static function assertLogica(string $mensagem, \Closure $acao): void
    {
        try {
            $acao();
            self::fail('deveria recusar: ' . $mensagem);
        } catch (\LogicException $e) {
            self::assertSame($mensagem, $e->getMessage());
        }
    }
}
