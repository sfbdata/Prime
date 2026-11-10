<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Unit;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Ponto\DTO\CabecalhoDaFolha;
use App\Ponto\DTO\ConteudoDaFolha;
use App\Ponto\DTO\PdfDaFolhaOutput;
use App\Ponto\Exception\CompetenciaDaFolhaInvalidaException;
use App\Ponto\Service\EmissorDaFolha;
use App\Ponto\Service\ImagemDaFolha\DesenhoDaFolha;
use App\Ponto\Service\PdfDeUmaImagem;
use App\Ponto\UseCase\GerarPdfDaFolhaUseCase;
use App\Tests\Ponto\Support\ConteudoDaFolhaDeExemplo;
use App\Tests\Ponto\Support\DesenhoDaFolhaEspiao;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

/**
 * A folha do mês em PDF (spec ponto-desenho-0811, E16, P34, P89 e D20; decisões 2, 5, 16, 25 e 26), sem banco: a
 * emissão é o dublê da {@see EmissorDaFolha}, o desenho é o espião da {@see DesenhoDaFolha} e o embrulho é o
 * {@see PdfDeUmaImagem} de verdade (PHP puro). As rotas da validação pública saem de um gerador de endereços do Symfony
 * com as duas rotas do comprovante, num endereço de teste.
 */
#[Group('ponto')]
#[CoversClass(GerarPdfDaFolhaUseCase::class)]
final class GerarPdfDaFolhaUseCaseTest extends TestCase
{
    private const QR = 'https://bluejus.test/ponto/validar/' . ConteudoDaFolhaDeExemplo::CODIGO;

    private Tenant $escritorio;
    private User $pessoa;
    private User $gestor;
    private EmissorDaFolha&MockObject $emissor;
    private DesenhoDaFolhaEspiao $desenho;

    protected function setUp(): void
    {
        $this->escritorio = new Tenant();
        $this->pessoa = new User();
        $this->gestor = new User();
        $this->emissor = $this->createMock(EmissorDaFolha::class);
        $this->desenho = new DesenhoDaFolhaEspiao();
    }

    #[TestDox('Com o código: emite em nome de quem pede, desenha a folha emitida com o QR da validação e embrulha num PDF com o título e o nome do desenho')]
    public function testComOCodigo(): void
    {
        $emitida = ConteudoDaFolhaDeExemplo::outubro();
        $this->emissor->expects(self::once())->method('executar')->with($this->escritorio, $this->pessoa, 2026, 10, $this->gestor)->willReturn($emitida);

        $saida = $this->gerar($this->desenho);

        self::assertSame([$emitida], $this->desenho->desenhados, 'desenha a folha que a emissão devolveu');
        self::assertSame([self::QR], $this->desenho->enderecosDoQr);
        self::assertStringStartsWith('%PDF-1.4', $saida->pdf);
        self::assertStringContainsString('/Title (Folha de Ponto 10/2026)', $saida->pdf);
        self::assertSame(['folha_ponto_MARIADASILVA-10-2026.pdf', ConteudoDaFolhaDeExemplo::CODIGO], [$saida->nomeDoArquivo, $saida->codigo]);
    }

    #[TestDox('Sem o segredo (decisão 16): a folha sem validação sai sem QR e sem código')]
    public function testSemOCodigo(): void
    {
        $this->emissor->method('executar')->willReturn(ConteudoDaFolhaDeExemplo::outubro(false));

        $saida = $this->gerar($this->desenho);

        self::assertSame([null], $this->desenho->enderecosDoQr);
        self::assertNull($saida->codigo);
        self::assertStringStartsWith('%PDF-1.4', $saida->pdf);
    }

    #[TestDox('O "Confira em ..." recebe a página de validação sem o protocolo')]
    public function testPaginaDaValidacaoSemOProtocolo(): void
    {
        $this->emissor->method('executar')->willReturn(ConteudoDaFolhaDeExemplo::outubro());
        $desenho = new class () implements DesenhoDaFolha {
            /** @var list<string> */
            public array $paginas = [];

            public function gerar(ConteudoDaFolha $conteudo, ?string $enderecoDoQr, string $enderecoDaPagina): string
            {
                $this->paginas[] = $enderecoDaPagina;

                return (new DesenhoDaFolhaEspiao())->gerar($conteudo, $enderecoDoQr, $enderecoDaPagina);
            }
        };

        $this->gerar($desenho);

        self::assertSame(['bluejus.test/ponto/validar'], $desenho->paginas);
    }

    #[DataProvider('nomes')]
    #[TestDox('Nome do arquivo ($_dataName): sem acento e só letras, em maiúsculas; sem letras, "USUARIO" (decisão 26)')]
    public function testNomeDoArquivo(string $nome, string $arquivo): void
    {
        $exemplo = ConteudoDaFolhaDeExemplo::outubro();
        $cabecalho = new CabecalhoDaFolha(...[...get_object_vars($exemplo->cabecalho), 'nome' => $nome]);
        $this->emissor->method('executar')->willReturn(new ConteudoDaFolha(2026, 10, $cabecalho, $exemplo->linhas, $exemplo->resumo, $exemplo->intervalos, null, $exemplo->validacao));

        self::assertSame($arquivo, $this->gerar($this->desenho)->nomeDoArquivo);
    }

    /** @return iterable<string, array{string, string}> */
    public static function nomes(): iterable
    {
        yield 'com acentos e espaços' => ['João da Conceição Ñandú', 'folha_ponto_JOAODACONCEICAONANDU-10-2026.pdf'];
        yield 'só números e sinais' => ['0001 - 2/3', 'folha_ponto_USUARIO-10-2026.pdf'];
        yield 'vazio' => ['', 'folha_ponto_USUARIO-10-2026.pdf'];
    }

    #[TestDox('Competência fora do calendário: a recusa da emissão sobe como está, sem desenhar')]
    public function testCompetenciaInvalidaNaoDesenha(): void
    {
        $this->emissor->method('executar')->willThrowException(new CompetenciaDaFolhaInvalidaException('Competência inválida: 13/2026.'));

        try {
            $this->gerar($this->desenho, 13);
            self::fail('a competência inválida não pode virar PDF');
        } catch (CompetenciaDaFolhaInvalidaException) {
            self::assertSame([], $this->desenho->desenhados);
        }
    }

    #[TestDox('Falha do embrulho depois da emissão (decisão 25): sobe o \InvalidArgumentException do PDF, nunca a recusa da competência')]
    public function testFalhaDoEmbrulhoNaoEhCompetenciaInvalida(): void
    {
        $this->emissor->expects(self::once())->method('executar')->willReturn(ConteudoDaFolhaDeExemplo::outubro());
        $desenho = new class () implements DesenhoDaFolha {
            public function gerar(ConteudoDaFolha $conteudo, ?string $enderecoDoQr, string $enderecoDaPagina): string
            {
                return 'isto não é um JPEG';
            }
        };

        try {
            $this->gerar($desenho);
            self::fail('o que não é JPEG não vira PDF');
        } catch (\InvalidArgumentException $falha) {
            self::assertNotInstanceOf(CompetenciaDaFolhaInvalidaException::class, $falha);
            self::assertSame('A página do PDF precisa ser uma imagem JPEG.', $falha->getMessage());
        }
    }

    private function gerar(DesenhoDaFolha $desenho, int $mes = 10): PdfDaFolhaOutput
    {
        return (new GerarPdfDaFolhaUseCase($this->emissor, $desenho, new PdfDeUmaImagem(), self::rotas()))
            ->executar($this->escritorio, $this->pessoa, 2026, $mes, $this->gestor);
    }

    /** As duas rotas da validação pública (as do `ValidacaoDoComprovanteController`), em https://bluejus.test. */
    private static function rotas(): UrlGenerator
    {
        $rotas = new RouteCollection();
        $rotas->add('validacao_comprovante_ponto', new Route('/ponto/validar'));
        $rotas->add('validacao_comprovante_ponto_codigo', new Route('/ponto/validar/{codigo}'));

        return new UrlGenerator($rotas, new RequestContext('', 'GET', 'bluejus.test', 'https'));
    }
}
