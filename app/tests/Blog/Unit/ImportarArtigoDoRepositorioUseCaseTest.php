<?php

declare(strict_types=1);

namespace App\Tests\Blog\Unit;

use App\Blog\DTO\ImportacaoDoArtigoOutput;
use App\Blog\DTO\ImportarArtigoDoRepositorioInput;
use App\Blog\Entity\ArtigoDoBlog;
use App\Blog\Enum\BlogsDoArtigo;
use App\Blog\Enum\SituacaoDoArtigo;
use App\Blog\Exception\ArtigoDoRepositorioRecusado;
use App\Blog\Repository\ArtigoDoBlogRepository;
use App\Blog\Service\ArquivoDoArtigo;
use App\Blog\Service\ExportadorDeArtigo;
use App\Blog\Service\HtmlDoArtigoParaMarkdown;
use App\Blog\Service\MarkdownDoArtigoParaHtml;
use App\Blog\Service\ValidadorDoArtigo;
use App\Blog\UseCase\ImportarArtigoDoRepositorioUseCase;
use App\Shared\Audit\EscopoDeAuditoriaDePlataforma;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Yaml\Yaml;

#[CoversClass(ImportarArtigoDoRepositorioUseCase::class)]
#[CoversClass(ImportarArtigoDoRepositorioInput::class)]
#[CoversClass(ImportacaoDoArtigoOutput::class)]
#[CoversClass(ArquivoDoArtigo::class)]
final class ImportarArtigoDoRepositorioUseCaseTest extends TestCase
{
    private ArtigoDoBlogRepository&MockObject $artigos;
    private EscopoDeAuditoriaDePlataforma $escopo;
    /** @var list<ArtigoDoBlog> */
    private array $salvos = [];
    /** @var list<string> */
    private array $slugsNoBanco = [];

    protected function setUp(): void
    {
        $this->artigos = $this->createMock(ArtigoDoBlogRepository::class);
        $this->escopo = new EscopoDeAuditoriaDePlataforma();
        $this->artigos->method('existeSlug')->willReturnCallback(fn (string $slug): bool => \in_array($slug, $this->slugsNoBanco, true));
        $this->artigos->method('slugsExistentes')->willReturnCallback(fn (): array => $this->slugsNoBanco);
        $this->artigos->method('salvar')->willReturnCallback(function (ArtigoDoBlog $artigo, bool $flush): void {
            self::assertTrue($flush, 'grava com flush');
            self::assertTrue($this->escopo->estaLigado(), 'grava no escopo de plataforma');
            $this->salvos[] = $artigo;
        });
    }

    private function sut(): ImportarArtigoDoRepositorioUseCase
    {
        return new ImportarArtigoDoRepositorioUseCase(
            $this->artigos,
            new MarkdownDoArtigoParaHtml(),
            new HtmlDoArtigoParaMarkdown(),
            new ValidadorDoArtigo(),
            new MockClock('2026-11-03 10:00:00'),
            $this->escopo,
        );
    }

    private static function real(): string
    {
        return (string) file_get_contents(__DIR__ . '/../Fixtures/controle-de-prazos-processuais.md');
    }

    /**
     * O artigo real com campos do frontmatter trocados (null tira o campo).
     *
     * @param array<string, mixed> $campos
     */
    private static function realCom(array $campos, ?string $corpo = null): string
    {
        [$frontmatter, $corpoReal] = self::separar(self::real());
        $dados = Yaml::parse($frontmatter, Yaml::PARSE_DATETIME);
        foreach ($campos as $campo => $valor) {
            if ($valor === null) {
                unset($dados[$campo]);
                continue;
            }
            $dados[$campo] = $valor;
        }

        return "---\n" . Yaml::dump($dados, 4, 2) . "---\n\n" . ($corpo ?? $corpoReal);
    }

    /** @return array{string, string} frontmatter (sem os ---) e corpo */
    private static function separar(string $md): array
    {
        self::assertSame(1, preg_match('/^---\n(.*?)\n---\n\n(.*)$/s', $md, $m));

        return [$m[1], $m[2]];
    }

    /** @param list<string> $slugs */
    private function importar(string $conteudo, BlogsDoArtigo $blogs = BlogsDoArtigo::Normal, bool $simular = false, array $slugs = []): ImportacaoDoArtigoOutput
    {
        return $this->sut()->executar(new ImportarArtigoDoRepositorioInput($conteudo, $blogs, $simular, $slugs));
    }

    #[TestDox('O artigo real entra publicado, com todos os campos, as datas do frontmatter e o corpo convertido')]
    public function testArtigoReal(): void
    {
        $resultado = $this->importar(self::real());

        self::assertTrue($resultado->importado);
        self::assertSame(SituacaoDoArtigo::Publicado, $resultado->situacao);
        self::assertSame([], $resultado->avisos);
        self::assertCount(1, $this->salvos);
        $artigo = $this->salvos[0];
        self::assertSame('controle-de-prazos-processuais', $artigo->getSlug());
        self::assertSame(BlogsDoArtigo::Normal, $artigo->getBlogs());
        self::assertSame('Controle de prazos processuais', $artigo->getTituloCurto());
        self::assertSame('prazos-processuais', $artigo->getCategoria());
        self::assertSame('9 min', $artigo->getLeitura());
        self::assertTrue($artigo->isDestaque());
        self::assertCount(2, $artigo->getReferencias());
        self::assertSame('2026-10-28 00:00', $artigo->getPublicadoEm()?->format('Y-m-d H:i'));
        self::assertSame('2026-10-30', $artigo->getAtualizadoEm()?->format('Y-m-d'));
        self::assertFalse($artigo->temAlteracoesPendentes());
        self::assertNull($artigo->getCriadoPor());
        self::assertStringContainsString('<div data-bj="etapas"><ol><li><strong>Captura.</strong>', $artigo->getCorpoHtml());
        self::assertStringStartsWith('Perder um prazo raramente', $artigo->getCorpoMarkdown());
    }

    #[TestDox('Spec, seção 3: o artigo real importado e reexportado sai equivalente (frontmatter campo a campo, corpo igual)')]
    public function testIdaEVoltaPeloExportador(): void
    {
        $this->importar(self::real());
        $exportado = (new ExportadorDeArtigo())->exportar($this->salvos[0]);

        [$frontmatterOriginal, $corpoOriginal] = self::separar(self::real());
        [$frontmatterExportado, $corpoExportado] = self::separar($exportado);
        $original = Yaml::parse($frontmatterOriginal, Yaml::PARSE_DATETIME);
        $obtido = Yaml::parse($frontmatterExportado, Yaml::PARSE_DATETIME);
        foreach ($original as $campo => $valor) {
            self::assertEquals($valor, $obtido[$campo] ?? null, sprintf('campo "%s"', $campo));
        }
        self::assertSame(array_keys($original), array_keys($obtido), 'nenhum campo a mais ou a menos');

        $normalizar = static fn (string $texto): string => trim((string) preg_replace('/\s+/u', ' ', $texto));
        self::assertSame($normalizar($corpoOriginal), $normalizar($corpoExportado));
    }

    #[TestDox('Slug que já existe no banco é pulado e nada é gravado (rodar de novo não duplica)')]
    public function testSlugExistentePula(): void
    {
        $this->slugsNoBanco = ['controle-de-prazos-processuais'];

        $resultado = $this->importar(self::real());

        self::assertFalse($resultado->importado);
        self::assertSame('controle-de-prazos-processuais', $resultado->slug);
        self::assertSame([], $this->salvos);
    }

    #[TestDox('Simular faz tudo menos gravar')]
    public function testSimular(): void
    {
        $resultado = $this->importar(self::real(), simular: true);

        self::assertTrue($resultado->importado);
        self::assertSame(SituacaoDoArtigo::Publicado, $resultado->situacao);
        self::assertSame([], $this->salvos);
    }

    #[TestDox('"Os dois" vem do comando e vai para o artigo')]
    public function testAmbos(): void
    {
        $this->importar(self::real(), BlogsDoArtigo::Ambos);

        self::assertSame(BlogsDoArtigo::Ambos, $this->salvos[0]->getBlogs());
    }

    #[TestDox('Data futura vira agendado para a meia-noite do dia')]
    public function testDataFuturaAgenda(): void
    {
        $this->importar(self::realCom(['publicadoEm' => new \DateTimeImmutable('2026-11-10'), 'atualizadoEm' => null]));

        self::assertSame(SituacaoDoArtigo::Agendado, $this->salvos[0]->getSituacao());
        self::assertSame('2026-11-10 00:00', $this->salvos[0]->getPublicarEm()?->format('Y-m-d H:i'));
        self::assertNull($this->salvos[0]->getPublicadoEm());
    }

    #[TestDox('Hoje já conta como publicado')]
    public function testHojePublica(): void
    {
        $this->importar(self::realCom(['publicadoEm' => '2026-11-03', 'atualizadoEm' => null]));

        self::assertSame(SituacaoDoArtigo::Publicado, $this->salvos[0]->getSituacao());
        self::assertNull($this->salvos[0]->getAtualizadoEm());
    }

    #[TestDox('rascunho: true vira rascunho; com data futura, a data fica guardada')]
    public function testRascunho(): void
    {
        $this->importar(self::realCom(['rascunho' => true]));
        $this->importar(self::realCom(['rascunho' => true, 'slug' => 'outro-rascunho', 'publicadoEm' => '2026-12-01']));

        self::assertSame(SituacaoDoArtigo::Rascunho, $this->salvos[0]->getSituacao());
        self::assertNull($this->salvos[0]->getPublicarEm());
        self::assertSame(SituacaoDoArtigo::Rascunho, $this->salvos[1]->getSituacao());
        self::assertSame('2026-12-01', $this->salvos[1]->getPublicarEm()?->format('Y-m-d'));
    }

    #[TestDox('Marcadores e revisor do frontmatter entram')]
    public function testMarcadoresERevisor(): void
    {
        $this->importar(self::realCom(['marcadores' => ['INSS', 'Prazo em dobro', 'inss'], 'revisor' => ['nome' => 'Dra. Ana', 'oab' => 'OAB/DF 123']]));

        self::assertSame(['INSS', 'Prazo em dobro'], $this->salvos[0]->getMarcadores());
        self::assertSame('Dra. Ana', $this->salvos[0]->getRevisorNome());
        self::assertSame('OAB/DF 123', $this->salvos[0]->getRevisorOab());
    }

    #[TestDox('A capa não é importada e campo desconhecido fica de fora: os dois viram aviso')]
    public function testCapaECampoDesconhecidoViramAviso(): void
    {
        $resultado = $this->importar(self::realCom(['imagem' => './capa.png', 'imagemAlt' => 'Capa', 'autorConvidado' => 'x']));

        self::assertCount(2, $resultado->avisos);
        self::assertStringContainsString('imagem de capa ficou de fora', $resultado->avisos[0]);
        self::assertStringContainsString('"autorConvidado"', $resultado->avisos[1]);
        self::assertNull($this->salvos[0]->getImagemChave());
        self::assertNull($this->salvos[0]->getImagemAlt());
    }

    #[TestDox('Sem leitura no frontmatter, calcula pelas palavras (200 por minuto)')]
    public function testLeituraCalculada(): void
    {
        $this->importar(self::realCom(['leitura' => null]));

        self::assertMatchesRegularExpression('/^\d+ min$/', $this->salvos[0]->getLeitura());
    }

    #[TestDox('Relacionado que não existe recusa, a não ser que venha na mesma importação')]
    public function testRelacionados(): void
    {
        $conteudo = self::realCom(['relacionados' => ['distribuicao-de-tarefas-na-equipe']]);
        try {
            $this->importar($conteudo);
            self::fail('deveria recusar');
        } catch (ArtigoDoRepositorioRecusado $e) {
            self::assertStringContainsString('"distribuicao-de-tarefas-na-equipe" não existe', $e->getMessage());
        }

        $this->importar($conteudo, slugs: ['distribuicao-de-tarefas-na-equipe']);
        self::assertSame(['distribuicao-de-tarefas-na-equipe'], $this->salvos[0]->getRelacionados());
    }

    #[TestDox('Regra do blog quebrada (título SEO com mais de 60) recusa sem gravar, até no rascunho')]
    public function testRegraQuebradaRecusa(): void
    {
        $this->expectException(ArtigoDoRepositorioRecusado::class);
        $this->expectExceptionMessage('O título SEO pode ter até 60 caracteres.');
        try {
            $this->importar(self::realCom(['tituloSeo' => str_repeat('a', 61), 'rascunho' => true]));
        } finally {
            self::assertSame([], $this->salvos);
        }
    }

    #[TestDox('Imagem no corpo recusa o artigo (as imagens entram na P3)')]
    public function testImagemNoCorpoRecusa(): void
    {
        $this->expectException(ArtigoDoRepositorioRecusado::class);
        $this->expectExceptionMessage('O texto tem imagem.');
        $this->importar(self::realCom([], "Texto.\n\n![painel](./imagens/painel.png)\n"));
    }

    #[TestDox('Arquivo sem frontmatter, slug inválido ou data inválida recusam')]
    public function testFormatoInvalido(): void
    {
        foreach (['Só texto.' => 'frontmatter', self::realCom(['slug' => 'Slug Errado']) => 'Slug inválido', self::realCom(['publicadoEm' => '28/10/2026']) => 'AAAA-MM-DD'] as $conteudo => $mensagem) {
            try {
                $this->importar((string) $conteudo);
                self::fail('deveria recusar: ' . $mensagem);
            } catch (ArtigoDoRepositorioRecusado $e) {
                self::assertStringContainsString($mensagem, $e->getMessage());
            }
        }
        self::assertSame([], $this->salvos);
    }
}
