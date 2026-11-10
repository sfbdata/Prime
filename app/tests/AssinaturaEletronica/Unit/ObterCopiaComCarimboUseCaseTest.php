<?php

declare(strict_types=1);

namespace App\Tests\AssinaturaEletronica\Unit;

use App\AssinaturaEletronica\Armazenamento\ChavesDeAssinaturaEletronica;
use App\AssinaturaEletronica\DTO\CopiaComCarimbo;
use App\AssinaturaEletronica\DTO\DadosDoRascunho;
use App\AssinaturaEletronica\Entity\DocumentoParaAssinar;
use App\AssinaturaEletronica\Entity\Signatario;
use App\AssinaturaEletronica\Entity\VersaoDoPdf;
use App\AssinaturaEletronica\Exception\DocumentoNaoEncontrado;
use App\AssinaturaEletronica\Repository\DocumentoParaAssinarRepository;
use App\AssinaturaEletronica\Repository\VersaoDoPdfRepository;
use App\AssinaturaEletronica\Service\CarimboVisivel;
use App\AssinaturaEletronica\Service\ConferenciaDePdf\LimitesDaConferencia;
use App\AssinaturaEletronica\UseCase\ObterCopiaComCarimboUseCase;
use App\Entity\Tenant\Tenant;
use App\Shared\Armazenamento\Exception\FalhaDeArmazenamento;
use App\Tests\AssinaturaEletronica\Unit\ConferenciaDePdf\OficinaDePdfAssinado;
use App\Tests\Shared\Doubles\ArmazenamentoEmMemoria;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(ObterCopiaComCarimboUseCase::class)]
#[CoversClass(CopiaComCarimbo::class)]
final class ObterCopiaComCarimboUseCaseTest extends TestCase
{
    private const CODIGO = 'BJA-7K2M-9QXD-4HTR';
    private const CPF_ANA = '52998224725';
    private const SHA = 'cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc';

    private Tenant $tenant;
    private ArmazenamentoEmMemoria $disco;
    private DocumentoParaAssinarRepository $documentos;
    /** @var list<VersaoDoPdf> */
    private array $versoesNaBase = [];
    private ?DocumentoParaAssinar $naBase = null;

    protected function setUp(): void
    {
        $this->tenant = (new Tenant())->setName('Escritório do Carimbo');
        (new \ReflectionProperty(Tenant::class, 'id'))->setValue($this->tenant, 9);
        $this->disco = new ArmazenamentoEmMemoria();
        $this->documentos = $this->createMock(DocumentoParaAssinarRepository::class);
        $this->documentos->method('doEscritorioMesmoNaLixeira')->willReturnCallback(
            fn (int $id, Tenant $tenant): ?DocumentoParaAssinar => $this->naBase !== null && $this->naBase->getId() === $id && $this->naBase->getTenant() === $tenant ? $this->naBase : null,
        );
    }

    #[TestDox('carimba a última versão assinada (a devolvida, não o original nem o PDF para assinar) e nada é gravado')]
    public function testCarimbaAUltimaVersaoAssinada(): void
    {
        $documento = $this->documentoComOriginal(OficinaDePdfAssinado::pdfAssinado());
        $this->assinarAna($documento);
        $this->versao(VersaoDoPdf::paraAssinar($documento, 2, 'para.pdf', 'para.pdf', self::SHA, 1, ['v' => 1], new \DateTimeImmutable()), 'para assinar');
        $devolvida = OficinaDePdfAssinado::pdfAssinadoComVinculo(anterior: OficinaDePdfAssinado::pdfAssinado());
        $this->versao(VersaoDoPdf::devolvida($documento->getSignatarios()[1], 3, 'devolvida.pdf', 'devolvida.pdf', self::SHA, \strlen($devolvida), ['v' => 1], new \DateTimeImmutable()), $devolvida);

        $copia = $this->useCase()->executar($this->tenant, 1);

        self::assertSame(['Contrato de honorários - com carimbo.pdf', null, false, false], [$copia->nome, $copia->erro, $copia->semOriginal, $copia->semAssinatura]);
        self::assertStringStartsWith($devolvida, (string) $copia->pdf, 'o acréscimo é feito sobre a devolvida, intacta no começo');
        self::assertStringContainsString('/Name/BlueJus', substr((string) $copia->pdf, \strlen($devolvida)));
        self::assertStringContainsString(self::CODIGO, substr((string) $copia->pdf, \strlen($devolvida)));
        self::assertSame([ChavesDeAssinaturaEletronica::versao($this->versoesNaBase[2])->comoTexto()], $this->disco->lidas, 'só a devolvida é lida');
        self::assertSame([], $this->disco->gravadas, 'nada é gravado');
    }

    #[TestDox('sem devolvida, carimba o original que já chegou assinado')]
    public function testCarimbaOOriginal(): void
    {
        $original = OficinaDePdfAssinado::pdfAssinado();
        $documento = $this->documentoComOriginal($original);
        $this->assinarAna($documento);

        $copia = $this->useCase()->executar($this->tenant, 1);

        self::assertNull($copia->erro);
        self::assertStringStartsWith($original, (string) $copia->pdf);
        self::assertGreaterThan(\strlen($original), \strlen((string) $copia->pdf));
    }

    #[TestDox('nenhuma assinatura eletrônica do BlueJus: "sem assinatura" com o texto do desenho, antes de ler o arquivo')]
    public function testSemAssinaturaBlueJus(): void
    {
        $this->documentoComOriginal(OficinaDePdfAssinado::pdfAssinado());

        $copia = $this->useCase()->executar($this->tenant, 1);

        self::assertDesfecho(['erro' => 'Ainda não há assinatura eletrônica feita pelo BlueJus para carimbar.', 'semOriginal' => false, 'semAssinatura' => true], $copia);
        self::assertSame([], $this->disco->lidas, 'decidido pela lista de assinaturas, sem ler o arquivo nem chamar o carimbo');
    }

    #[TestDox('PDF que o motor recusa (cifrado): só o motivo, sem o " Use o original." que a tela acrescenta')]
    public function testRecusaDoMotor(): void
    {
        $documento = $this->documentoComOriginal(OficinaDePdfAssinado::pdfSemAssinatura('/Encrypt 9 0 R'));
        $this->assinarAna($documento);

        $copia = $this->useCase()->executar($this->tenant, 1);

        self::assertDesfecho(['erro' => 'Arquivo protegido por senha.', 'semOriginal' => false, 'semAssinatura' => false], $copia);
    }

    #[TestDox('versão acima do teto do motor (20 MB): lida só até um byte além dele e recusada como estrutura não reconhecida')]
    public function testAcimaDoTeto(): void
    {
        $documento = $this->documentoComOriginal(str_repeat('%', LimitesDaConferencia::PDF_MAXIMO_EM_BYTES + 1024));
        $this->assinarAna($documento);

        $copia = $this->useCase()->executar($this->tenant, 1);

        self::assertDesfecho(['erro' => 'Estrutura do PDF não reconhecida.', 'semOriginal' => false, 'semAssinatura' => false], $copia);
    }

    #[TestDox('documento de outro escritório ou inexistente: não encontrado, sem ler arquivo')]
    public function testOutroEscritorio(): void
    {
        $this->documentoComOriginal(OficinaDePdfAssinado::pdfAssinado());
        $outro = (new Tenant())->setName('Outro escritório');
        (new \ReflectionProperty(Tenant::class, 'id'))->setValue($outro, 10);

        $this->assertNaoEncontrado(fn () => $this->useCase()->executar($outro, 1));
        $this->assertNaoEncontrado(fn () => $this->useCase()->executar($this->tenant, 2));
        self::assertSame([], $this->disco->lidas);
    }

    #[TestDox('sem versão guardada ou só com o PDF para assinar: "sem original", mesmo sem assinatura BlueJus')]
    public function testSemVersaoAssinada(): void
    {
        $documento = $this->rascunho();
        $semOriginal = ['erro' => 'Original não encontrado.', 'semOriginal' => true, 'semAssinatura' => false];
        self::assertDesfecho($semOriginal, $this->useCase()->executar($this->tenant, 1));

        $documento->enviar(str_repeat('f', 64), new \DateTimeImmutable());
        $documento->definirCodigo(self::CODIGO);
        $this->assinarAna($documento);
        $this->versao(VersaoDoPdf::paraAssinar($documento, 1, 'para.pdf', 'para.pdf', self::SHA, 1, ['v' => 1], new \DateTimeImmutable()), OficinaDePdfAssinado::pdfParaAssinar());
        self::assertDesfecho($semOriginal, $this->useCase()->executar($this->tenant, 1));
        self::assertSame([], $this->disco->lidas);
    }

    #[TestDox('versão guardada sem o arquivo: "sem original" (D10)')]
    public function testArquivoAusente(): void
    {
        $documento = $this->rascunho();
        $this->versoesNaBase[] = VersaoDoPdf::original($documento, 1, 'sumiu.pdf', 'Contrato.pdf', self::SHA, 1, ['v' => 1], new \DateTimeImmutable());
        $documento->enviar(str_repeat('f', 64), new \DateTimeImmutable());
        $documento->definirCodigo(self::CODIGO);
        $this->assinarAna($documento);

        $copia = $this->useCase()->executar($this->tenant, 1);

        self::assertDesfecho(['erro' => 'Original não encontrado.', 'semOriginal' => true, 'semAssinatura' => false], $copia);
    }

    #[TestDox('pane do armazenamento não vira 404: sobe (D10)')]
    public function testPaneDoArmazenamento(): void
    {
        $this->assinarAna($this->documentoComOriginal(OficinaDePdfAssinado::pdfAssinado()));
        $this->disco->falhaAoAbrir = new FalhaDeArmazenamento('disco fora');

        $this->expectExceptionObject(new FalhaDeArmazenamento('disco fora'));
        $this->useCase()->executar($this->tenant, 1);
    }

    /**
     * O desfecho inteiro de quem não foi carimbado: sem nome, sem PDF, e o texto e as marcas dadas.
     *
     * @param array{erro: string, semOriginal: bool, semAssinatura: bool} $esperado
     */
    private static function assertDesfecho(array $esperado, CopiaComCarimbo $copia): void
    {
        self::assertSame(['nome' => null, 'pdf' => null] + $esperado, get_object_vars($copia));
    }

    private function useCase(): ObterCopiaComCarimboUseCase
    {
        $versoes = $this->createMock(VersaoDoPdfRepository::class);
        $versoes->method('doDocumento')->willReturnCallback(fn (DocumentoParaAssinar $documento): array => array_values(array_filter(
            $this->versoesNaBase,
            static fn (VersaoDoPdf $versao): bool => $versao->getDocumento() === $documento,
        )));

        return new ObterCopiaComCarimboUseCase($this->documentos, $versoes, $this->disco, new CarimboVisivel());
    }

    /** Rascunho (id 1) com Ana (Simples) e Bia (gov.br). */
    private function rascunho(): DocumentoParaAssinar
    {
        $documento = new DocumentoParaAssinar($this->tenant, null, new \DateTimeImmutable());
        $documento->aplicarRascunho(DadosDoRascunho::deJson([
            'nome' => 'Contrato de honorários',
            'signatarios' => [
                ['nome' => 'Ana Lima', 'email' => 'ana@exemplo.com', 'cpf' => self::CPF_ANA],
                ['nome' => 'Bia Souza', 'email' => 'bia@exemplo.com', 'nivel' => 'govbr'],
            ],
        ]), [], new \DateTimeImmutable());
        (new \ReflectionProperty(DocumentoParaAssinar::class, 'id'))->setValue($documento, 1);
        foreach ($documento->getSignatarios() as $i => $signatario) {
            (new \ReflectionProperty(Signatario::class, 'id'))->setValue($signatario, 11 + $i);
        }
        $this->naBase = $documento;

        return $documento;
    }

    /** O rascunho com o original guardado (ordem 1), depois enviado e com o código de validação. */
    private function documentoComOriginal(string $pdf): DocumentoParaAssinar
    {
        $documento = $this->rascunho();
        $this->versao(VersaoDoPdf::original($documento, 1, 'original.pdf', 'Contrato assinado.pdf', self::SHA, \strlen($pdf), ['v' => 1], new \DateTimeImmutable()), $pdf);
        $documento->enviar(str_repeat('f', 64), new \DateTimeImmutable());
        $documento->definirCodigo(self::CODIGO);

        return $documento;
    }

    private function assinarAna(DocumentoParaAssinar $documento): void
    {
        $documento->assinar($documento->getSignatarios()[0], 'Ana Maria Lima', self::CPF_ANA, 'rubrica.png', 'Assinatura desenhada', null, null, new \DateTimeImmutable());
    }

    private function versao(VersaoDoPdf $versao, string $conteudo): void
    {
        $this->versoesNaBase[] = $versao;
        $this->disco->semear(ChavesDeAssinaturaEletronica::versao($versao), $conteudo);
    }

    private function assertNaoEncontrado(\Closure $acao): void
    {
        try {
            $acao();
            self::fail('esperava DocumentoNaoEncontrado');
        } catch (DocumentoNaoEncontrado) {
            $this->addToAssertionCount(1);
        }
    }
}
