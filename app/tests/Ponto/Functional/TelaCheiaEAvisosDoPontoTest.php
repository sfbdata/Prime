<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Functional;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Master\Entity\RecursoEstado;
use App\Ponto\Controller\PontoController;
use App\Ponto\Entity\BlocoJornadaColaborador;
use App\Ponto\Entity\ConfiguracaoDoPonto;
use App\Ponto\Entity\HomeOfficeConfig;
use App\Ponto\Entity\JornadaColaborador;
use App\Ponto\Service\FuncoesDoPonto;
use App\Tests\Functional\JusPrimeWebTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;

/**
 * E8 do desenho novo do Ponto (desenho 16, spec docs/specs/ponto-desenho-0811.md, P79 a P83 e P96): a tela cheia do
 * registro, o aviso flutuante, o botão de som e o lembrete de saída, cada um com a sua função (`ponto-animacao`,
 * `ponto-sons`, `ponto-lembrete`), Global E Escritório.
 *
 * O arranjo é conferido por filho direto (`>`): "existe em algum lugar da página" não prova o desenho. PHPUnit não roda
 * o JavaScript: as fases, os tempos, o som e o lembrete em ação são do smoke do dono; o que o JS lê está travado em
 * `TelaCheiaEAvisosDoPontoJsTest`.
 */
#[Group('ponto')]
#[CoversClass(PontoController::class)]
final class TelaCheiaEAvisosDoPontoTest extends JusPrimeWebTestCase
{
    use CenarioDeJustificativaTrait;

    private const TELA_CHEIA = '.pe-page > #ponto-tela-cheia.pe-tc';
    private const VAL = self::TELA_CHEIA . ' > .pe-tc-fase[data-tc-fase="val"] > .pe-tc-val';
    private const OK = self::TELA_CHEIA . ' > .pe-tc-fase[data-tc-fase="ok"]';
    private const ERRO = self::TELA_CHEIA . ' > .pe-tc-fase[data-tc-fase="erro"]';
    private const RODAPE = '.pe-registrar > .pe-corpo > .pe-rodape';

    private KernelBrowser $client;
    private Tenant $escritorio;
    private User $eu;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->instalarCsrfPrevisivel();
        $this->escritorio = $this->escritorio('Escritório Tela Cheia');
        $this->eu = $this->pessoa('tela_cheia');
        $this->vincularColaborador($this->eu, $this->escritorio);
    }

    #[TestDox('Tela cheia (P79): diálogo do desenho escondido, com as três fases, as 24 barras, as etapas com a localização e os botões')]
    public function testTelaCheiaComAsFasesDoDesenho(): void
    {
        $tela = $this->tela();

        $raiz = $tela->filter(self::TELA_CHEIA);
        self::assertCount(1, $raiz, 'a tela cheia é filha direta da página');
        self::assertSame('dialog', $raiz->attr('role'));
        self::assertSame('true', $raiz->attr('aria-modal'));
        self::assertSame('Registro de ponto', $raiz->attr('aria-label'));
        self::assertSame('-1', $raiz->attr('tabindex'), 'a validação leva o foco à própria tela');
        self::assertNotNull($raiz->attr('hidden'), 'nasce escondida');
        self::assertSame(['val', 'ok', 'erro'], $raiz->children()->each(static fn (Crawler $f): string => (string) $f->attr('data-tc-fase')));
        foreach ($raiz->children() as $fase) {
            self::assertTrue($fase->hasAttribute('hidden'), 'toda fase nasce escondida');
        }
        self::assertCount(0, $tela->filter('#ponto-tela-cheia .modal, #ponto-tela-cheia [data-bs-toggle]'), 'sem modal do Bootstrap');

        // Validando: o anel de 24 barras acendendo em 94% de 2.800 ms, a hora no centro, o texto que o leitor de tela anuncia.
        $barras = $tela->filter(self::VAL . ' > .pe-tc-anel > .pe-tc-raio > .pe-tc-barra.pe-tc-barra--on');
        self::assertCount(24, $barras);
        self::assertSame('transform: rotate(345deg);', $tela->filter(self::VAL . ' > .pe-tc-anel > .pe-tc-raio')->eq(23)->attr('style'));
        self::assertSame('animation-delay: 0ms;', $barras->eq(0)->attr('style'));
        self::assertSame('animation-delay: 2522ms;', $barras->eq(23)->attr('style'), 'round(23 / 24 * 2800 * .94)');
        self::assertCount(1, $tela->filter(self::VAL . ' > .pe-tc-anel > .pe-tc-anel-hora[data-tc-hora-val]:last-child'));
        $anuncio = $tela->filter(self::VAL . ' > div[aria-live="assertive"]');
        self::assertCount(1, $anuncio);
        self::assertSame('Validando', trim($anuncio->filter('.pe-tc-validando')->text()));
        self::assertCount(1, $anuncio->filter('.pe-tc-validando > span[data-tc-tipo]'));
        self::assertCount(1, $anuncio->filter('.pe-tc-validando + .pe-tc-data[data-tc-data]'));

        // As etapas (L762 a L764): com a cerca valendo, a localização entra; o facial é da E18.
        $etapas = $tela->filter(self::VAL . ' > .pe-tc-etapas > span.pe-tc-etapa.pe-tc-mov');
        self::assertSame(['Horário oficial confirmado', 'Localização validada', 'Registro selado'], $etapas->each(static fn (Crawler $e): string => trim($e->text())));
        // A última ("Registro selado") sem o atraso no `style`: o JS a marca no tempo dela (2.632 ms) só com o servidor
        // confirmando (revisão da E8).
        self::assertSame(['animation-delay: 877ms;', 'animation-delay: 1755ms;', ''], $etapas->each(static fn (Crawler $e): string => (string) $e->attr('style')));
        self::assertSame('2632', $etapas->last()->attr('data-tc-selo-em'));
        self::assertCount(1, $etapas->filter('.pe-tc-etapa--selo[data-tc-selo]'));
        self::assertCount(3, $etapas->filter('span > i.bi.bi-check-circle-fill[aria-hidden="true"]'));

        // Ponto registrado: cortina verde, anel mini de 24 barras com o check, os textos, "Concluir" e a barra dos 10 s.
        self::assertCount(1, $tela->filter(self::OK . ' > .pe-tc-cortina.pe-tc-cortina--ok.pe-tc-mov:first-child'));
        $corpo = self::OK . ' > .pe-tc-cortina + .pe-tc-ok > .pe-tc-ok-corpo';
        $barrasOk = $tela->filter($corpo . ' > .pe-tc-anel.pe-tc-anel--mini > .pe-tc-raio > .pe-tc-barra.pe-tc-barra--ok');
        self::assertCount(24, $barrasOk);
        self::assertSame('animation-delay: 422ms;', $barrasOk->eq(23)->attr('style'), '100 + 23 * 14');
        self::assertCount(1, $tela->filter($corpo . ' > .pe-tc-anel--mini > svg.pe-tc-check > path.pe-tc-traco--check'));
        $textos = $corpo . ' > .pe-tc-anel--mini + .pe-tc-ok-textos.pe-tc-mov';
        self::assertSame('Ponto registrado', trim($tela->filter($textos . ' > .pe-tc-titulo')->text()));
        self::assertCount(1, $tela->filter($textos . ' > .pe-tc-titulo + .pe-tc-ok-hora[data-tc-hora-ok]'));
        self::assertCount(1, $tela->filter($textos . ' > .pe-tc-ok-hora + .pe-tc-ok-tipo > span[data-tc-tipo] + span[data-tc-data]'));
        self::assertSame('Registrado no sistema · localização validada', trim($tela->filter($textos . ' > .pe-tc-ok-met')->text()));
        // A batida já registrada troca a linha pelo texto do sistema; o padrão fica guardado para a próxima.
        self::assertSame('Registrado no sistema · localização validada', $tela->filter($textos . ' > .pe-tc-ok-met[data-tc-ok-met]')->attr('data-padrao'));
        // Fechada a tela cheia, o foco vai ao título do cartão (que não aciona nada), e não ao "Bater Ponto".
        self::assertSame('-1', $tela->filter('.pe-registrar-barra > #ponto-registrar-titulo.pe-registrar-titulo')->attr('tabindex'));
        self::assertSame('Concluir', trim($tela->filter($corpo . ' > .pe-tc-ok-textos + .pe-tc-botoes > button.pe-tc-btn.pe-tc-btn--contorno[data-tc-concluir]')->text()));
        self::assertCount(1, $tela->filter(self::OK . ' > .pe-tc-ok + span.pe-tc-tempo.pe-tc-mov:last-child'));
        self::assertCount(0, $tela->filter('#ponto-tela-cheia [data-tc-fase="ok"] .bi-download'), 'o comprovante é da E15');

        // Ponto não registrado: cortina vermelha, o X, os textos e "Tentar novamente" antes de "Fechar".
        self::assertCount(1, $tela->filter(self::ERRO . ' > .pe-tc-cortina.pe-tc-cortina--erro.pe-tc-mov:first-child'));
        $erro = self::ERRO . ' > .pe-tc-cortina + .pe-tc-erro.pe-tc-mov';
        self::assertCount(2, $tela->filter($erro . ' > svg.pe-tc-x:first-child > path.pe-tc-traco'));
        self::assertSame('Ponto não registrado', trim($tela->filter($erro . ' > svg + .pe-tc-titulo')->text()));
        self::assertCount(1, $tela->filter($erro . ' > .pe-tc-titulo + .pe-tc-erro-titulo[data-tc-erro-titulo] + .pe-tc-erro-detalhe[data-tc-erro-detalhe]'));
        $botoes = $erro . ' > .pe-tc-erro-detalhe + .pe-tc-botoes:last-child';
        self::assertSame('Tentar novamente', trim($tela->filter($botoes . ' > button.pe-tc-btn.pe-tc-btn--tentar[data-tc-tentar]:first-child')->text()));
        self::assertCount(1, $tela->filter($botoes . ' > button[data-tc-tentar] > i.bi.bi-arrow-repeat[aria-hidden="true"]'));
        self::assertSame('Fechar', trim($tela->filter($botoes . ' > [data-tc-tentar] + button.pe-tc-btn.pe-tc-btn--contorno[data-tc-fechar]:last-child')->text()));

        // O JS da tela recebe a chave, e os módulos da E8 vêm antes do script da tela.
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('const ANIMACAO_LIGADA = true;', $html);
        $ordem = array_map(static fn (string $js): int|false => strpos($html, $js), ['js/ponto-batida.js', 'js/ponto-aviso-flutuante.js', 'js/ponto-sons.js', 'js/ponto-tela-cheia.js', 'const ANIMACAO_LIGADA']);
        self::assertNotContains(false, $ordem);
        $ordenada = $ordem;
        sort($ordenada);
        self::assertSame($ordenada, $ordem, 'os módulos vêm antes do script da tela, que os usa');
    }

    #[TestDox('Sem a cerca (geolocalização desligada no escritório ou Home Office hoje): as etapas e o "Registrado no sistema" não falam de localização')]
    public function testSemCercaNaoFalaDeLocalizacao(): void
    {
        $outro = $this->escritorio('Escritório Home Office');
        $this->vincularColaborador($this->eu, $outro);
        $homeOffice = new HomeOfficeConfig();
        $homeOffice->setUser($this->eu);
        $homeOffice->setTenant($outro);
        $homeOffice->setDiasSemana([1, 2, 3, 4, 5, 6, 7]);
        $homeOffice->setAtivo(true);
        $this->em()->persist($homeOffice);
        $this->em()->flush();
        $this->desligarNoEscritorio($this->escritorio, [FuncoesDoPonto::GEO]);

        $this->confereSemLocalizacao($this->tela());
        $this->confereSemLocalizacao($this->tela($outro));
    }

    #[TestDox('`ponto-animacao` desligada (Escritório ou Global): sem a tela cheia, o resultado vai ao aviso flutuante; outro escritório não é afetado')]
    public function testAnimacaoDesligadaViraAvisoFlutuante(): void
    {
        $outro = $this->escritorio('Escritório Vizinho');
        $global = $this->escritorio('Escritório Global');
        $this->vincularColaborador($this->eu, $global);
        $this->desligarNoEscritorio($outro, [FuncoesDoPonto::ANIMACAO]);
        $vizinho = $this->tela();
        self::assertCount(1, $vizinho->filter(self::TELA_CHEIA), 'a chave do outro escritório não vale aqui');

        $this->desligarNoEscritorio($this->escritorio, [FuncoesDoPonto::ANIMACAO]);
        $tela = $this->tela();
        self::assertCount(0, $tela->filter('#ponto-tela-cheia'));
        self::assertStringContainsString('const ANIMACAO_LIGADA = false;', (string) $this->client->getResponse()->getContent());
        $aviso = $tela->filter('.pe-page > #ponto-aviso-flutuante.pe-flutuante');
        self::assertCount(1, $aviso, 'o aviso flutuante está sempre na página');
        self::assertSame('status', $aviso->attr('role'));
        self::assertNotNull($aviso->attr('hidden'));
        self::assertNull($aviso->attr('data-aviso-na-carga'));

        $this->desligarNoMaster(FuncoesDoPonto::ANIMACAO);
        self::assertCount(0, $this->tela($global)->filter('#ponto-tela-cheia'), 'o Master desligou para todos');
    }

    #[TestDox('Botão de som (P81, L136): entre "Justificar falta" e a jornada; some com `ponto-sons` desligada no Escritório ou no Global')]
    public function testBotaoDeSom(): void
    {
        $global = $this->escritorio('Escritório Global Som');
        $this->vincularColaborador($this->eu, $global);
        $jornada = (new JornadaColaborador())->setUser($this->eu)->setTenant($this->escritorio)->setCargaHorariaSemanal(1800);
        $jornada->addBloco((new BlocoJornadaColaborador())->setDiasSemana([1, 2, 3, 4, 5])->setEntrada('08:00')
            ->setRepouso('12:00')->setRetorno('13:00')->setSaida('15:00')->setMinutosBloco(360));
        $this->em()->persist($jornada);
        $this->em()->flush();

        $tela = $this->tela();
        $botao = $tela->filter(self::RODAPE . ' > button#btn-justificar-falta:first-child + button#btn-som-ponto.pe-som');
        self::assertCount(1, $botao);
        self::assertSame('button', $botao->attr('type'));
        self::assertSame('Silenciar sons', $botao->attr('aria-label'));
        self::assertSame('Silenciar sons', $botao->attr('title'));
        self::assertCount(1, $botao->filter('svg[data-som-ligado][aria-hidden="true"]:not([hidden])'));
        self::assertCount(1, $botao->filter('svg[data-som-ligado] + svg[data-som-mudo][hidden][aria-hidden="true"]'));
        self::assertSame('18', $botao->filter('svg[data-som-mudo]')->attr('width'));
        self::assertCount(1, $tela->filter(self::RODAPE . ' > #btn-som-ponto + span.pe-jornada:last-child'), 'a jornada semanal vem depois do botão de som');
        self::assertStringContainsString('const SONS_LIGADOS = true;', (string) $this->client->getResponse()->getContent());

        $this->desligarNoEscritorio($this->escritorio, [FuncoesDoPonto::SONS]);
        $semSom = $this->tela();
        self::assertCount(0, $semSom->filter('#btn-som-ponto'));
        self::assertCount(1, $semSom->filter(self::RODAPE . ' > #btn-justificar-falta + span.pe-jornada'));
        self::assertStringContainsString('const SONS_LIGADOS = false;', (string) $this->client->getResponse()->getContent());

        $this->desligarNoMaster(FuncoesDoPonto::SONS);
        self::assertCount(0, $this->tela($global)->filter('#btn-som-ponto'));
    }

    #[TestDox('`ponto-lembrete`: a tela recebe a chave (ligada por padrão); desligada no Escritório, o JS esconde a saída prevista e o lembrete')]
    public function testLembreteSegueAChave(): void
    {
        $this->tela();
        self::assertStringContainsString('const LEMBRETE_LIGADO = true;', (string) $this->client->getResponse()->getContent());

        $this->desligarNoEscritorio($this->escritorio, [FuncoesDoPonto::LEMBRETE]);
        $this->tela();
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('const LEMBRETE_LIGADO = false;', $html);
        self::assertStringContainsString('const ANIMACAO_LIGADA = true;', $html, 'uma chave não desliga a outra');
        self::assertStringContainsString('const SONS_LIGADOS = true;', $html);
    }

    #[TestDox('Envio da justificativa (E4, P80): "Enviado para aprovação do gestor." no aviso flutuante, uma vez só, e não no topo da página')]
    public function testJustificativaViraAvisoFlutuante(): void
    {
        $mesPassado = new \DateTimeImmutable('first day of last month');
        $this->logarComTenant($this->client, $this->eu, $this->escritorio);
        $this->client->request('POST', '/ponto/justificativa/nova', [
            'justificativa_ponto' => [
                '_token' => 'TOKEN_justificativa_ponto',
                'tipo'   => 'atestado_medico',
                'datas'  => $mesPassado->modify('+2 days')->format('Y-m-d'),
                'motivo' => 'Consulta médica.',
            ],
            'competencia' => $mesPassado->format('Y-m'),
        ]);
        self::assertResponseRedirects('/ponto/?competencia=' . $mesPassado->format('Y-m'));

        $tela = $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSame(PontoController::AVISO_ENVIADO_AO_GESTOR, $tela->filter('.pe-page > #ponto-aviso-flutuante')->attr('data-aviso-na-carga'));
        self::assertSame('ok', $tela->filter('#ponto-aviso-flutuante')->attr('data-aviso-na-carga-tom'), 'verde');
        self::assertSame('Enviado para aprovação do gestor.', PontoController::AVISO_ENVIADO_AO_GESTOR);
        self::assertSame('', trim($tela->filter('#ponto-aviso-flutuante')->text()), 'o texto entra pelo JS, na hora de aparecer');
        $html = (string) $this->client->getResponse()->getContent();
        self::assertSame(1, substr_count(html_entity_decode($html, \ENT_QUOTES | \ENT_HTML5), 'Enviado para aprovação do gestor.'), 'o texto sai uma vez só');
        self::assertCount(0, $tela->filter('.alert')->reduce(static fn (Crawler $a): bool => str_contains($a->text(), 'aprovação do gestor')), 'nada no topo do layout');
        self::assertStringNotContainsString('Aguarde análise do administrador', $html);

        $denovo = $this->client->request('GET', '/ponto/');
        self::assertNull($denovo->filter('#ponto-aviso-flutuante')->attr('data-aviso-na-carga'), 'o aviso sai uma vez só');
    }

    #[TestDox('Análise pela /ponto (P80, L601): "Justificativa abonada." verde e "Justificativa recusada." vermelho no aviso flutuante; na ficha do gestor, o flash de sempre')]
    public function testAnaliseViraAvisoFlutuante(): void
    {
        $primario = $this->pessoa('primario_analise');
        $this->vincularPrimario($primario, $this->escritorio);
        $mes = new \DateTimeImmutable('first day of last month');
        $abonar = (int) $this->justificativa($this->eu, $this->escritorio, $mes, 'pendente')->getId();
        $recusar = (int) $this->justificativa($this->eu, $this->escritorio, $mes->modify('+1 day'), 'pendente')->getId();
        $naFicha = (int) $this->justificativa($this->eu, $this->escritorio, $mes->modify('+2 days'), 'pendente')->getId();
        $voltaParaPonto = '/ponto/?aba=justificativas&competencia=' . $mes->format('Y-m');

        $this->analisar($primario, $abonar, 'aprovar', true, $mes);
        self::assertResponseRedirects($voltaParaPonto);
        $tela = $this->client->followRedirect();
        $aviso = $tela->filter('.pe-page > #ponto-aviso-flutuante');
        self::assertSame(['Justificativa abonada.', 'ok'], [$aviso->attr('data-aviso-na-carga'), $aviso->attr('data-aviso-na-carga-tom')]);
        self::assertCount(0, $tela->filter('.alert')->reduce(static fn (Crawler $a): bool => str_contains($a->text(), 'abonada')), 'nada no topo do layout');

        $this->analisar($primario, $recusar, 'rejeitar', true, $mes);
        self::assertResponseRedirects($voltaParaPonto);
        $tela = $this->client->followRedirect();
        $aviso = $tela->filter('.pe-page > #ponto-aviso-flutuante');
        self::assertSame(['Justificativa recusada.', 'erro'], [$aviso->attr('data-aviso-na-carga'), $aviso->attr('data-aviso-na-carga-tom')]);
        self::assertCount(0, $tela->filter('.alert')->reduce(static fn (Crawler $a): bool => str_contains($a->text(), 'recusada')), 'nada no topo do layout');

        // Na ficha do gestor (sem `volta=ponto`) não há aviso flutuante: o flash continua o de hoje.
        $this->analisar($primario, $naFicha, 'aprovar', false, $mes);
        self::assertResponseRedirects();
        self::assertStringNotContainsString('/ponto/', (string) $this->client->getResponse()->headers->get('Location'));
        $flashes = $this->client->getRequest()->getSession()->getFlashBag();
        self::assertSame([sprintf('Justificativa de %s abonada.', $mes->modify('+2 days')->format('d/m/Y'))], $flashes->peek('success'));
        self::assertSame([], $flashes->peek(PontoController::FLASH_AVISO_FLUTUANTE));
    }

    private function analisar(User $quem, int $justificativa, string $acao, bool $volta, \DateTimeImmutable $mes): void
    {
        $this->logarComTenant($this->client, $quem, $this->escritorio);
        $this->client->request('POST', sprintf('/tenant/%d/user/%d/justificativa/%d/%s', $this->escritorio->getId(), $this->eu->getId(), $justificativa, $acao), [
            '_token'            => sprintf('TOKEN_justificativa_%s_%d', $acao, $justificativa),
            'observacaoAnalise' => 'Conferido.',
        ] + ($volta ? ['volta' => 'ponto', 'competencia' => $mes->format('Y-m')] : []));
    }

    private function confereSemLocalizacao(Crawler $tela): void
    {
        self::assertSame(
            ['Horário oficial confirmado', 'Registro selado'],
            $tela->filter(self::VAL . ' > .pe-tc-etapas > span.pe-tc-etapa')->each(static fn (Crawler $e): string => trim($e->text())),
        );
        self::assertSame(
            ['animation-delay: 1316ms;', ''],
            $tela->filter(self::VAL . ' > .pe-tc-etapas > span.pe-tc-etapa')->each(static fn (Crawler $e): string => (string) $e->attr('style')),
        );
        self::assertSame('Registrado no sistema', trim($tela->filter(self::OK . ' .pe-tc-ok-met')->text()));
        self::assertSame('Registrado no sistema', $tela->filter(self::OK . ' .pe-tc-ok-met')->attr('data-padrao'));
        self::assertSame('2632', $tela->filter(self::VAL . ' > .pe-tc-etapas > span.pe-tc-etapa--selo')->attr('data-tc-selo-em'));
    }

    private function tela(?Tenant $tenant = null): Crawler
    {
        $this->logarComTenant($this->client, $this->eu, $tenant ?? $this->escritorio);
        $crawler = $this->client->request('GET', '/ponto/');
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    /**
     * Depois de uma requisição o EntityManager do teste esquece o que tinha: o escritório e a pessoa voltam pelo id.
     *
     * @param list<string> $chaves
     */
    private function desligarNoEscritorio(Tenant $tenant, array $chaves): void
    {
        $em = $this->em();
        $tenant = $em->find(Tenant::class, $tenant->getId());
        $por = $em->find(User::class, $this->eu->getId());
        self::assertNotNull($tenant);
        self::assertNotNull($por);
        $configuracao = $em->getRepository(ConfiguracaoDoPonto::class)->findOneBy(['tenant' => $tenant]) ?? new ConfiguracaoDoPonto($tenant);
        foreach ($chaves as $chave) {
            $configuracao->definir($chave, false, $por, new \DateTimeImmutable());
        }
        $this->em()->persist($configuracao);
        $this->em()->flush();
    }

    private function desligarNoMaster(string $chave): void
    {
        $estado = $this->em()->getRepository(RecursoEstado::class)->findOneBy(['chave' => $chave]) ?? new RecursoEstado($chave);
        $estado->desativar(null, null, null, new \DateTimeImmutable());
        $this->em()->persist($estado);
        $this->em()->flush();
    }
}
