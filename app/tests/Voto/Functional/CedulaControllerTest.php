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
        self::assertSame(['1 Sim', '2 Não', 'ABSTENÇÃO'], $cartao->filter('form[data-vc-votar] .vcOpcoes > button.vB')->each(
            static fn (Crawler $b): string => (string) $b->attr('data-vc-escolha'),
        ));
        self::assertCount(1, $crawler->filter('body > .vMod[data-vc-janela][hidden] > .vModC > .vH [data-vc-fechar]'));
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
        self::assertCount(3, $crawler->filter('form[data-vc-votar] button[name="opcao"]'));
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
        $crawler = $this->client->request('GET', $this->link());
        $form = $crawler->filter('form[data-vc-votar]');
        $sim = $this->opcao(1);

        $this->client->request('POST', (string) $form->attr('action'), [
            '_token' => $form->filter('input[name="_token"]')->attr('value'),
            'opcao' => (string) $sim->getId(),
        ]);

        self::assertResponseRedirects($this->link());
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));
        $depois = $this->client->followRedirect();
        self::assertSame('Voto registrado.', $depois->filter('body > .vcToast[data-vc-toast]')->text());
        self::assertStringStartsWith('Você já votou nesta pauta.', $depois->filter('.vcPauta [data-vc-votou]')->text());
        self::assertMatchesRegularExpression('/Registrado às \d{2}:\d{2}\./', $depois->filter('.vcPauta [data-vc-votou] .vcHora')->text());
        self::assertCount(0, $depois->filter('form[data-vc-votar]'), 'quem votou não vê mais as opções');
        self::assertSame([1, '0.025000'], $this->contador($sim));
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
        self::assertCount(1, $depois->filter('form[data-vc-votar]'));
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
        self::assertCount(0, $crawler->filter('form[data-vc-votar]'));
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
