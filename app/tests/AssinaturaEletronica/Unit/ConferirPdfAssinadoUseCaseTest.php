<?php

declare(strict_types=1);

namespace App\Tests\AssinaturaEletronica\Unit;

use App\AssinaturaEletronica\Armazenamento\ChavesDeAssinaturaEletronica;
use App\AssinaturaEletronica\DTO\DadosDoRascunho;
use App\AssinaturaEletronica\DTO\PdfAssinadoConferido;
use App\AssinaturaEletronica\Entity\DocumentoParaAssinar;
use App\AssinaturaEletronica\Entity\Signatario;
use App\AssinaturaEletronica\Entity\VersaoDoPdf;
use App\AssinaturaEletronica\Exception\AcaoRecusada;
use App\AssinaturaEletronica\Exception\LinkInvalido;
use App\AssinaturaEletronica\Repository\VersaoDoPdfRepository;
use App\AssinaturaEletronica\Service\Cadeia\RegraDaCadeia;
use App\AssinaturaEletronica\Service\ChaveDaAssinatura;
use App\AssinaturaEletronica\Service\ConferenciaDePdf\ConferenciaDePdf;
use App\AssinaturaEletronica\Service\ConferenciaDePdf\LimitesDaConferencia;
use App\AssinaturaEletronica\Service\ConferenciaDoPdfAssinado;
use App\AssinaturaEletronica\Service\ResultadoDoPdfAssinado;
use App\AssinaturaEletronica\UseCase\ConferirPdfAssinadoUseCase;
use App\Tests\AssinaturaEletronica\Support\OficinaDosUseCasesDaAssinatura;
use App\Tests\AssinaturaEletronica\Support\ValidadorDeCadeiaContado;
use App\Tests\AssinaturaEletronica\Unit\ConferenciaDePdf\OficinaDePdfAssinado;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * A pré-conferência do PDF assinado da tela pública (etapa 5, bloco 5C) com o repositório dos documentos dublado
 * (a oficina dos UseCases) e o motor, a regra e a conferência de verdade: só gov.br e ICP, na vez, com o validador
 * configurado, PDF de até 20 MB e o CPF cadastrado. Nada é gravado (versão, trilha, arquivo, trava) e o validador de
 * cadeia nunca é chamado.
 */
#[CoversClass(ConferirPdfAssinadoUseCase::class)]
#[CoversClass(PdfAssinadoConferido::class)]
final class ConferirPdfAssinadoUseCaseTest extends TestCase
{
    use OficinaDosUseCasesDaAssinatura;

    private const SHA = 'cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc';

    private ValidadorDeCadeiaContado $validador;
    private VersaoDoPdfRepository $versoes;
    /** @var list<VersaoDoPdf> */
    private array $versoesNaBase = [];

    protected function setUp(): void
    {
        $this->montarOficina();
        $this->validador = new ValidadorDeCadeiaContado();
        $this->versoes = $this->createMock(VersaoDoPdfRepository::class);
        $this->versoes->method('ultima')->willReturnCallback(function (DocumentoParaAssinar $documento): ?VersaoDoPdf {
            $doDocumento = array_filter($this->versoesNaBase, static fn (VersaoDoPdf $v): bool => $v->getDocumento() === $documento);

            return array_pop($doDocumento);
        });
        $this->versoes->expects(self::never())->method('salvar');
        $this->versoes->expects(self::never())->method('remover');
        $this->aoTravar = static fn (): never => self::fail('a pré-conferência não trava o documento');
    }

    #[TestDox('gov.br na vez: o número de assinaturas do arquivo, o nome e as anteriores preservadas; nada gravado e o validador não é chamado')]
    public function testConfere(): void
    {
        [$bia, $base] = $this->enviadoComGovbr();

        $conferido = $this->useCase()->executar($this->chave->token($bia), $this->upload('Contrato assinado.pdf', OficinaDePdfAssinado::pdfAssinadoComVinculo('ec', null, $base)));

        self::assertSame(['n' => 2, 'arquivo' => 'Contrato assinado.pdf', 'preservadas' => true], $conferido->paraJson());
        self::assertSame(0, $this->validador->conferencias, 'o validador de cadeia não é chamado');
        self::assertSame([], $this->gravados, 'nenhum evento na trilha');
        self::assertSame([], $this->disco->gravadas, 'nenhum arquivo gravado');
        self::assertTrue($bia->estaPendente());
        self::assertNull($bia->getAssinadoEm());
    }

    #[TestDox('o nome do arquivo vem do navegador: sem caractere de controle e em UTF-8 válido (a pasta o UploadedFile já tira)')]
    public function testNomeDoArquivo(): void
    {
        [$bia, $base] = $this->enviadoComGovbr();

        $conferido = $this->useCase()->executar($this->chave->token($bia), $this->upload("pasta/Contrato\x01 assinado\xFF.pdf", OficinaDePdfAssinado::pdfAssinadoComVinculo('ec', null, $base)));

        self::assertSame('Contrato assinado?.pdf', $conferido->arquivo);
    }

    #[TestDox('signatário Simples: link inválido, sem consultar o validador nem conferir o arquivo')]
    public function testSimplesNaoConfere(): void
    {
        $this->enviadoComGovbr();
        $ana = $this->naBase[0]->getSignatarios()[0];

        $this->expectException(LinkInvalido::class);
        try {
            $this->useCase()->executar($this->chave->token($ana), $this->upload('a.pdf', OficinaDePdfAssinado::pdfAssinado()));
        } finally {
            self::assertSame([], $this->disco->lidas, 'a versão base não foi lida');
        }
    }

    #[TestDox('token desconhecido: link inválido')]
    public function testTokenDesconhecido(): void
    {
        $this->enviadoComGovbr();

        $this->expectException(LinkInvalido::class);
        $this->useCase()->executar(str_repeat('b', 64), $this->upload('a.pdf', OficinaDePdfAssinado::pdfAssinado()));
    }

    #[TestDox('fora da vez (em ordem, aguardando o anterior): a mesma recusa da Simples')]
    public function testForaDaVez(): void
    {
        [$bia, $base] = $this->enviadoComGovbr(emOrdem: true);

        $this->assertRecusa('assinatura_eletronica.publica.estado.aguardando_anterior', $bia, OficinaDePdfAssinado::pdfAssinadoComVinculo('ec', null, $base));
    }

    #[TestDox('validador de cadeia não configurado: "cadeia_indisponivel" antes de ler o arquivo')]
    public function testValidadorNaoConfigurado(): void
    {
        [$bia, $base] = $this->enviadoComGovbr();
        $this->validador = new ValidadorDeCadeiaContado(configurado: false);

        $this->assertRecusa(ResultadoDoPdfAssinado::chave(ResultadoDoPdfAssinado::CADEIA_INDISPONIVEL), $bia, OficinaDePdfAssinado::pdfAssinadoComVinculo('ec', null, $base));
        self::assertSame([], $this->disco->lidas);
    }

    #[TestDox('arquivo que não é PDF ou que passa de 20 MB: "ilegivel", sem rodar o motor')]
    public function testArquivoForaDoTeto(): void
    {
        [$bia] = $this->enviadoComGovbr();

        $this->assertRecusa(ResultadoDoPdfAssinado::chave(ResultadoDoPdfAssinado::ILEGIVEL), $bia, 'só texto');
        $this->assertRecusa(ResultadoDoPdfAssinado::chave(ResultadoDoPdfAssinado::ILEGIVEL), $bia, $this->upload('grande.pdf', "%PDF-1.7\n", LimitesDaConferencia::PDF_MAXIMO_EM_BYTES + 1));
        self::assertSame([], $this->disco->lidas);
    }

    #[TestDox('sem versão guardada do documento: "prefixo"')]
    public function testSemBase(): void
    {
        [$bia] = $this->enviadoComGovbr();
        $this->versoesNaBase = [];

        $this->assertRecusa(ResultadoDoPdfAssinado::chave(ResultadoDoPdfAssinado::PREFIXO), $bia, OficinaDePdfAssinado::pdfAssinadoComVinculo());
    }

    #[TestDox('PDF que não começa pela versão base: "prefixo"')]
    public function testPrefixoNaoConfere(): void
    {
        [$bia] = $this->enviadoComGovbr();

        $this->assertRecusa(ResultadoDoPdfAssinado::chave(ResultadoDoPdfAssinado::PREFIXO), $bia, OficinaDePdfAssinado::pdfAssinadoComVinculo('ec', null, OficinaDePdfAssinado::pdfAssinado('rsa2')));
    }

    #[TestDox('acréscimo que muda a página: "alterada"')]
    public function testAcrescimoQueMudaOConteudo(): void
    {
        [$bia, $base] = $this->enviadoComGovbr();
        $muda = OficinaDePdfAssinado::assinar(
            OficinaDePdfAssinado::acrescimoParaAssinar($base, trocas: [3 => '<</Type/Page/Parent 2 0 R/MediaBox[0 0 600 842]/Annots[4 0 R 6 0 R]>>']),
            OficinaDePdfAssinado::cmsDoOpenssl(OficinaDePdfAssinado::chave('ec')),
        );

        $this->assertRecusa(ResultadoDoPdfAssinado::chave(ResultadoDoPdfAssinado::ALTERADA), $bia, $muda);
    }

    #[TestDox('o CPF cadastrado é o esperado: certificado sem CPF "sem_cpf"; CPF de outro "cpf_diferente"')]
    public function testCpfCadastrado(): void
    {
        [$bia, $base] = $this->enviadoComGovbr();
        $semCpf = OficinaDePdfAssinado::assinar(
            OficinaDePdfAssinado::acrescimoParaAssinar($base),
            OficinaDePdfAssinado::cmsDoOpenssl(OficinaDePdfAssinado::chave('ec'), OficinaDePdfAssinado::certificado(OficinaDePdfAssinado::chave('ec'), 'SEM DOCUMENTO')),
        );
        $deOutro = OficinaDePdfAssinado::pdfAssinadoComVinculo('ec', OficinaDePdfAssinado::certificado(OficinaDePdfAssinado::chave('ec'), 'BELTRANA:' . OficinaDePdfAssinado::CPF_2), $base);

        $this->assertRecusa(ResultadoDoPdfAssinado::chave(ResultadoDoPdfAssinado::SEM_CPF), $bia, $semCpf);
        $this->assertRecusa(ResultadoDoPdfAssinado::chave(ResultadoDoPdfAssinado::CPF_DIFERENTE), $bia, $deOutro);
        self::assertSame(0, $this->validador->conferencias);
    }

    #[TestDox('sem CPF cadastrado: o motor não confere o CPF do certificado (fica para a assinatura com certificado)')]
    public function testSemCpfCadastrado(): void
    {
        [$bia, $base] = $this->enviadoComGovbr(cpf: null);

        $conferido = $this->useCase()->executar($this->chave->token($bia), $this->upload('a.pdf', OficinaDePdfAssinado::pdfAssinadoComVinculo('ec', OficinaDePdfAssinado::certificado(OficinaDePdfAssinado::chave('ec'), 'BELTRANA:' . OficinaDePdfAssinado::CPF_2), $base)));

        self::assertSame(2, $conferido->n);
    }

    private function useCase(): ConferirPdfAssinadoUseCase
    {
        return new ConferirPdfAssinadoUseCase(
            $this->link(),
            $this->validador,
            new ConferenciaDoPdfAssinado($this->versoes, $this->disco, new ConferenciaDePdf(), new RegraDaCadeia()),
            $this->relogio,
        );
    }

    /**
     * Documento enviado às 9h com Ana (Simples) e Bia (gov.br, com o CPF dado), e o original já assinado guardado no
     * rascunho como a versão base. Devolve Bia e os bytes da base.
     *
     * @return array{Signatario, string}
     */
    private function enviadoComGovbr(bool $emOrdem = false, ?string $cpf = OficinaDePdfAssinado::CPF): array
    {
        $momento = new \DateTimeImmutable('2026-11-09 08:00:00');
        $documento = new DocumentoParaAssinar($this->tenant, null, $momento);
        $documento->aplicarRascunho(DadosDoRascunho::deJson([
            'nome' => 'Contrato de honorários',
            'emOrdem' => $emOrdem,
            'signatarios' => [
                ['nome' => 'Ana Lima', 'email' => 'ana@exemplo.com'],
                array_filter(['nome' => 'Bia Souza', 'email' => 'bia@exemplo.com', 'nivel' => 'govbr', 'cpf' => $cpf]),
            ],
        ]), [], $momento);
        (new \ReflectionProperty(DocumentoParaAssinar::class, 'id'))->setValue($documento, 1);
        foreach ($documento->getSignatarios() as $i => $signatario) {
            (new \ReflectionProperty(Signatario::class, 'id'))->setValue($signatario, 11 + $i);
        }
        $base = OficinaDePdfAssinado::pdfAssinado();
        $original = VersaoDoPdf::original($documento, 1, 'original.pdf', 'Contrato assinado.pdf', self::SHA, \strlen($base), ['v' => 1], $momento);
        $this->versoesNaBase[] = $original;
        $this->disco->semear(ChavesDeAssinaturaEletronica::versao($original), $base);
        $documento->enviar(str_repeat('f', 64), new \DateTimeImmutable('2026-11-09 09:00:00'));
        foreach ($documento->getSignatarios() as $signatario) {
            $signatario->definirTokenHash(ChaveDaAssinatura::hashDoToken($this->chave->token($signatario)));
        }
        $this->naBase[] = $documento;

        return [$documento->getSignatarios()[1], $base];
    }

    /** O arquivo enviado; com `$tamanho`, completado com zeros até ele (arquivo esparso, sem os bytes em memória). */
    private function upload(string $nome, string $conteudo, ?int $tamanho = null): UploadedFile
    {
        $caminho = (string) tempnam(sys_get_temp_dir(), 'assinado-');
        file_put_contents($caminho, $conteudo);
        if ($tamanho !== null) {
            $arquivo = fopen($caminho, 'r+b') ?: self::fail('não abriu o temporário');
            ftruncate($arquivo, $tamanho);
            fclose($arquivo);
            clearstatcache(true, $caminho);
        }

        return new UploadedFile($caminho, $nome, null, null, true);
    }

    private function assertRecusa(string $chave, Signatario $signatario, string|UploadedFile $pdf): void
    {
        try {
            $this->useCase()->executar($this->chave->token($signatario), $pdf instanceof UploadedFile ? $pdf : $this->upload('a.pdf', $pdf));
            self::fail('esperava a recusa ' . $chave);
        } catch (AcaoRecusada $e) {
            self::assertSame($chave, $e->getMessage());
        }
        self::assertSame([], $this->gravados);
        self::assertSame([], $this->disco->gravadas);
        self::assertTrue($signatario->estaPendente());
    }
}
