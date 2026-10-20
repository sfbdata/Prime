<?php

declare(strict_types=1);

namespace App\Tests\Contribua\Functional;

use App\Contribua\Controller\RelatorioDeContribuicoesController;
use App\Contribua\Entity\Apoio;
use App\Contribua\Repository\ApoioRepository;
use App\Contribua\Repository\IntegracaoBancariaRepository;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Relatório de contribuições no Painel do Master (desenho 08, L253). Só o Master; a chave `rel` da Integração
 * bancária liga e desliga; só confirmados nos totais; nada de e-mail ou CNPJ; arranjo do cartão no painel
 * provado por combinador de filho direto.
 */
#[CoversClass(RelatorioDeContribuicoesController::class)]
#[CoversClass(ApoioRepository::class)]
final class RelatorioDeContribuicoesControllerTest extends JusPrimeWebTestCase
{
    private const URL = '/contribua/relatorio-do-master';

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
    }

    /** @return iterable<string, array{bool}> */
    public static function deEscritorio(): iterable
    {
        yield 'primário' => [true];
        yield 'secundário' => [false];
    }

    #[DataProvider('deEscritorio')]
    #[TestDox('Quem não é Master recebe 403 (primário e secundário de escritório)')]
    public function testQuemNaoEhMasterRecebe403(bool $primario): void
    {
        $this->ligarRelatorio(true);
        $this->logarDeEscritorio($primario);

        $this->client->request('GET', self::URL);

        self::assertResponseStatusCodeSame(403);
    }

    #[TestDox('Anônimo vai para o login')]
    public function testAnonimoVaiParaLogin(): void
    {
        $this->client->request('GET', self::URL);

        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $this->client->getResponse()->headers->get('Location'));
    }

    #[TestDox('Master: 200, totais em R$ só dos confirmados, conta excluída marcada, sem e-mail nem CNPJ')]
    public function testMasterVeOsTotais(): void
    {
        $this->ligarRelatorio(true);
        $this->semearApoios();
        $this->logarMaster();

        $crawler = $this->client->request('GET', self::URL);

        self::assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();
        self::assertSame('R$ 1.350,00', trim($crawler->filter('[data-cr-total]')->text()), '1.000,00 + 250,00 + 100,00; o informado de 70,00 fica fora');
        self::assertCount(12, $crawler->filter('.cr-meses > .cr-mes'));
        $linhas = $crawler->filter('.cr-tabela > tbody > tr');
        self::assertSame(['Gama Excluída Advocacia EXCLUÍDO', 'R$ 1.000,00', '1'], $this->celulas($linhas->eq(0), 3));
        self::assertSame(['Alfa Advocacia', 'R$ 350,00', '2'], $this->celulas($linhas->eq(1), 3));
        self::assertCount(2, $linhas, 'a conta que só informou não entra na tabela');
        self::assertStringContainsString('Informados, não confirmados (últimos 12 meses): <b>R$ 70,00</b>', $html);
        self::assertStringNotContainsString('@', $html);
        self::assertStringNotContainsString('12345678000199', $html);
        self::assertDoesNotMatchRegularExpression('/[—–]/u', $html);
    }

    #[TestDox('Mês de cada apoio: o do pagamento (não o do registro), virada do mês em São Paulo e nada depois do mês atual')]
    public function testMesDeCadaApoio(): void
    {
        $this->ligarRelatorio(true);
        $conta = $this->escritorio('Delta Advocacia');
        $inicio = (new \DateTimeImmutable('first day of this month'))->setTime(0, 0);
        // Registrado ("Já contribuí") há 3 meses e pago pelo banco no mês passado.
        $informadoDepoisPago = new Apoio($conta, null, 5000);
        (new \ReflectionProperty(Apoio::class, 'criadoEm'))->setValue($informadoDepoisPago, $inicio->modify('-3 months')->setTime(10, 0));
        $informadoDepoisPago->confirmarPeloBanco(30000, 'cora', 'inv_d1', 'BJ9-1', $inicio->modify('-1 month')->setTime(9, 0));
        $this->em->persist($informadoDepoisPago);
        // Virada do mês: 1º dia às 00:30 e último dia do mês anterior às 23:30 (horário de São Paulo).
        $this->em->persist(Apoio::confirmadoPeloBanco($conta, 11100, 'cora', 'inv_d2', 'BJ9-2', $inicio->modify('-4 months')->setTime(0, 30)));
        $this->em->persist(Apoio::confirmadoPeloBanco($conta, 22200, 'cora', 'inv_d3', 'BJ9-3', $inicio->modify('-4 months')->modify('-1 day')->setTime(23, 30)));
        // No 1º dia do mês seguinte ao atual: fora da grade (limite superior), mas no total da conta.
        $this->em->persist(Apoio::confirmadoPeloBanco($conta, 99900, 'cora', 'inv_d4', 'BJ9-4', $inicio->modify('+1 month')));
        $this->em->flush();
        $this->logarMaster();

        $crawler = $this->client->request('GET', self::URL);

        self::assertResponseIsSuccessful();
        $valor = static fn (\DateTimeImmutable $mes): string => trim($crawler->filter('.cr-meses > .cr-mes[data-mes="' . $mes->format('Y-m') . '"] > b')->text());
        self::assertSame('R$ 300,00', $valor($inicio->modify('-1 month')), 'mês do confirmado_em');
        self::assertSame('R$ 0,00', $valor($inicio->modify('-3 months')), 'o mês do criado_em fica zerado');
        self::assertSame('R$ 111,00', $valor($inicio->modify('-4 months')), '1º dia às 00:30');
        self::assertSame('R$ 222,00', $valor($inicio->modify('-5 months')), 'último dia às 23:30');
        self::assertCount(0, $crawler->filter('.cr-mes[data-mes="' . $inicio->modify('+1 month')->format('Y-m') . '"]'));
        self::assertStringContainsString('R$ 633,00', $crawler->filter('.cr-rotulo')->text(), 'o total da grade é o do período mostrado');
        self::assertSame('R$ 1.632,00', trim($crawler->filter('[data-cr-total]')->text()));
    }

    #[TestDox('Contas na ordem do banco: maior soma primeiro; no empate, pelo nome respeitando acentos')]
    public function testOrdemDasContas(): void
    {
        $this->ligarRelatorio(true);
        $agora = new \DateTimeImmutable();
        foreach ([['Beta Advocacia', 10000], ['Ágora Advocacia', 10000], ['Zeta Advocacia', 50000]] as $n => [$nome, $valor]) {
            $this->em->persist(Apoio::confirmadoPeloBanco($this->escritorio($nome), $valor, 'cora', 'inv_o' . $n, 'BJ8-' . $n, $agora));
        }
        $this->em->flush();
        $this->logarMaster();

        $crawler = $this->client->request('GET', self::URL);

        $nomes = $crawler->filter('.cr-tabela > tbody > tr > td:first-child')->each(static fn (Crawler $td): string => trim($td->text()));
        self::assertSame(['Zeta Advocacia', 'Ágora Advocacia', 'Beta Advocacia'], $nomes);
    }

    #[TestDox('Master sem apoio confirmado: "Nenhuma contribuição confirmada ainda."')]
    public function testEstadoVazio(): void
    {
        $this->ligarRelatorio(true);
        $this->logarMaster();

        $crawler = $this->client->request('GET', self::URL);

        self::assertResponseIsSuccessful();
        self::assertSame('Nenhuma contribuição confirmada ainda.', trim($crawler->filter('[data-cr-vazio]')->text()));
        self::assertCount(0, $crawler->filter('.cr-tabela'));
    }

    #[TestDox('Chave "rel" desligada: o cartão diz que está desligado e leva à Integração bancária, sem valores')]
    public function testChaveDesligada(): void
    {
        $this->ligarRelatorio(false);
        $this->semearApoios();
        $this->logarMaster();

        $crawler = $this->client->request('GET', self::URL);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Relatório de contribuições desligado na Integração bancária.', $crawler->filter('[data-cr-desligado]')->text());
        self::assertSame('/contribua#integracao', $crawler->filter('[data-cr-desligado] > a')->attr('href'));
        self::assertStringNotContainsString('R$', (string) $this->client->getResponse()->getContent());
    }

    #[TestDox('Integração bancária nunca salva: o cartão diz que está desligado')]
    public function testSemIntegracao(): void
    {
        $this->logarMaster();

        $crawler = $this->client->request('GET', self::URL);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('a Integração bancária ainda não foi configurada', $crawler->filter('[data-cr-desligado]')->text());
    }

    #[TestDox('Tela: o cartão fica no Painel do sistema, depois da seção, com cabeçalho, grade de 12 meses e tabela (filho direto)')]
    public function testArranjoNoPainel(): void
    {
        $this->ligarRelatorio(true);
        $this->semearApoios();
        $this->logarMaster();

        $crawler = $this->client->request('GET', '/master');

        self::assertResponseIsSuccessful();
        $cartao = '#master-painel > .mp-painel[data-painel-aba="painel"] > #mp-secao ~ section.mp-card.cr-relatorio';
        self::assertCount(1, $crawler->filter($cartao));
        self::assertStringContainsString('Contribuições', $crawler->filter($cartao . ' > .mp-cab-cinza > b')->text());
        self::assertCount(1, $crawler->filter($cartao . ' > .mp-cab-cinza > .cr-total > b[data-cr-total]'));
        self::assertCount(12, $crawler->filter($cartao . ' > .cr-corpo > .cr-meses > .cr-mes > .cr-mes-barra > i'));
        self::assertCount(2, $crawler->filter($cartao . ' > .cr-corpo > table.cr-tabela > tbody > tr'));
        self::assertCount(1, $crawler->filter('link[href$="/css/contribua-relatorio.css"]'));
        self::assertCount(1, $crawler->filter('script[src$="/js/contribua-relatorio.js"]'));
    }

    /**
     * Alfa: dois confirmados (250,00 neste mês e 100,00 há dois meses) e um informado de 70,00, não confirmado.
     * Gama (excluída): um confirmado de 1.000,00. Beta: só um informado de 40,00, de mais de um ano atrás.
     */
    private function semearApoios(): void
    {
        $alfa = $this->escritorio('Alfa Advocacia');
        $gama = $this->escritorio('Gama Excluída Advocacia')->setExcluidoEm(new \DateTimeImmutable('-3 days'));
        $beta = $this->escritorio('Beta Advocacia');
        $agora = new \DateTimeImmutable();
        $this->em->persist(Apoio::confirmadoPeloBanco($alfa, 25000, 'cora', 'inv_a1', 'BJ1-1', $agora));
        $this->em->persist(Apoio::confirmadoPeloBanco($alfa, 10000, 'cora', 'inv_a2', 'BJ1-2', $agora->modify('first day of this month')->modify('-2 months')));
        $this->em->persist(Apoio::confirmadoPeloBanco($gama, 100000, 'cora', 'inv_g1', 'BJ2-1', $agora->modify('-1 day')));
        $this->em->persist(new Apoio($alfa, null, 7000));
        $antigo = new Apoio($beta, null, 4000);
        (new \ReflectionProperty(Apoio::class, 'criadoEm'))->setValue($antigo, $agora->modify('-14 months'));
        $this->em->persist($antigo);
        $this->em->flush();
    }

    private function escritorio(string $nome): Tenant
    {
        $tenant = (new Tenant())->setName($nome)->setCnpj('12345678000199');
        $this->em->persist($tenant);

        return $tenant;
    }

    private function ligarRelatorio(bool $ligado): void
    {
        $integracoes = static::getContainer()->get(IntegracaoBancariaRepository::class);
        $integracao = $integracoes->atual();
        $integracao->configurar('sandbox', 'cliente-teste', ['rel' => $ligado], $this->usuario(['ROLE_SUPER_ADMIN']));
        $integracoes->salvar($integracao);
    }

    private function logarMaster(): void
    {
        $this->client->loginUser($this->usuario(['ROLE_SUPER_ADMIN', 'ROLE_MASTER_PRIMARIO']));
        $this->marcarTermosAceitos($this->client);
    }

    private function logarDeEscritorio(bool $primario): void
    {
        $tenant = $this->escritorio('Escritório Relatório ' . uniqid());
        $papel = (new TenantRole())->setTenant($tenant)->setName($primario ? 'Administrador do Escritório' : 'Colaborador')->setIsSystem($primario);
        $this->em->persist($papel);
        $user = $this->usuario(['ROLE_USER']);
        $vinculo = new UserTenant($user, $tenant);
        $vinculo->setTenantRole($papel);
        $this->em->persist($vinculo);
        if ($primario) {
            $tenant->setCriadoPor($user);
        }
        $this->em->flush();
        $this->logarComTenant($this->client, $user, $tenant);
    }

    /** @param list<string> $papeis */
    private function usuario(array $papeis): User
    {
        $u = new User();
        $u->setEmail('relatorio_' . uniqid() . '@teste.com');
        $u->setFullName('Usuário Relatório');
        $u->setRoles($papeis);
        $u->setIsActive(true);
        $this->em->persist($u);
        $this->em->flush();

        return $u;
    }

    /** @return list<string> */
    private function celulas(Crawler $linha, int $quantas): array
    {
        return array_slice($linha->filter('td')->each(static fn (Crawler $td): string => trim((string) preg_replace('/\s+/', ' ', $td->text()))), 0, $quantas);
    }
}
