<?php

declare(strict_types=1);

namespace App\Tests\Voto\Functional;

use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Master\Entity\RecursoEstado;
use App\Tests\Auth\Doubles\RateLimiterFactoryEspiao;
use App\Tests\Factory\Auth\UserFactory;
use App\Tests\Factory\Tenant\TenantFactory;
use App\Tests\Factory\Voto\AssembleiaFactory;
use App\Tests\Factory\Voto\OpcaoDaPautaFactory;
use App\Tests\Factory\Voto\PautaDaAssembleiaFactory;
use App\Tests\Factory\Voto\UnidadeDaAssembleiaFactory;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Voto\Controller\CedulaController;
use App\Voto\Entity\Assembleia;
use App\Voto\Entity\LiberacaoDoVoto;
use App\Voto\Entity\OpcaoDaPauta;
use App\Voto\Entity\PautaDaAssembleia;
use App\Voto\Entity\UnidadeDaAssembleia;
use App\Voto\EventListener\CedulaSemTenantFilterListener;
use App\Voto\UseCase\MontarCedulaUseCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;
use Zenstruck\Foundry\Test\Factories;

/**
 * A cédula pública da assembleia de condomínio (spec docs/specs/voto-assembleia-condominio.md, seções 1, 4 e 5): sem
 * login, logado em outro escritório, 404 e limitador, liberação do escritório, chave `voto` do Master, cabeçalhos,
 * CSRF, o voto pela página e os estados de cada pauta. O arranjo do desenho é conferido por filho direto.
 */
#[CoversClass(CedulaController::class)]
#[CoversClass(MontarCedulaUseCase::class)]
#[CoversClass(CedulaSemTenantFilterListener::class)]
#[Group('voto')]
final class CedulaControllerTest extends JusPrimeWebTestCase
{
    use Factories;

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Tenant $escritorio;
    private Assembleia $assembleia;
    private PautaDaAssembleia $pauta;
    private UnidadeDaAssembleia $unidade;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->ligarOVoto();
        $this->escritorio = TenantFactory::createOne(['name' => 'Condomínio Teste ' . uniqid()])->_real();
        $this->liberar($this->escritorio);
        $this->assembleia = AssembleiaFactory::new()->porFracao()->publicada()
            ->create(['tenant' => $this->escritorio, 'titulo' => 'AGO Residencial Ipê'])->_real();
        $this->pauta = PautaDaAssembleiaFactory::new()->comOpcoesPadrao()->aberta()
            ->create(['assembleia' => $this->assembleia, 'titulo' => 'Aprovação das contas'])->_real();
        $this->unidade = UnidadeDaAssembleiaFactory::createOne([
            'assembleia' => $this->assembleia, 'bloco' => 'Bloco A', 'identificacao' => 'A-101', 'fracao' => '0.025000',
        ])->_real();
    }

    #[TestDox('Sem login: 200, cabeçalhos de proteção, assembleia, unidade, aviso de sigilo e a pauta com as opções')]
    public function testAnonimoAbreACedula(): void
    {
        $crawler = $this->client->request('GET', $this->link());

        self::assertResponseStatusCodeSame(200);
        $resposta = $this->client->getResponse();
        self::assertSame('noindex, nofollow', $resposta->headers->get('X-Robots-Tag'));
        self::assertStringContainsString('no-store', (string) $resposta->headers->get('Cache-Control'));
        self::assertSame('no-referrer', $resposta->headers->get('Referrer-Policy'));
        self::assertSame('Portal do Eleitor', $crawler->filter('body > .vcPagina > header.vcCab > b.vcPortal')->text());
        self::assertCount(1, $crawler->filter('header.vcCab > img.vcLogo[src="/images/voto/bj-voto-logo.png"]'));
        self::assertSame('AGO Residencial Ipê', $crawler->filter('.vcMain > .vC.vcUnidade b')->text());
        self::assertStringContainsString('Bloco A · A-101', $crawler->filter('.vcMain > .vC.vcUnidade')->text());
        self::assertSame(
            'Seu voto é secreto: o sistema registra que a unidade votou, mas não em quê.',
            $crawler->filter('.vcMain > .vAviso[data-vc-sigilo]')->text(),
        );
        $cartao = $crawler->filter('.vcMain > .vC.vcPauta');
        self::assertCount(1, $cartao);
        self::assertSame('Aprovação das contas', $cartao->filter('.vcPautaNome > b')->text());
        self::assertSame('Votação aberta', $cartao->filter('.vcPautaTopo > .vChip')->text());
        // Reserva (JavaScript que não sobe): o voto simples de antes, escondido, com as mesmas 3 opções.
        $reserva = $cartao->filter('.vcPautaIn > .vcReserva[data-vc-reserva][hidden]');
        self::assertCount(1, $reserva);
        self::assertSame(['1 Sim', '2 Não', 'ABSTENÇÃO'], $reserva->filter('form[data-vc-votar] .vcOpcoes > button.vB[name="opcao"]')->each(
            static fn (Crawler $b): string => trim(preg_replace('/\s+/', ' ', $b->text()) ?? ''),
        ));
        self::assertSame($this->link() . '/pauta/' . $this->pauta->getId(), $reserva->filter('form')->attr('action'));
        self::assertNotSame('', (string) $reserva->filter('input[name="_token"]')->attr('value'));
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('<noscript><style>.vcReserva[hidden] { display:block; } .vcAcoes { display:none; }</style></noscript>', $html);
        self::assertStringContainsString("window.__vcCabine === true", $html, 'guarda que insiste na cabine antes da reserva');
        self::assertStringContainsString("sessionStorage.setItem(chave, '1')", $html);
        self::assertCount(0, $crawler->filter('.vMod'), 'a janela de confirmação antiga saiu, a cabine confirma');

        // Botões do desenho (L153), em ordem: Conhecer candidaturas, Simular votação e Votar.
        self::assertSame(
            ['Conhecer candidaturas', 'Simular votação', 'Votar'],
            $crawler->filter('.vcMain > .vcAcoes > button.vB')->each(static fn (Crawler $b): string => trim($b->text())),
        );
        self::assertCount(0, $crawler->filter('.vcAcoes > button[disabled]'));

        // Cabine (L257-280): arranjo por filho direto, 10 números, 3 teclas de ação e 8 LEDs.
        $cabine = $crawler->filter('.vcMain > .uBg[data-vc-cabine][hidden][role="application"]');
        self::assertCount(1, $cabine);
        self::assertCount(1, $cabine->filter('.uBody > .uGr > .uMold > .uTela[data-u-tela]'));
        self::assertCount(1, $cabine->filter('.uBody > .uGr > .uDir > .uPlaca > button[data-u-som]'));
        self::assertCount(10, $cabine->filter('.uBody > .uGr > .uDir > .uTec > button.uT[data-u-tecla], .uBody > .uGr > .uDir > .uTec > button.uT0'));
        self::assertSame(['BRANCO', 'CORRIGE', 'CONFIRMA'], $cabine->filter('.uTec > .uAc > button.uT')->each(
            static fn (Crawler $b): string => trim($b->text()),
        ));
        self::assertCount(8, $cabine->filter('.uBody > .uFen > i'));
        self::assertStringContainsString('Sem vínculo com a Justiça Eleitoral', $cabine->filter('.uBody > .uDemo')->text());

        $dados = $this->dadosDaCabine($crawler);
        self::assertSame('AGO Residencial Ipê', $dados['assembleia']);
        self::assertCount(1, $dados['pautas']);
        self::assertSame($dados['pautas'], $dados['simulacao']);
        self::assertSame('Aprovação das contas', $dados['pautas'][0]['titulo']);
        self::assertSame(
            ['1 Sim', '2 Não', 'ABSTENÇÃO'],
            array_map(static fn (array $o): string => $o['abstencao'] ? $o['rotulo'] : $o['numero'] . ' ' . $o['rotulo'], $dados['pautas'][0]['opcoes']),
        );
        self::assertSame([false, false, true], array_column($dados['pautas'][0]['opcoes'], 'abstencao'));
    }

    /** @return array<string, mixed> */
    private function dadosDaCabine(Crawler $crawler): array
    {
        return json_decode((string) $crawler->filter('[data-vc-cabine-dados]')->attr('data-vc-cabine-dados'), true, 512, \JSON_THROW_ON_ERROR);
    }

    #[TestDox('Cabine: o endereço do voto e o CSRF da página vão nos dados, com os textos da cabine')]
    public function testDadosDaCabineApontamParaOVoto(): void
    {
        $dados = $this->dadosDaCabine($this->client->request('GET', $this->link()));

        self::assertSame($this->link() . '/pauta/__PAUTA__', $dados['urlVotar']);
        self::assertNotSame('', $dados['csrf']);
        self::assertSame('/images/voto/bj-voto-logo.png', $dados['logo']);
        foreach (['inicio_aperte', 'candidaturas', 'fim_voto', 'erro_generico', 'dica', 'fala_nulo'] as $chave) {
            self::assertNotSame('', $dados['textos'][$chave] ?? '', 'texto da cabine ausente: ' . $chave);
        }
    }

    #[TestDox('Página própria: sem a moldura do sistema, sem Bootstrap e sem os textos proibidos (cifra, demonstração, BJV)')]
    public function testSemMolduraNemTextoProibido(): void
    {
        $crawler = $this->client->request('GET', $this->link());

        $html = (string) $this->client->getResponse()->getContent();
        self::assertCount(0, $crawler->filter('.main-sidebar, .pje-menu-list, .modal, .card, .btn'));
        self::assertStringNotContainsString('bootstrap.min', $html);
        foreach (['cifrad', 'Protegendo seu voto', 'Ambiente demonstrativo', 'BJV', "\u{2014}", "\u{2013}"] as $proibido) {
            self::assertStringNotContainsString($proibido, $html, 'texto proibido: ' . $proibido);
        }
    }

    #[TestDox('Logado em OUTRO escritório: a cédula abre igual (o filtro de tenant da sessão não esconde nada)')]
    public function testLogadoEmOutroEscritorio(): void
    {
        $outro = TenantFactory::createOne()->_real();
        $usuario = UserFactory::createOne(['roles' => ['ROLE_USER']])->_real();
        $papel = (new TenantRole())->setTenant($outro)->setName('Perfil ' . uniqid())->setIsSystem(true);
        $this->em->persist($papel);
        $this->em->persist((new UserTenant($usuario, $outro))->setTenantRole($papel));
        $this->em->flush();
        $this->logarComTenant($this->client, $usuario, $outro);

        $crawler = $this->client->request('GET', $this->link());

        self::assertResponseStatusCodeSame(200);
        self::assertSame('Aprovação das contas', $crawler->filter('.vcPauta .vcPautaNome > b')->text());
        self::assertCount(3, $this->dadosDaCabine($crawler)['pautas'][0]['opcoes']);
    }

    #[TestDox('Token errado: 404 "Link inválido ou eleição indisponível." e consome o limitador com o IP')]
    public function testTokenErradoDa404EConsomeOLimitador(): void
    {
        $espiao = new RateLimiterFactoryEspiao();
        static::getContainer()->set('limiter.voto_cedula_invalido', $espiao);

        $crawler = $this->client->request('GET', '/votar/' . str_repeat('ab', 20));

        self::assertResponseStatusCodeSame(404);
        self::assertSame('Link inválido ou eleição indisponível.', $crawler->filter('.vcMain > .vC.vcErro')->text());
        self::assertSame('noindex, nofollow', $this->client->getResponse()->headers->get('X-Robots-Tag'));
        self::assertSame(['127.0.0.1'], $espiao->chavesConsumidas);
    }

    #[TestDox('Rajada de tokens errados: limitador estourado dá 429')]
    public function testRajadaDa429(): void
    {
        static::getContainer()->set('limiter.voto_cedula_invalido', new RateLimiterFactoryEspiao(aceita: false));

        $crawler = $this->client->request('GET', '/votar/' . str_repeat('cd', 20));

        self::assertResponseStatusCodeSame(429);
        self::assertSame('Muitas consultas. Tente novamente em alguns minutos.', $crawler->filter('.vcMain > .vC.vcErro')->text());
    }

    #[TestDox('Token válido não passa pelo limitador: abre mesmo com a cota do IP estourada (wi-fi da assembleia)')]
    public function testTokenValidoNaoELimitado(): void
    {
        $espiao = new RateLimiterFactoryEspiao(aceita: false);
        static::getContainer()->set('limiter.voto_cedula_invalido', $espiao);

        $this->client->request('GET', $this->link());

        self::assertResponseStatusCodeSame(200);
        self::assertSame([], $espiao->chavesConsumidas);
    }

    #[TestDox('Escritório da assembleia sem o Voto liberado pelo Master: 404 e consome o limitador')]
    public function testEscritorioSemLiberacaoDa404(): void
    {
        $liberacao = $this->em->getRepository(LiberacaoDoVoto::class)->findOneBy(['tenant' => $this->escritorio]);
        self::assertNotNull($liberacao);
        $liberacao->bloquear();
        $this->em->flush();
        $espiao = new RateLimiterFactoryEspiao();
        static::getContainer()->set('limiter.voto_cedula_invalido', $espiao);

        $this->client->request('GET', $this->link());

        self::assertResponseStatusCodeSame(404);
        self::assertCount(1, $espiao->chavesConsumidas);
    }

    #[TestDox('Chave `voto` desligada pelo Master: 503 "em preparação" para o morador')]
    public function testChaveVotoDesligadaDa503(): void
    {
        $estado = $this->em->getRepository(RecursoEstado::class)->findOneBy(['chave' => 'voto']) ?? new RecursoEstado('voto');
        $estado->desativar(null, null, null, new \DateTimeImmutable());
        $this->em->persist($estado);
        $this->em->flush();

        $this->client->request('GET', $this->link());

        self::assertResponseStatusCodeSame(503);
    }

    #[TestDox('Votar: POST com o CSRF da página grava o voto e volta à cédula com "Voto registrado." e "Você já votou nesta pauta."')]
    public function testVotarComCsrf(): void
    {
        $dados = $this->dadosDaCabine($this->client->request('GET', $this->link()));
        $sim = $this->opcao(1);

        $this->client->request('POST', str_replace('__PAUTA__', (string) $this->pauta->getId(), $dados['urlVotar']), [
            '_token' => $dados['csrf'],
            'opcao' => (string) $sim->getId(),
        ]);

        self::assertResponseRedirects($this->link());
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));
        $depois = $this->client->followRedirect();
        self::assertSame('Voto registrado.', $depois->filter('body > .vcToast[data-vc-toast]')->text());
        self::assertStringStartsWith('Você já votou nesta pauta.', $depois->filter('.vcPauta [data-vc-votou]')->text());
        self::assertMatchesRegularExpression('/Registrado às \d{2}:\d{2}\./', $depois->filter('.vcPauta [data-vc-votou] .vcHora')->text());
        self::assertSame([], $this->dadosDaCabine($depois)['pautas'], 'quem votou não tem mais o que votar na cabine');
        self::assertCount(0, $depois->filter('[data-vc-reserva], form[data-vc-votar]'), 'quem votou também não tem reserva');
        self::assertCount(1, $depois->filter('.vcAcoes > button[data-vc-abrir-cabine][disabled]'), 'o botão Votar fica desabilitado');
        self::assertSame([1, '0.025000'], $this->contador($sim));
    }

    #[TestDox('Votar pela cabine (Accept: application/json): grava o voto e responde JSON {ok, mensagem}, sem redirecionar')]
    public function testVotarPelaCabineResponderJson(): void
    {
        $dados = $this->dadosDaCabine($this->client->request('GET', $this->link()));
        $sim = $this->opcao(1);

        $this->client->request('POST', str_replace('__PAUTA__', (string) $this->pauta->getId(), $dados['urlVotar']), [
            '_token' => $dados['csrf'],
            'opcao' => (string) $sim->getId(),
        ], [], ['HTTP_ACCEPT' => 'application/json']);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['ok' => true, 'mensagem' => 'Voto registrado.'], json_decode((string) $this->client->getResponse()->getContent(), true));
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));
        self::assertSame([1, '0.025000'], $this->contador($sim));
    }

    #[TestDox('Votar pela cabine sem CSRF válido, ou a mesma pauta de novo: JSON com ok falso e a mensagem, nada gravado a mais')]
    public function testVotarPelaCabineRecusaEmJson(): void
    {
        $dados = $this->dadosDaCabine($this->client->request('GET', $this->link()));
        $sim = $this->opcao(1);
        $url = str_replace('__PAUTA__', (string) $this->pauta->getId(), $dados['urlVotar']);

        $this->client->request('POST', $url, ['_token' => 'x', 'opcao' => (string) $sim->getId()], [], ['HTTP_ACCEPT' => 'application/json']);
        self::assertResponseStatusCodeSame(400);
        self::assertFalse(json_decode((string) $this->client->getResponse()->getContent(), true)['ok']);
        self::assertSame([0, '0.000000'], $this->contador($sim));

        $voto = ['_token' => $dados['csrf'], 'opcao' => (string) $sim->getId()];
        $this->client->request('POST', $url, $voto, [], ['HTTP_ACCEPT' => 'application/json']);
        self::assertResponseStatusCodeSame(200);
        $this->client->request('POST', $url, $voto, [], ['HTTP_ACCEPT' => 'application/json']);
        self::assertResponseStatusCodeSame(422);
        $segunda = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertFalse($segunda['ok']);
        self::assertNotSame('', $segunda['mensagem']);
        self::assertSame([1, '0.025000'], $this->contador($sim), 'a segunda tentativa não soma');
    }

    #[TestDox('Votar sem CSRF válido: nada é gravado e a cédula volta com o aviso de página expirada')]
    public function testVotarSemCsrf(): void
    {
        $sim = $this->opcao(1);

        $this->client->request('POST', $this->link() . '/pauta/' . $this->pauta->getId(), ['_token' => 'x', 'opcao' => (string) $sim->getId()]);

        self::assertResponseRedirects($this->link());
        $depois = $this->client->followRedirect();
        self::assertSame('A página expirou. Escolha de novo e confirme o voto.', $depois->filter('body > .vcToast.vcToastErro')->text());
        self::assertSame([0, '0.000000'], $this->contador($sim));
        self::assertCount(1, $this->dadosDaCabine($depois)['pautas']);
    }

    #[TestDox('POST com token errado: 404 e consome o limitador')]
    public function testVotarComTokenErradoDa404(): void
    {
        $espiao = new RateLimiterFactoryEspiao();
        static::getContainer()->set('limiter.voto_cedula_invalido', $espiao);
        $this->client->request('GET', '/votar/' . str_repeat('ef', 20));
        // O CSRF só existe para um token que a página já mostrou: sem ele, volta ao GET, que dá 404.
        $this->client->request('POST', '/votar/' . str_repeat('ef', 20) . '/pauta/' . $this->pauta->getId(), ['_token' => 'x', 'opcao' => '1']);
        $this->client->followRedirect();

        self::assertResponseStatusCodeSame(404);
        self::assertCount(2, $espiao->chavesConsumidas);
    }

    #[TestDox('Pauta aguardando: "A votação desta pauta ainda não foi aberta." e nenhuma opção')]
    public function testPautaAguardando(): void
    {
        PautaDaAssembleiaFactory::new()->comOpcoesPadrao()->create(['assembleia' => $this->assembleia, 'ordem' => 2, 'titulo' => 'Previsão orçamentária']);

        $crawler = $this->client->request('GET', $this->link());

        $cartao = $crawler->filter('.vcMain > .vC.vcPauta')->eq(1);
        self::assertSame('Publicada', $cartao->filter('.vChip')->text());
        self::assertSame('A votação desta pauta ainda não foi aberta.', trim($cartao->filter('[data-vc-aguardando]')->text()));
        self::assertCount(0, $cartao->filter('form'));
        self::assertSame('30', $crawler->filter('body')->attr('data-vc-recarregar'), 'à espera da mesa, a página se atualiza');
    }

    #[TestDox('Unidade inapta: vê as pautas, o selo "Não apto(a)" e o aviso, sem opções')]
    public function testUnidadeInapta(): void
    {
        $inapta = UnidadeDaAssembleiaFactory::new()->inapta()->create(['assembleia' => $this->assembleia])->_real();

        $crawler = $this->client->request('GET', '/votar/' . UnidadeDaAssembleiaFactory::tokenDe($inapta));

        self::assertResponseStatusCodeSame(200);
        self::assertSame('Não apto(a)', $crawler->filter('.vcUnidade > .vChip')->text());
        self::assertSame('Sua unidade não está apta a votar nesta assembleia.', trim($crawler->filter('.vcPauta [data-vc-inapta]')->text()));
        self::assertSame([], $this->dadosDaCabine($crawler)['pautas'], 'inapta não tem o que votar na cabine');
        self::assertCount(0, $crawler->filter('[data-vc-reserva], form[data-vc-votar]'));
        self::assertCount(1, $crawler->filter('.vcAcoes > button[data-vc-abrir-cabine][disabled]'));
    }

    #[TestDox('Pauta encerrada: o resultado do desenho (faixa, barras, abstenções e decisão), sem opções')]
    public function testPautaEncerradaMostraOResultado(): void
    {
        $encerrada = PautaDaAssembleiaFactory::new()->encerrada()
            ->create(['assembleia' => $this->assembleia, 'ordem' => 2, 'titulo' => 'Obra da fachada'])->_real();
        OpcaoDaPautaFactory::new()->comVotos(3, '0.600000')->create(['pauta' => $encerrada, 'numero' => 1, 'rotulo' => 'Sim']);
        OpcaoDaPautaFactory::new()->comVotos(1, '0.100000')->create(['pauta' => $encerrada, 'numero' => 2, 'rotulo' => 'Não']);
        OpcaoDaPautaFactory::new()->abstencao()->comVotos(1, '0.050000')->create(['pauta' => $encerrada]);
        UnidadeDaAssembleiaFactory::createOne(['assembleia' => $this->assembleia, 'fracao' => '0.975000']);
        $this->em->clear();

        $crawler = $this->client->request('GET', $this->link());

        $cartao = $crawler->filter('.vcMain > .vC.vcPauta')->eq(1);
        self::assertSame('Encerrada', $cartao->filter('.vChip')->text());
        self::assertSame('Obra da fachada: 1 Sim', $cartao->filter('.vcResultado > .vcResumo')->text());
        self::assertSame(['1 Sim', '2 Não'], $cartao->filter('.vcResultado .vcItem > .vcItemNome')->each(static fn (Crawler $c): string => $c->text()));
        self::assertSame(['3 un · 0,6', '1 un · 0,1'], $cartao->filter('.vcResultado .vcItem > .vcItemValor')->each(static fn (Crawler $c): string => $c->text()));
        self::assertSame('Abstenções 1 · Nulos 0', $cartao->filter('.vcResultado .vcAbstencoes')->text());
        self::assertSame('Resultado: 1 Sim', $cartao->filter('.vcResultado .vcDecisao')->text());
        self::assertCount(0, $cartao->filter('form'));
    }

    #[TestDox('Prazo vencido: a primeira leitura grava o encerramento ("Prazo definido pela mesa") e mostra a pauta encerrada')]
    public function testPrazoVencidoEncerraNaLeitura(): void
    {
        $vencida = PautaDaAssembleiaFactory::new()->comOpcoesPadrao()->aberta(1, new \DateTimeImmutable('-1 minute'))
            ->create(['assembleia' => $this->assembleia, 'ordem' => 2, 'titulo' => 'Troca do portão'])->_real();
        $id = $vencida->getId();
        $this->em->clear();

        $crawler = $this->client->request('GET', $this->link());

        self::assertSame('Encerrada', $crawler->filter('.vcMain > .vC.vcPauta')->eq(1)->filter('.vChip')->text());
        $this->em->clear();
        $relida = $this->em->find(PautaDaAssembleia::class, $id);
        self::assertSame(PautaDaAssembleia::MOTIVO_DO_PRAZO, $relida?->getMotivoEncerramento());
    }

    #[TestDox('Pauta aberta com prazo: "Encerra em mm:ss." com os segundos para o contador da página')]
    public function testPrazoCorrendo(): void
    {
        PautaDaAssembleiaFactory::new()->comOpcoesPadrao()->aberta(1, new \DateTimeImmutable('+5 minutes'))
            ->create(['assembleia' => $this->assembleia, 'ordem' => 2, 'titulo' => 'Troca do portão']);

        $crawler = $this->client->request('GET', $this->link());

        $prazo = $crawler->filter('.vcMain > .vC.vcPauta')->eq(1)->filter('[data-vc-prazo]');
        self::assertMatchesRegularExpression('/^Encerra em 0[45]:\d{2}\.$/', $prazo->filter('[data-vc-prazo-texto]')->text());
        self::assertGreaterThan(200, (int) $prazo->attr('data-vc-prazo'));
        self::assertSame('Encerra em {t}.', $prazo->attr('data-vc-modelo'));
    }

    private function link(): string
    {
        return '/votar/' . UnidadeDaAssembleiaFactory::tokenDe($this->unidade);
    }

    private function opcao(int $numero): OpcaoDaPauta
    {
        foreach ($this->pauta->getOpcoesDeVoto() as $opcao) {
            if ($opcao->getNumero() === $numero) {
                return $opcao;
            }
        }
        self::fail('Opção ' . $numero . ' não existe.');
    }

    /** @return array{int, string} [unidades, peso] relidos do banco */
    private function contador(OpcaoDaPauta $opcao): array
    {
        $linha = $this->em->getConnection()->fetchAssociative(
            'SELECT total_unidades, total_peso FROM voto.assembleia_opcao WHERE id = :id',
            ['id' => $opcao->getId()],
        );
        self::assertIsArray($linha);

        return [(int) $linha['total_unidades'], (string) $linha['total_peso']];
    }

    private function liberar(Tenant $tenant): void
    {
        $liberacao = new LiberacaoDoVoto($tenant);
        $liberacao->liberar();
        $this->em->persist($liberacao);
        $this->em->flush();
    }

    /** O banco de teste pode ter a chave `voto` desligada (migration do recurso): cada teste parte dela ligada. */
    private function ligarOVoto(): void
    {
        $estado = $this->em->getRepository(RecursoEstado::class)->findOneBy(['chave' => 'voto']);
        if (null !== $estado) {
            $estado->reativar(null, new \DateTimeImmutable());
            $this->em->flush();
        }
    }
}
