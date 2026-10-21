<?php

declare(strict_types=1);

namespace App\Tests\Comercial\Unit;

use App\Comercial\Campanha\ModelosDeCampanha;
use App\Comercial\Campanha\SanitizadorDeHtmlDeEmail;
use App\Comercial\DTO\MensagemDaCampanha;
use App\Comercial\DTO\ModeloDaGrade;
use App\Comercial\Entity\ModeloDeCampanha;
use App\Comercial\Exception\CampanhaRecusada;
use App\Comercial\Repository\ModeloDeCampanhaRepository;
use App\Comercial\UseCase\CriarModeloDeCampanhaUseCase;
use App\Comercial\UseCase\DuplicarModeloDeCampanhaUseCase;
use App\Comercial\UseCase\ExcluirModeloDeCampanhaUseCase;
use App\Comercial\UseCase\ImportarModeloHtmlUseCase;
use App\Comercial\UseCase\SalvarModeloDeCampanhaUseCase;
use App\Entity\Auth\User;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/**
 * Os modelos próprios (dc L.549-553, spec §8.2): novo, duplicar (o simples vira blocos), importar (sanitizado, até
 * 200 KB), salvar (só o próprio; o fixo recebe a mensagem do dc) e excluir. A trilha manual é do repositório (o teste
 * dele está no functional); aqui se confere a ação pedida.
 */
#[CoversClass(CriarModeloDeCampanhaUseCase::class)]
#[CoversClass(DuplicarModeloDeCampanhaUseCase::class)]
#[CoversClass(ImportarModeloHtmlUseCase::class)]
#[CoversClass(SalvarModeloDeCampanhaUseCase::class)]
#[CoversClass(ExcluirModeloDeCampanhaUseCase::class)]
#[CoversClass(ModeloDeCampanha::class)]
#[CoversClass(ModeloDaGrade::class)]
final class ModelosProprioUseCasesTest extends TestCase
{
    private ModeloDeCampanhaRepository&MockObject $repositorio;
    private MockClock $relogio;
    private User $master;
    private ?ModeloDeCampanha $gravado = null;
    private ?string $acao = null;

    protected function setUp(): void
    {
        $this->repositorio = $this->createMock(ModeloDeCampanhaRepository::class);
        $this->repositorio->method('salvar')->willReturnCallback(function (ModeloDeCampanha $m, string $acao): void {
            $this->gravado = $m;
            $this->acao = $acao;
        });
        $this->relogio = new MockClock('2030-01-15 10:00:00');
        $this->master = (new User())->setEmail('master@bluejus.test');
    }

    #[TestDox('"Novo modelo": o conteúdo inicial do dc (L.549), em blocos, auditado como modelo_new')]
    public function testNovo(): void
    {
        $saida = (new CriarModeloDeCampanhaUseCase($this->repositorio, $this->relogio))->executar($this->master);

        self::assertSame(ModeloDeCampanhaRepository::NOVO, $this->acao);
        self::assertSame(CriarModeloDeCampanhaUseCase::MENSAGEM, $saida->mensagem);
        self::assertSame(['Novo modelo', 'bi-file-earmark-plus', 'blocos', 'Novo informativo', 'BLUEJUS', 'Título do informativo'], [
            $saida->modelo['nome'], $saida->modelo['icone'], $saida->modelo['modo'], $saida->modelo['assunto'], $saida->modelo['selo'], $saida->modelo['titulo'],
        ]);
        self::assertSame(['texto', 'botao'], array_column($saida->modelo['blocos'], 't'));
    }

    #[TestDox('"Duplicar" um simples: vira blocos como o dc L.550 (título, texto, notícias, botão), "(cópia)" no nome')]
    public function testDuplicarSimples(): void
    {
        $base = (new ModelosDeCampanha())->fixo('convite');
        self::assertNotNull($base);
        $saida = $this->duplicar()->executar(MensagemDaCampanha::deArray($base), $this->master);

        self::assertSame('blocos', $saida->modelo['modo']);
        self::assertSame(['titulo', 'texto', 'noticias', 'botao'], array_column($saida->modelo['blocos'], 't'));
        self::assertSame(implode("\n", $base['itens'] ?? []), $saida->modelo['blocos'][2]['a']);
        self::assertSame(['Criar minha conta grátis', 'cadastro'], [$saida->modelo['blocos'][3]['a'], $saida->modelo['blocos'][3]['b']]);
        self::assertSame('Convite · novos escritórios (cópia)', $saida->modelo['nome']);
        self::assertSame(DuplicarModeloDeCampanhaUseCase::MENSAGEM, $saida->mensagem);
    }

    #[TestDox('Ícone fora do padrão bi-... vira bi-file-earmark; nome longo é cortado em 40')]
    public function testIconeENome(): void
    {
        $saida = $this->duplicar()->executar(MensagemDaCampanha::deArray([
            'modo' => 'blocos', 'nome' => str_repeat('N', 60), 'icone' => 'x" onclick="alert(1)', 'assunto' => 'A', 'blocos' => [['t' => 'divisor']],
        ]), $this->master);

        self::assertSame('bi-file-earmark', $saida->modelo['icone']);
        self::assertSame(40, mb_strlen($saida->modelo['nome']));
    }

    #[TestDox('"Importar HTML": nome e assunto do <title>, HTML guardado sanitizado e o aviso do <style>')]
    public function testImportar(): void
    {
        $html = '<html><head><title>Informe de outubro</title><style>p{}</style></head><body><p onclick="x()">Olá {nome}</p><script>alert(1)</script></body></html>';
        $saida = $this->importar()->executar('arquivo.html', $html, $this->master);

        self::assertSame(['Informe de outubro', 'Informe de outubro', 'html', 'bi-filetype-html'], [$saida->modelo['nome'], $saida->modelo['assunto'], $saida->modelo['modo'], $saida->modelo['icone']]);
        self::assertSame('<p>Olá {nome}</p>', $this->gravado?->getConteudo());
        self::assertSame(ImportarModeloHtmlUseCase::MENSAGEM . ' ' . SanitizadorDeHtmlDeEmail::AVISO_DE_ESTILO, $saida->mensagem);
    }

    #[TestDox('"Importar HTML" sem <title>: o nome do arquivo e o assunto "Informativo"; acima de 200 KB, recusado')]
    public function testImportarSemTituloEGrande(): void
    {
        $saida = $this->importar()->executar('promo-de-natal.htm', '<p>Oi</p>', $this->master);
        self::assertSame(['promo-de-natal', 'Informativo'], [$saida->modelo['nome'], $saida->modelo['assunto']]);
        self::assertSame(ImportarModeloHtmlUseCase::MENSAGEM, $saida->mensagem);

        $this->expectExceptionMessage('O arquivo passa de 200 KB.');
        $this->importar()->executar('x.html', str_repeat('a', 204801), $this->master);
    }

    #[TestDox('"Salvar modelo" num fixo: a mensagem do dc (L.551), nada gravado')]
    public function testSalvarFixo(): void
    {
        $this->repositorio->expects(self::never())->method('salvar');

        $this->expectExceptionMessage(SalvarModeloDeCampanhaUseCase::FIXO_NAO_SE_ALTERA);
        $this->salvar()->executar('news', MensagemDaCampanha::deArray(['modo' => 'blocos']), $this->master);
    }

    #[TestDox('"Salvar modelo" no próprio: grava nome e conteúdo, auditado como modelo_upd; outro modo é recusado')]
    public function testSalvarProprio(): void
    {
        $modelo = new ModeloDeCampanha('Antigo', 'bi-stars', 'blocos', $this->master, $this->relogio->now());
        $this->repositorio->method('porId')->with(7)->willReturn($modelo);

        $saida = $this->salvar()->executar('7', MensagemDaCampanha::deArray(['modo' => 'blocos', 'nome' => 'Novo nome', 'assunto' => 'Assunto', 'blocos' => [['t' => 'texto', 'a' => 'Oi']]]), $this->master);

        self::assertSame([SalvarModeloDeCampanhaUseCase::MENSAGEM, ModeloDeCampanhaRepository::ALTERADO, 'Novo nome'], [$saida->mensagem, $this->acao, $modelo->getNome()]);
        self::assertSame([['t' => 'texto', 'a' => 'Oi', 'b' => '']], json_decode($modelo->getConteudo(), true));

        $this->expectException(CampanhaRecusada::class);
        $this->salvar()->executar('7', MensagemDaCampanha::deArray(['modo' => 'html', 'html' => '<p>x</p>']), $this->master);
    }

    #[TestDox('"Excluir": só o próprio; fixo e id desconhecido são recusados')]
    public function testExcluir(): void
    {
        $modelo = new ModeloDeCampanha('Meu', 'bi-stars', 'blocos', $this->master, $this->relogio->now());
        $this->repositorio->method('porId')->willReturnMap([[3, $modelo], [4, null]]);
        $this->repositorio->expects(self::once())->method('remover')->with($modelo, $this->master);
        $excluir = new ExcluirModeloDeCampanhaUseCase($this->repositorio, new ModelosDeCampanha());

        $excluir->executar('3', $this->master);
        foreach (['convite', '4', 'cu3'] as $invalido) {
            try {
                $excluir->executar($invalido, $this->master);
                self::fail('deveria recusar ' . $invalido);
            } catch (CampanhaRecusada) {
                self::addToAssertionCount(1);
            }
        }
    }

    private function duplicar(): DuplicarModeloDeCampanhaUseCase
    {
        return new DuplicarModeloDeCampanhaUseCase($this->repositorio, new SanitizadorDeHtmlDeEmail(), $this->relogio);
    }

    private function importar(): ImportarModeloHtmlUseCase
    {
        return new ImportarModeloHtmlUseCase($this->repositorio, new SanitizadorDeHtmlDeEmail(), $this->relogio);
    }

    private function salvar(): SalvarModeloDeCampanhaUseCase
    {
        return new SalvarModeloDeCampanhaUseCase($this->repositorio, new ModelosDeCampanha(), new SanitizadorDeHtmlDeEmail(), $this->relogio);
    }
}
