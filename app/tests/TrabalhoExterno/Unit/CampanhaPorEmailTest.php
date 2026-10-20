<?php

declare(strict_types=1);

namespace App\Tests\TrabalhoExterno\Unit;

use App\TrabalhoExterno\DTO\DadosDaVaga;
use App\TrabalhoExterno\DTO\DadosDoCurriculo;
use App\TrabalhoExterno\DTO\FiltroDaCampanha;
use App\TrabalhoExterno\Entity\ConfiguracaoCentral;
use App\TrabalhoExterno\Entity\Curriculo;
use App\TrabalhoExterno\Entity\InteressadoEmVagas;
use App\TrabalhoExterno\Entity\LinkDeVagas;
use App\TrabalhoExterno\Entity\Vaga;
use App\TrabalhoExterno\Enum\Perfil;
use App\TrabalhoExterno\Exception\AcaoNaoPermitida;
use App\TrabalhoExterno\Exception\DadosInvalidos;
use App\TrabalhoExterno\Exception\DemandaNaoEncontrada;
use App\TrabalhoExterno\Repository\CurriculoRepository;
use App\TrabalhoExterno\Repository\InteressadoEmVagasRepository;
use App\TrabalhoExterno\Repository\LinkDeVagasRepository;
use App\TrabalhoExterno\Repository\VagaRepository;
use App\TrabalhoExterno\Service\Contexto;
use App\TrabalhoExterno\Service\DestinatariosDaCampanha;
use App\TrabalhoExterno\Service\EmailDaCampanha;
use App\TrabalhoExterno\Service\PoliticaDaDemanda;
use App\TrabalhoExterno\Service\PoliticaDaVaga;
use App\TrabalhoExterno\Service\PoliticaDoCurriculo;
use App\TrabalhoExterno\Service\RegistroDaCampanha;
use App\TrabalhoExterno\UseCase\ExportarDestinatariosDaCampanhaUseCase;
use App\TrabalhoExterno\UseCase\ExportarEmailDaCampanhaUseCase;
use App\TrabalhoExterno\UseCase\MontarCampanhaUseCase;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Asset\Package;
use Symfony\Component\Asset\Packages;
use Symfony\Component\Asset\VersionStrategy\EmptyVersionStrategy;
use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * A campanha por e-mail do Master (spec VS 18.10.26 §4.10): quem recebe (LGPD, filtros, sem duplicados,
 * inscritos de fora até confirmarem, escritórios desligados pela constante), o e-mail do desenho com o
 * marcador de descadastro e as exportações (só o Master, link de vagas só na exportação, log sem a lista).
 * Twig, rotas e assets são os objetos reais, sem kernel.
 */
#[CoversClass(DestinatariosDaCampanha::class)]
#[CoversClass(EmailDaCampanha::class)]
#[CoversClass(FiltroDaCampanha::class)]
#[CoversClass(RegistroDaCampanha::class)]
#[CoversClass(MontarCampanhaUseCase::class)]
#[CoversClass(ExportarDestinatariosDaCampanhaUseCase::class)]
#[CoversClass(ExportarEmailDaCampanhaUseCase::class)]
final class CampanhaPorEmailTest extends TestCase
{
    private const MOTIVO_DE_2 = '2 inscrito(s) nos alertas ficam de fora até confirmarem o e-mail.';

    #[TestDox('candidatos: só currículo com o aceite LGPD e e-mail, nos filtros de UF e área')]
    public function testCandidatosComLgpdENosFiltros(): void
    {
        $semAceite = $this->curriculo(3, 'sem@exemplo.test', 'DF', ['Cível']);
        (new \ReflectionProperty($semAceite, 'versaoDoConsentimento'))->setValue($semAceite, '');
        $cvs = [
            $this->curriculo(1, 'ana@exemplo.test', 'DF', ['Cível']),
            $this->curriculo(2, 'bia@exemplo.test', 'SP', ['Trabalhista']),
            $semAceite,
            $this->curriculo(4, '', 'DF', ['Cível']),
        ];
        $d = $this->destinatarios($cvs);

        self::assertSame(['ana@exemplo.test', 'bia@exemplo.test'], self::emails($d->para(FiltroDaCampanha::deArray([]))));
        self::assertSame(['ana@exemplo.test'], self::emails($d->para(FiltroDaCampanha::deArray(['uf' => 'DF']))));
        self::assertSame(['bia@exemplo.test'], self::emails($d->para(FiltroDaCampanha::deArray(['area' => 'Trabalhista']))));
        self::assertSame([], self::emails($d->para(FiltroDaCampanha::deArray(['uf' => 'SP', 'area' => 'Cível']))));
        self::assertSame(
            ['email' => 'ana@exemplo.test', 'nome' => 'Pessoa 1', 'origem' => 'Currículo', 'uf' => 'DF'],
            $d->para(FiltroDaCampanha::deArray([]))['lista'][0],
        );
    }

    #[TestDox('sem duplicados: o e-mail em minúsculas é a chave, e o inscrito que já tem currículo não conta como de fora')]
    public function testSemDuplicados(): void
    {
        $d = $this->destinatarios(
            [$this->curriculo(1, 'Ana@Exemplo.test', 'DF', []), $this->curriculo(2, 'ana@exemplo.TEST', 'DF', [])],
            [new InteressadoEmVagas('ANA@exemplo.test')],
        );

        $r = $d->para(FiltroDaCampanha::deArray([]));

        self::assertSame(['ana@exemplo.test'], self::emails($r));
        self::assertSame([], $r['deFora']);
    }

    #[TestDox('inscritos nos alertas ficam de fora até confirmarem o e-mail, com a contagem e o motivo; o filtro vale para eles também')]
    public function testInscritosDeFora(): void
    {
        $doDf = new InteressadoEmVagas('lead1@exemplo.test');
        $doDf->definirPreferencias('DF', ['Cível'], null, new \DateTimeImmutable());
        $doSp = new InteressadoEmVagas('lead2@exemplo.test');
        $doSp->definirPreferencias('SP', [], null, new \DateTimeImmutable());
        $semUf = new InteressadoEmVagas('lead3@exemplo.test');
        $d = $this->destinatarios([], [$doDf, $doSp, $semUf]);

        self::assertFalse($d->inscritoConfirmado($doDf), 'liga com a confirmação da 5d: hoje ninguém está confirmado');
        $todos = $d->para(FiltroDaCampanha::deArray([]));
        self::assertSame([], $todos['lista']);
        self::assertSame([['n' => 3, 'motivo' => '3 inscrito(s) nos alertas ficam de fora até confirmarem o e-mail.']], $todos['deFora']);
        self::assertSame([['n' => 2, 'motivo' => self::MOTIVO_DE_2]], $d->para(FiltroDaCampanha::deArray(['uf' => 'DF']))['deFora'], 'o sem UF vale para qualquer UF');
        self::assertSame([], $d->para(FiltroDaCampanha::deArray(['pub' => 'esc']))['deFora']);
    }

    #[TestDox('escritórios desligados pela constante: botão indisponível com a dica e nenhuma vaga lida')]
    public function testEscritoriosDesligados(): void
    {
        self::assertFalse(DestinatariosDaCampanha::ESCRITORIOS_LIBERADOS, 'a confirmar com o dono');
        $vagas = $this->createMock(VagaRepository::class);
        $vagas->expects(self::never())->method('daCentral');
        $d = new DestinatariosDaCampanha($this->createStub(CurriculoRepository::class), $this->createStub(InteressadoEmVagasRepository::class), $vagas);

        self::assertSame(
            [
                ['k' => 'cand', 'rot' => 'Candidatos', 'sub' => 'Vagas abertas + criar conta', 'ic' => 'bi-person-badge', 'disponivel' => true],
                ['k' => 'esc', 'rot' => 'Escritórios', 'sub' => 'Divulgue sua vaga grátis', 'ic' => 'bi-building', 'disponivel' => false, 'dica' => 'A confirmar'],
            ],
            $d->publicos(),
        );
        self::assertSame(['lista' => [], 'deFora' => []], $d->para(FiltroDaCampanha::deArray(['pub' => 'esc'])));
    }

    #[TestDox('escritórios ligados: os e-mails dos ofertantes, sem duplicados e sem a dica')]
    public function testEscritoriosLigados(): void
    {
        $vagas = $this->createStub(VagaRepository::class);
        $vagas->method('daCentral')->willReturn([
            $this->vaga(1, ['ofertante' => ['nome' => 'Silva Advogados', 'email' => 'Rh@Silva.test', 'uf' => 'GO']]),
            $this->vaga(2, ['ofertante' => ['nome' => 'Silva', 'email' => 'rh@silva.test']]),
            $this->vaga(3, ['ofertante' => ['nome' => 'Sem e-mail']]),
        ]);
        $d = new DestinatariosDaCampanha($this->createStub(CurriculoRepository::class), $this->createStub(InteressadoEmVagasRepository::class), $vagas, escritoriosLiberados: true);

        self::assertSame(
            [['email' => 'rh@silva.test', 'nome' => 'Silva Advogados', 'origem' => 'Escritório', 'uf' => 'GO']],
            $d->para(FiltroDaCampanha::deArray(['pub' => 'esc']))['lista'],
        );
        self::assertTrue($d->publicos()[1]['disponivel']);
        self::assertArrayNotHasKey('dica', $d->publicos()[1]);
    }

    #[TestDox('o e-mail é o do desenho: assunto, preheader, vagas abertas (até 6), o link, o marcador {{descadastrar}} literal e sem travessão')]
    public function testHtmlDosCandidatos(): void
    {
        $abertas = array_map(fn (int $i): Vaga => $this->vaga($i, ['titulo' => 'Vaga ' . $i]), range(1, 7));
        $email = $this->email($abertas);
        $filtro = FiltroDaCampanha::deArray([]);

        $html = $email->html($filtro, $email->vagasAbertas($filtro, new \DateTimeImmutable()), 'https://bluejus.test/vagas-juridicas/abc');

        self::assertStringContainsString('<a href="{{descadastrar}}" style="color:#8fd0f2;">Cancelar inscrição</a>', $html);
        self::assertStringContainsString('<title>⚖️ 7 vaga(s) jurídica(s) abertas para você</title>', $html);
        self::assertStringContainsString('Crie sua conta grátis e candidate-se em 1 clique.', $html);
        self::assertStringContainsString('&#9679; 7 vaga(s) aberta(s) agora', $html);
        self::assertStringContainsString('Vagas para Advogados e área Jurídica', $html);
        self::assertStringContainsString('Criar conta grátis e ver as vagas', $html);
        self::assertSame(6, substr_count($html, 'Ver vaga &rarr;'), 'no máximo 6 vagas no e-mail');
        self::assertStringContainsString('Advogado(a) · CLT', $html);
        self::assertStringContainsString('&#128205; Híbrido · Brasília/DF', $html);
        self::assertStringContainsString('href="https://bluejus.test/vagas-juridicas/abc"', $html);
        self::assertStringContainsString('src="https://bluejus.test/images/logo/bluejus-white-transparent-nav.png"', $html);
        self::assertStringNotContainsString('Como funciona', $html);
        self::assertDoesNotMatchRegularExpression('/[—–]/u', $html, 'sem travessão no texto que o usuário vê');
    }

    #[TestDox('o modelo de escritórios: textos do desenho, "Como funciona", nenhuma vaga e o assunto escolhido pelo Master')]
    public function testHtmlDosEscritorios(): void
    {
        $email = $this->email([$this->vaga(1)]);
        $filtro = FiltroDaCampanha::deArray(['pub' => 'esc', 'assunto' => '  Vagas sem custo  ']);

        self::assertSame([], $email->vagasAbertas($filtro, new \DateTimeImmutable()));
        self::assertSame('https://bluejus.test/vagas-juridicas?p=esc', $email->linkPublico($filtro));
        $html = $email->html($filtro, [], $email->linkPublico($filtro));

        self::assertStringContainsString('<title>Vagas sem custo</title>', $html);
        self::assertStringContainsString('&#9679; Para escritórios · divulgação gratuita', $html);
        self::assertStringContainsString('Encontre advogados e profissionais jurídicos sem gastar com anúncio', $html);
        self::assertStringContainsString('Divulgar minha vaga grátis', $html);
        self::assertStringContainsString('Como funciona', $html);
        self::assertStringContainsString('Receba os melhores candidatos', $html);
        self::assertStringNotContainsString('Ver vaga', $html);
        self::assertStringContainsString('{{descadastrar}}', $html);
        self::assertDoesNotMatchRegularExpression('/[—–]/u', $html);
        self::assertSame('Divulgue sua vaga grátis e receba os melhores candidatos', $email->assuntoPadrao($filtro, 0));
        self::assertSame('⚖️ Vagas para Advogados e área Jurídica no BlueJus', $email->assuntoPadrao(FiltroDaCampanha::deArray([]), 0));
    }

    #[TestDox('o filtro: valor fora da lista vira o padrão e o assunto vazio é o padrão do público')]
    public function testFiltro(): void
    {
        $f = FiltroDaCampanha::deArray(['pub' => 'xyz', 'uf' => 'ZZ', 'area' => 'Espacial', 'assunto' => '   ']);

        self::assertSame(['cand', '', '', null], [$f->pub, $f->uf, $f->area, $f->assunto]);
        self::assertSame(['pub' => 'esc', 'uf' => 'DF', 'area' => 'Cível'], FiltroDaCampanha::deArray(['pub' => 'esc', 'uf' => 'DF', 'area' => 'Cível'])->paraOLog());
    }

    /** @return iterable<string, array{Contexto}> */
    public static function quemNaoMonta(): iterable
    {
        $cfg = new ConfiguracaoCentral();
        yield 'Equipe' => [Apoio::contexto(Perfil::Equipe, cfg: $cfg)];
        yield 'Solicitante' => [Apoio::contexto(Perfil::Solicitante, cfg: $cfg)];
        yield 'Convidado' => [Apoio::contexto(Perfil::Convidado, cfg: $cfg)];
        yield 'prévia do Master como Master' => [new Contexto(Apoio::usuario(1), Apoio::tenant(1), Perfil::Master, $cfg, previa: true)];
    }

    #[DataProvider('quemNaoMonta')]
    #[TestDox('a campanha e as exportações são só do Master de verdade: nega $_dataName sem ler nada')]
    public function testSoOMaster(Contexto $ctx): void
    {
        $curriculos = $this->createMock(CurriculoRepository::class);
        $curriculos->expects(self::never())->method(self::anything());
        $links = $this->createMock(LinkDeVagasRepository::class);
        $links->expects(self::never())->method(self::anything());
        $d = new DestinatariosDaCampanha($curriculos, $this->createStub(InteressadoEmVagasRepository::class), $this->createStub(VagaRepository::class));
        $email = $this->email([]);
        $registro = new RegistroDaCampanha(new NullLogger());
        $filtro = FiltroDaCampanha::deArray([]);

        $acoes = [
            fn () => (new MontarCampanhaUseCase($this->politica(), $d, $email))->executar($ctx, $filtro),
            fn () => (new ExportarDestinatariosDaCampanhaUseCase($this->politica(), $d, $registro))->executar($ctx, $filtro),
            fn () => (new ExportarEmailDaCampanhaUseCase($this->politica(), $d, $email, $links, $registro))->executar($ctx, $filtro, 'baixar'),
        ];
        foreach ($acoes as $acao) {
            try {
                $acao();
                self::fail('deveria ser negado');
            } catch (AcaoNaoPermitida $e) {
                self::assertSame('Campanha por e-mail disponível só para o Master.', $e->getMessage());
            }
        }
    }

    #[TestDox('o Master monta o modal: públicos, filtros, assunto padrão, contagens, quem fica de fora, resumo e a prévia com a página pública geral')]
    public function testMasterMonta(): void
    {
        $d = $this->destinatarios([$this->curriculo(1, 'ana@exemplo.test', 'DF', ['Cível'])], [new InteressadoEmVagas('l1@x.test'), new InteressadoEmVagas('l2@x.test')]);
        $email = $this->email([$this->vaga(1, ['uf' => 'DF']), $this->vaga(2, ['uf' => 'SP'])]);

        $r = (new MontarCampanhaUseCase($this->politica(), $d, $email))->executar(Apoio::contexto(Perfil::Master), FiltroDaCampanha::deArray(['uf' => 'DF']));

        self::assertSame(['cand', 'DF', ''], [$r['pub'], $r['uf'], $r['area']]);
        self::assertCount(27, $r['ufs']);
        self::assertSame(DadosDaVaga::AREAS, $r['areas']);
        self::assertSame('⚖️ 1 vaga(s) jurídica(s) abertas para você', $r['assunto']);
        self::assertSame($r['assunto'], $r['assuntoPadrao']);
        self::assertSame([1, 1], [$r['destinatarios'], $r['vagas']]);
        self::assertSame([['n' => 2, 'motivo' => self::MOTIVO_DE_2]], $r['deFora']);
        self::assertSame(['destinatarios' => '1 destinatário(s) com autorização (LGPD)', 'vagas' => '1 vaga(s) aberta(s) no e-mail'], $r['resumo']);
        self::assertStringContainsString('href="https://bluejus.test/vagas-juridicas"', $r['html']);
        self::assertStringNotContainsString('ana@exemplo.test', $r['html'], 'a prévia não leva a lista');
    }

    #[TestDox('CSV dos destinatários: colunas do desenho, proteção contra fórmula, log com público, filtros e quantidade, sem os e-mails')]
    public function testCsvDosDestinatarios(): void
    {
        $d = $this->destinatarios([$this->curriculo(1, 'ana@exemplo.test', 'DF', ['Cível'], '=Ana')]);
        $log = new TestHandler();
        $master = Apoio::contexto(Perfil::Master, usuario: Apoio::usuario(77));

        $csv = (new ExportarDestinatariosDaCampanhaUseCase($this->politica(), $d, new RegistroDaCampanha(new Logger('app', [$log]))))->executar($master, FiltroDaCampanha::deArray(['uf' => 'DF']));

        self::assertSame(["\u{FEFF}" . '"E-mail";"Nome";"Origem";"UF"', '"ana@exemplo.test";"\'=Ana";"Currículo";"DF"'], explode("\n", $csv));
        self::assertCount(1, $log->getRecords());
        $c = $log->getRecords()[0]->context;
        self::assertSame(['csv', 77, 'cand', ['uf' => 'DF', 'area' => ''], 1], [$c['acao'], $c['usuario'], $c['publico'], $c['filtros'], $c['destinatarios']]);
        self::assertNotFalse(\DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $c['em']));
        self::assertStringNotContainsString('exemplo.test', json_encode($log->getRecords()[0]->toArray(), JSON_THROW_ON_ERROR), 'nunca a lista no log');
    }

    #[TestDox('CSV sem ninguém: "não encontrado" com o texto do toast do desenho, sem log')]
    public function testCsvSemDestinatarios(): void
    {
        $log = new TestHandler();
        try {
            (new ExportarDestinatariosDaCampanhaUseCase($this->politica(), $this->destinatarios([]), new RegistroDaCampanha(new Logger('app', [$log]))))->executar(Apoio::contexto(Perfil::Master), FiltroDaCampanha::deArray([]));
            self::fail('esperava "não encontrado"');
        } catch (DemandaNaoEncontrada $e) {
            self::assertSame('Ainda não há e-mails autorizados para este público.', $e->getMessage());
        }
        self::assertSame([], $log->getRecords());
    }

    #[TestDox('exportar o HTML gera um link de vagas com as abertas nos filtros e o usa no e-mail; o log registra a ação sem a lista')]
    public function testExportarHtmlComLink(): void
    {
        $guardado = null;
        $links = $this->createMock(LinkDeVagasRepository::class);
        $links->expects(self::once())->method('salvar')->willReturnCallback(static function (LinkDeVagas $l) use (&$guardado): void {
            $guardado = $l;
        });
        $log = new TestHandler();
        $d = $this->destinatarios([$this->curriculo(1, 'ana@exemplo.test', 'DF', [])]);
        $uc = new ExportarEmailDaCampanhaUseCase($this->politica(), $d, $this->email([$this->vaga(5, ['uf' => 'DF']), $this->vaga(6, ['uf' => 'SP'])]), $links, new RegistroDaCampanha(new Logger('app', [$log])));

        $r = $uc->executar(Apoio::contexto(Perfil::Master, usuario: Apoio::usuario(77)), FiltroDaCampanha::deArray(['uf' => 'DF']), 'copiar');

        self::assertInstanceOf(LinkDeVagas::class, $guardado);
        self::assertSame([5], $guardado->getVagas(), 'só as abertas nos filtros');
        self::assertMatchesRegularExpression('#href="https://bluejus\.test/vagas-juridicas/[A-Za-z0-9_-]{43}"#', $r['html']);
        self::assertSame([1, 1], [$r['destinatarios'], $r['vagas']]);
        self::assertSame(['copiar', 1], [$log->getRecords()[0]->context['acao'], $log->getRecords()[0]->context['destinatarios']]);
        self::assertStringNotContainsString('ana@exemplo.test', json_encode($log->getRecords()[0]->toArray(), JSON_THROW_ON_ERROR));
    }

    #[TestDox('sem vaga aberta (ou no modelo de escritórios) o e-mail exportado usa a página pública e nenhum link é gravado; ação desconhecida é recusada')]
    public function testExportarHtmlSemVagas(): void
    {
        $links = $this->createMock(LinkDeVagasRepository::class);
        $links->expects(self::never())->method('salvar');
        $uc = new ExportarEmailDaCampanhaUseCase($this->politica(), $this->destinatarios([]), $this->email([$this->vaga(1, ['uf' => 'SP'])]), $links, new RegistroDaCampanha(new NullLogger()));
        $master = Apoio::contexto(Perfil::Master);

        self::assertStringContainsString('href="https://bluejus.test/vagas-juridicas"', $uc->executar($master, FiltroDaCampanha::deArray(['uf' => 'DF']), 'baixar')['html']);
        self::assertStringContainsString('href="https://bluejus.test/vagas-juridicas?p=esc"', $uc->executar($master, FiltroDaCampanha::deArray(['pub' => 'esc']), 'baixar')['html']);
        $this->expectException(DadosInvalidos::class);
        $uc->executar($master, FiltroDaCampanha::deArray([]), 'enviar');
    }

    #[TestDox('currículos desligados pela constante (o aceite do currículo não cobre campanha): lista vazia, de fora com a contagem e o motivo, e o inscrito que tem currículo não conta duas vezes')]
    public function testCurriculosDesligados(): void
    {
        self::assertFalse(DestinatariosDaCampanha::CURRICULOS_LIBERADOS, 'a confirmar com o dono');
        $cvs = [
            $this->curriculo(1, 'ana@exemplo.test', 'DF', ['Cível']),
            $this->curriculo(2, 'ANA@exemplo.test', 'DF', ['Cível']),
            $this->curriculo(3, 'bia@exemplo.test', 'SP', ['Trabalhista']),
        ];
        $d = $this->destinatarios($cvs, [new InteressadoEmVagas('ana@exemplo.test'), new InteressadoEmVagas('lead@exemplo.test')], curriculosLiberados: false);

        $todos = $d->para(FiltroDaCampanha::deArray([]));
        self::assertSame([], $todos['lista']);
        self::assertSame([
            ['n' => 2, 'motivo' => '2 currículo(s) ficam de fora: o aceite do currículo não cobre e-mail de campanha.'],
            ['n' => 1, 'motivo' => '1 inscrito(s) nos alertas ficam de fora até confirmarem o e-mail.'],
        ], $todos['deFora'], 'Ana tem currículo repetido e é inscrita: conta uma vez só, como currículo');
        self::assertSame([
            ['n' => 1, 'motivo' => '1 currículo(s) ficam de fora: o aceite do currículo não cobre e-mail de campanha.'],
            ['n' => 2, 'motivo' => self::MOTIVO_DE_2],
        ], $d->para(FiltroDaCampanha::deArray(['uf' => 'SP']))['deFora'], 'o filtro vale para a contagem: em SP, Ana conta só como inscrita (sem UF)');
        self::assertSame([], $this->destinatarios($cvs, [], curriculosLiberados: false)->para(FiltroDaCampanha::deArray(['pub' => 'esc']))['deFora']);
    }

    /**
     * @param list<Curriculo>          $cvs
     * @param list<InteressadoEmVagas> $inscritos
     */
    private function destinatarios(array $cvs, array $inscritos = [], bool $curriculosLiberados = true): DestinatariosDaCampanha
    {
        $curriculos = $this->createStub(CurriculoRepository::class);
        $curriculos->method('recebidos')->willReturn($cvs);
        $interessados = $this->createStub(InteressadoEmVagasRepository::class);
        $interessados->method('paraExportar')->willReturn($inscritos);

        return new DestinatariosDaCampanha($curriculos, $interessados, $this->createStub(VagaRepository::class), curriculosLiberados: $curriculosLiberados);
    }

    /** @param list<Vaga> $abertas o que o repositório devolve como abertas */
    private function email(array $abertas): EmailDaCampanha
    {
        $vagas = $this->createStub(VagaRepository::class);
        $vagas->method('abertas')->willReturn($abertas);
        $rotas = new RouteCollection();
        $rotas->add('trabalho_externo_vagas_publicas', new Route('/vagas-juridicas'));
        $rotas->add('trabalho_externo_vagas_link', new Route('/vagas-juridicas/{token}'));
        $twig = new Environment(new FilesystemLoader(\dirname(__DIR__, 3) . '/templates'), ['strict_variables' => true]);

        return new EmailDaCampanha(
            $vagas,
            $twig,
            new UrlGenerator($rotas, new RequestContext('', 'GET', 'bluejus.test', 'https')),
            new Packages(new Package(new EmptyVersionStrategy())),
        );
    }

    /** @param list<string> $areas */
    private function curriculo(int $id, string $email, string $uf, array $areas, ?string $nome = null): Curriculo
    {
        $cv = Curriculo::criar(Apoio::usuario($id), DadosDoCurriculo::deArray([
            'nome' => $nome ?? 'Pessoa ' . $id, 'email' => $email, 'situacao' => 'bach', 'uf' => $uf, 'areas' => $areas, 'lgpd' => true,
        ]), new \DateTimeImmutable());
        (new \ReflectionProperty($cv, 'id'))->setValue($cv, $id);

        return $cv;
    }

    /** @param array<string, mixed> $extra */
    private function vaga(int $id, array $extra = []): Vaga
    {
        $v = Vaga::daCentral(Apoio::usuario(1), 'Ana', DadosDaVaga::deArray($extra + [
            'titulo' => 'Advogado cível', 'cargo' => 'Advogado(a)', 'contratacao' => 'CLT', 'modalidade' => 'Híbrido',
            'cidade' => 'Brasília', 'uf' => 'DF', 'areas' => ['Cível'], 'ofertante' => ['nome' => 'Escritório'],
        ]), new \DateTimeImmutable());
        (new \ReflectionProperty($v, 'id'))->setValue($v, $id);

        return $v;
    }

    /** @param array{lista: list<array{email: string}>} $r @return list<string> */
    private static function emails(array $r): array
    {
        return array_column($r['lista'], 'email');
    }

    private function politica(): PoliticaDoCurriculo
    {
        return new PoliticaDoCurriculo(new PoliticaDaVaga(new PoliticaDaDemanda()), new PoliticaDaDemanda());
    }
}
