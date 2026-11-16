<?php

declare(strict_types=1);

namespace App\Tests\Contribua\Functional;

use App\Contribua\DTO\RetratoDoEspaco;
use App\Contribua\Entity\EspacoDoEscritorio;
use App\Contribua\Entity\PedidoDeEspaco;
use App\Contribua\Enum\OrigemDaLiberacao;
use App\Contribua\Repository\ConsumoDeArmazenamentoRepository;
use App\Contribua\Repository\EspacoDoEscritorioRepository;
use App\Contribua\Repository\PlanoRepository;
use App\Contribua\Twig\AvisoDeEspacoExtension;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Avisos de espaço em todas as telas (desenho Contribua 14 e 15/11, etapa 5): barra do escritório (A9), modais
 * "Solicitar mais espaço" e "Pedido em análise" (N6), pop-up dos 97% com a marca `auto` (N8) e barra do Master com
 * pedidos pendentes (N9). Fora da Contribua a barra aparece; os modais e o pop-up valem em toda tela, inclusive nela.
 */
#[CoversClass(AvisoDeEspacoExtension::class)]
final class AvisosDeEspacoNasTelasTest extends JusPrimeWebTestCase
{
    private const OUTRA_TELA = '/novidades';
    private const BARRA = '[data-aviso-espaco]';
    private const BARRA_DO_MASTER = '[data-aviso-pedidos-espaco]';
    private const RAIZ = '[data-espaco-raiz]';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Tenant $escritorio;
    private Tenant $outro;
    private User $primario;
    private User $secundario;
    private User $master;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->escritorio = $this->tenant('A');
        $this->outro = $this->tenant('B');
        $this->primario = $this->pessoa('primario', $this->escritorio, true);
        $this->secundario = $this->pessoa('secundario', $this->escritorio, false);
        $this->master = $this->pessoa('master', $this->escritorio, false, ['ROLE_SUPER_ADMIN']);
    }

    #[TestDox('A9: primário acima da faixa de alerta vê a barra do desenho, sem "no plano", "Recomendamos" nem "Aumentar espaço"')]
    public function testBarraDoPrimario(): void
    {
        $this->usar(0.93);
        $crawler = $this->abrir($this->primario, self::OUTRA_TELA);

        $barra = $crawler->filter(self::BARRA);
        self::assertCount(1, $barra);
        $texto = $barra->text();
        self::assertStringContainsString('Seu espaço está 93% ocupado', $texto);
        self::assertStringContainsString('9,3 GB de 10 GB. Faltam 0,7 GB. Se precisar, solicite mais espaço.', $texto);
        self::assertStringNotContainsString('plano', $texto);
        self::assertStringNotContainsString('Recomendamos', $texto);
        self::assertStringNotContainsString('Aumentar espaço', $texto);
        self::assertStringNotContainsString('pausados', $texto, 'D6: nada de pausa na barra');
        self::assertSame('Solicitar mais espaço', trim($crawler->filter(self::BARRA . ' button[data-espaco-solicitar]')->text()));
        self::assertCount(1, $crawler->filter(self::BARRA . ' [data-fechar-aviso-espaco]'), 'o × existe sempre');
    }

    #[TestDox('A9: abaixo da faixa de alerta não há barra, e o secundário nunca vê barra nem modais')]
    public function testSemBarraAbaixoDaFaixaEParaSecundario(): void
    {
        $this->usar(0.80);
        self::assertCount(0, $this->abrir($this->primario, self::OUTRA_TELA)->filter(self::BARRA));

        $this->usar(0.99);
        $crawler = $this->abrir($this->secundario, self::OUTRA_TELA);
        self::assertCount(0, $crawler->filter(self::BARRA));
        self::assertCount(0, $crawler->filter(self::RAIZ));
        self::assertCount(0, $crawler->filter('[data-es-modal]'));
    }

    #[TestDox('A9: na tela Contribua a barra não aparece, mas o modal e o pop-up estão lá')]
    public function testNaContribuaSemBarraMasComModal(): void
    {
        $this->usar(0.93);
        $crawler = $this->abrir($this->primario, '/contribua');

        self::assertCount(0, $crawler->filter(self::BARRA));
        self::assertCount(1, $crawler->filter(self::RAIZ . ' [data-es-modal="solicitar"]'));
        self::assertCount(1, $crawler->filter(self::RAIZ . ' [data-es-modal="pedido"]'));
    }

    #[TestDox('A9: pedido pendente troca título, sub e botão ("Ver pedido"); opções liberadas pelo Master trocam para "Ver opções"')]
    public function testTextosPorEstado(): void
    {
        $this->usar(0.93);
        $this->em->persist(new PedidoDeEspaco($this->escritorio, $this->primario, 20, null, 9, 10, null));
        $this->em->flush();

        $crawler = $this->abrir($this->primario, self::OUTRA_TELA);
        $texto = $crawler->filter(self::BARRA)->text();
        self::assertStringContainsString('Seu pedido de mais espaço está em análise', $texto);
        self::assertMatchesRegularExpression('#Enviado em \d{2}/\d{2}/\d{4} \d{2}:\d{2}\. Avisaremos por e-mail\.#', $texto);
        self::assertSame('Ver pedido', trim($crawler->filter(self::BARRA . ' button[data-espaco-ver-pedido]')->text()));
        self::assertSame('pendente', $crawler->filter(self::RAIZ)->attr('data-estado'));
        self::assertStringContainsString('Pedido em análise', $crawler->filter('[data-es-modal="pedido"]')->text());

        $this->liberarPeloMaster();
        $crawler = $this->abrir($this->primario, self::OUTRA_TELA);
        self::assertStringContainsString('As opções de espaço extra já estão liberadas para você.', $crawler->filter(self::BARRA)->text());
        $ver = $crawler->filter(self::BARRA . ' a.ae-aumentar');
        self::assertSame('Ver opções', trim($ver->text()));
        self::assertSame('/contribua#espaco', $ver->attr('href'));
        self::assertSame('liberado', $crawler->filter(self::RAIZ)->attr('data-estado'));
    }

    #[TestDox('N6: o modal tem 3 opções (20, 50, 100 GB), motivo de até 300 com o placeholder do desenho, notas e a rota do POST')]
    public function testModalSolicitar(): void
    {
        $this->usar(0.50);
        $crawler = $this->abrir($this->primario, self::OUTRA_TELA);

        $modal = $crawler->filter('[data-es-modal="solicitar"]');
        self::assertSame(['20', '50', '100'], $modal->filter('input[type="radio"][name="gb"]')->each(static fn ($n): string => (string) $n->attr('value')));
        self::assertCount(1, $modal->filter('input[value="20"][checked]'));
        $motivo = $modal->filter('textarea[name="motivo"]');
        self::assertSame('300', $motivo->attr('maxlength'));
        self::assertSame('Ex.: digitalização dos processos antigos do escritório', $motivo->attr('placeholder'));
        $texto = $modal->text();
        foreach (['O BlueJus é gratuito', 'Hoje você usa 5 GB de 10 GB (50%).', 'Quanto espaço você imagina precisar?', 'Até 20 GB', 'Até 50 GB', 'Mais de 50 GB', 'Para que vai usar (opcional)', 'Solicitar não gera cobrança', 'Cancelar', 'Enviar pedido', 'Pedido enviado'] as $esperado) {
            self::assertStringContainsString($esperado, $texto, $esperado);
        }
        $raiz = $crawler->filter(self::RAIZ);
        self::assertSame('/contribua/espaco/solicitar', $raiz->attr('data-url-solicitar'));
        self::assertNotSame('', (string) $raiz->attr('data-token'));
        self::assertSame('nenhum', $raiz->attr('data-estado'));
        self::assertCount(0, $crawler->filter(self::BARRA), 'a 50% não há barra, mas o modal está lá para o botão da Contribua');
    }

    #[TestDox('N8: a 97% o pop-up abre com o texto do desenho (dentro e fora da Contribua) e a marca `auto` é gravada')]
    public function testPopupDos97EMarcaAuto(): void
    {
        $this->usar(0.97);
        self::assertNull($this->marca());

        $crawler = $this->abrir($this->primario, self::OUTRA_TELA);
        $popup = $crawler->filter('[data-es-modal="popup"]');
        self::assertCount(1, $popup);
        $texto = $popup->text();
        self::assertStringContainsString('Atenção: seu espaço chegou a 97%', $texto);
        self::assertStringContainsString('9,7 GB de 10 GB usados', $texto);
        self::assertStringContainsString('Faltam 0,3 GB.', $texto);
        self::assertStringContainsString('Liberamos automaticamente as opções de espaço extra para o seu escritório. O BlueJus continua gratuito; o espaço extra é opcional.', $texto);
        self::assertStringContainsString('Agora não', $texto);
        self::assertSame('/contribua#espaco', $popup->filter('a')->attr('href'));
        self::assertSame(OrigemDaLiberacao::Auto, $this->marca());

        self::assertCount(1, $this->abrir($this->primario, '/contribua')->filter('[data-es-modal="popup"]'), 'vale dentro da Contribua');
    }

    #[TestDox('N8: abaixo de 97% não há pop-up nem marca; o secundário nunca recebe pop-up nem grava marca')]
    public function testSemPopupAbaixoDe97(): void
    {
        $this->usar(0.96);
        self::assertCount(0, $this->abrir($this->primario, self::OUTRA_TELA)->filter('[data-es-modal="popup"]'));
        self::assertNull($this->marca());

        $this->usar(0.99);
        self::assertCount(0, $this->abrir($this->secundario, self::OUTRA_TELA)->filter('[data-es-modal="popup"]'));
        self::assertNull($this->marca());
    }

    #[TestDox('N8/D6: a 100% o pop-up fica vermelho e "cheio"; sem o bloqueio ligado não promete envios pausados')]
    public function testPopupCheioSemPausaComBloqueioDesligado(): void
    {
        $this->usar(1.0);
        $popup = $this->abrir($this->primario, self::OUTRA_TELA)->filter('[data-es-modal="popup"]');

        self::assertCount(1, $popup->filter('.es-topo--cheio'));
        self::assertStringContainsString('Seu espaço está cheio', $popup->text());
        self::assertStringContainsString('Consultar e baixar arquivos continua liberado.', $popup->text());
        self::assertStringNotContainsString('pausados', $popup->text());
        self::assertStringNotContainsString('ficam pausados', $this->abrir($this->primario, self::OUTRA_TELA)->filter(self::BARRA)->text());
    }

    #[TestDox('N9: o Master vê a barra com os pedidos pendentes de todos os escritórios, fora da Contribua; o primário comum não')]
    public function testBarraDoMaster(): void
    {
        $this->usar(0.10);
        $this->em->persist(new PedidoDeEspaco($this->outro, null, 50, null, 9, 10, null));
        $this->em->persist(new PedidoDeEspaco($this->escritorio, $this->primario, 20, null, 9, 10, null));
        $this->em->flush();

        $crawler = $this->abrir($this->master, self::OUTRA_TELA);
        $barra = $crawler->filter(self::BARRA_DO_MASTER);
        self::assertCount(1, $barra);
        self::assertStringContainsString('2 pedido(s) de espaço aguardando você', $barra->text());
        self::assertStringContainsString('Escritórios pediram a liberação das opções de espaço extra.', $barra->text());
        self::assertSame('/contribua#espaco', $barra->filter('a')->attr('href'));
        self::assertSame('Analisar pedidos', trim($barra->filter('a')->text()));
        self::assertCount(1, $barra->filter('[data-fechar-aviso-pedidos]'));

        self::assertCount(0, $this->abrir($this->master, '/contribua')->filter(self::BARRA_DO_MASTER), 'nem na Contribua');
        self::assertCount(0, $this->abrir($this->primario, self::OUTRA_TELA)->filter(self::BARRA_DO_MASTER));
    }

    #[TestDox('N9: sem pedido pendente não há barra do Master; com a própria barra de espaço, o JS dá a prioridade à dos pedidos')]
    public function testMasterSemPedidoEPrioridade(): void
    {
        $this->usar(0.93);
        self::assertCount(0, $this->abrir($this->master, self::OUTRA_TELA)->filter(self::BARRA_DO_MASTER));

        $masterPrimario = $this->pessoa('masterprimario', $this->escritorio, true, ['ROLE_SUPER_ADMIN']);
        $this->em->persist(new PedidoDeEspaco($this->outro, null, 20, null, 9, 10, null));
        $this->em->flush();
        $crawler = $this->abrir($masterPrimario, self::OUTRA_TELA);
        self::assertCount(1, $crawler->filter(self::BARRA_DO_MASTER));
        self::assertCount(1, $crawler->filter(self::BARRA));

        $js = (string) file_get_contents(__DIR__ . '/../../../public/js/aviso-espaco.js');
        self::assertGreaterThan(strpos($js, 'mostrar(barraMaster)'), strpos($js, 'mostrar(barra)'), 'a barra do Master vem primeiro, com return');
        self::assertGreaterThan(strpos($js, 'mostrar(popup'), strpos($js, 'mostrar(barra)'), 'o pop-up vem antes da barra do escritório');
    }

    /** Bytes em uso que o stub devolve; o stub entra uma vez só (o container recusa trocar serviço já usado). */
    private int $bytesEmUso = 0;

    private bool $consumoTrocado = false;

    private function usar(float $fracao): void
    {
        $this->bytesEmUso = (int) round($fracao * 10 * RetratoDoEspaco::BYTES_POR_GB);
        if ($this->consumoTrocado) {
            return;
        }
        $consumo = $this->createStub(ConsumoDeArmazenamentoRepository::class);
        $consumo->method('bytesDoEscritorio')->willReturnCallback(fn (): int => $this->bytesEmUso);
        static::getContainer()->set(ConsumoDeArmazenamentoRepository::class, $consumo);
        $this->consumoTrocado = true;
    }

    private function marca(): ?OrigemDaLiberacao
    {
        $this->em->clear();

        return static::getContainer()->get(EspacoDoEscritorioRepository::class)->doTenant((int) $this->escritorio->getId())?->getOpcoesLiberadasPor();
    }

    private function liberarPeloMaster(): void
    {
        $espaco = new EspacoDoEscritorio($this->escritorio, static::getContainer()->get(PlanoRepository::class)->free());
        $espaco->liberarOpcoes(OrigemDaLiberacao::Master, new \DateTimeImmutable());
        $this->em->persist($espaco);
        $this->em->flush();
    }

    private function abrir(User $quem, string $url): \Symfony\Component\DomCrawler\Crawler
    {
        $this->logarComTenant($this->client, $quem, $this->escritorio);

        return $this->client->request('GET', $url);
    }

    private function tenant(string $nome): Tenant
    {
        $tenant = new Tenant();
        $tenant->setName('Escritório AVISO ' . $nome . ' ' . uniqid());
        $this->em->persist($tenant);
        $this->em->flush();

        return $tenant;
    }

    /** @param list<string> $roles */
    private function pessoa(string $prefixo, Tenant $tenant, bool $primario, array $roles = ['ROLE_USER']): User
    {
        $user = new User();
        $user->setEmail($prefixo . '_' . uniqid() . '@test.com');
        $user->setFullName('Pessoa ' . ucfirst($prefixo));
        $user->setRoles($roles);
        $user->setIsActive(true);
        $user->setPassword('x');
        $this->em->persist($user);

        $papel = new TenantRole();
        $papel->setTenant($tenant);
        $papel->setName(($primario ? 'Primário ' : 'Equipe ') . uniqid());
        $papel->setIsSystem($primario);
        $this->em->persist($papel);

        $vinculo = new UserTenant($user, $tenant);
        $vinculo->setTenantRole($papel);
        $this->em->persist($vinculo);
        $this->em->flush();

        return $user;
    }
}
