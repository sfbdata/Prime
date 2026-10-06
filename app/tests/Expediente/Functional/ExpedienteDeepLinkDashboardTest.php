<?php

declare(strict_types=1);

namespace App\Tests\Expediente\Functional;

use App\Dashboard\DTO\DashboardOutput;
use App\Dashboard\DTO\LinhaAdvogadoDashboardOutput;
use App\Dashboard\UseCase\ObterDadosDashboardUseCase;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Expediente\Controller\ExpedienteController;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PrioridadePasta;
use App\Pasta\Repository\PastaRepository;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Os números de PASTAS do Dashboard (Pastas criadas, Total demandas, Demandas ativas e o card
 * Urgentes) viram links para o Acervo do Expediente com o filtro equivalente. O contrato que
 * importa é "o número clicado = o tamanho da lista": cada teste monta um cenário com as pegadinhas
 * (lápide, pasta fora do período, criador ≠ responsável) e compara a lista do acervo com o que o
 * ObterDadosDashboardUseCase devolve para o MESMO filtro.
 */
#[CoversClass(ExpedienteController::class)]
#[CoversClass(PastaRepository::class)]
final class ExpedienteDeepLinkDashboardTest extends JusPrimeWebTestCase
{
    private const DATA_DE  = '2024-03-01';
    private const DATA_ATE = '2024-03-31';

    #[TestDox('criado_por: a lista do acervo tem o mesmo tamanho da coluna Pastas criadas do Dashboard, sem a lápide')]
    public function testCriadoPorBateComPastasCriadasDoDashboard(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $admin  = $this->criarUsuario($tenant, 'Admin Criado', admin: true);
        $ana    = $this->criarUsuario($tenant, 'Ana Criadora');
        $bruno  = $this->criarUsuario($tenant, 'Bruno Outro');

        $s       = strtoupper(uniqid());
        $dentro  = new \DateTimeImmutable('2024-03-10 09:00:00');
        $a1      = $this->criarPasta($tenant, 'CP-A1-' . $s, criadoPor: $ana, dataAbertura: $dentro);
        // Ana abriu, Bruno responde: conta para Ana (critério é o criador).
        $a2      = $this->criarPasta($tenant, 'CP-A2-' . $s, criadoPor: $ana, responsavel: $bruno, dataAbertura: $dentro);
        $lapide  = $this->criarPasta($tenant, 'CP-LAPIDE-' . $s, criadoPor: $ana, dataAbertura: $dentro);
        $lapide->marcarExcluida($admin, new \DateTimeImmutable());
        $fora    = $this->criarPasta($tenant, 'CP-FORA-' . $s, criadoPor: $ana, dataAbertura: new \DateTimeImmutable('2024-05-10'));
        $deBruno = $this->criarPasta($tenant, 'CP-B-' . $s, criadoPor: $bruno, responsavel: $ana, dataAbertura: $dentro);
        $this->em()->flush();

        $filtros = ['data_de' => self::DATA_DE, 'data_ate' => self::DATA_ATE];
        $linha   = $this->linhaDoDashboard($tenant, $filtros, $ana);

        $this->logarComTenant($client, $admin, $tenant);
        $crawler = $client->xmlHttpRequest('GET', '/expediente/painel/acervo-geral?' . http_build_query(
            $filtros + ['criado_por' => $ana->getId()],
        ));

        self::assertResponseIsSuccessful();
        self::assertSame(2, $linha->pastasCriadas, 'pré-condição: o Dashboard conta 2 para a Ana');
        self::assertSame($linha->pastasCriadas, $this->contarLinhas($crawler), 'número clicado = tamanho da lista');

        $nups = $this->nupsListados($crawler);
        self::assertContains($a1->getNup(), $nups);
        self::assertContains($a2->getNup(), $nups);
        self::assertNotContains($lapide->getNup(), $nups, 'lápide não conta no Dashboard, não pode aparecer na lista');
        self::assertNotContains($fora->getNup(), $nups);
        self::assertNotContains($deBruno->getNup(), $nups, 'responsável não é criador');
    }

    #[TestDox('sem criado_por o acervo segue listando a lápide riscada (comportamento de sempre)')]
    public function testSemCriadoPorAcervoContinuaListandoLapide(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $admin  = $this->criarUsuario($tenant, 'Admin Lapide', admin: true);

        $s      = strtoupper(uniqid());
        $lapide = $this->criarPasta($tenant, 'SEMCP-LAPIDE-' . $s, criadoPor: $admin);
        $lapide->marcarExcluida($admin, new \DateTimeImmutable());
        $this->em()->flush();

        $this->logarComTenant($client, $admin, $tenant);
        $crawler = $client->xmlHttpRequest('GET', '/expediente/painel/acervo-geral');

        self::assertResponseIsSuccessful();
        self::assertContains($lapide->getNup(), $this->nupsListados($crawler));
    }

    #[TestDox('responsavel + período: a lista bate com Total demandas da linha (lápide conta dos dois lados)')]
    public function testResponsavelBateComTotalDemandas(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $admin  = $this->criarUsuario($tenant, 'Admin Demandas', admin: true);
        $ana    = $this->criarUsuario($tenant, 'Ana Demandas');

        $s      = strtoupper(uniqid());
        $dentro = new \DateTimeImmutable('2024-03-15 10:00:00');
        $this->criarPasta($tenant, 'TD-ATIVA-' . $s, responsavel: $ana, dataAbertura: $dentro);
        $arq = $this->criarPasta($tenant, 'TD-ARQ-' . $s, responsavel: $ana, dataAbertura: $dentro);
        $arq->setSituacao(Pasta::SITUACAO_ARQUIVADA);
        $lap = $this->criarPasta($tenant, 'TD-LAP-' . $s, responsavel: $ana, dataAbertura: $dentro);
        $lap->marcarExcluida($admin, new \DateTimeImmutable());
        $this->criarPasta($tenant, 'TD-FORA-' . $s, responsavel: $ana, dataAbertura: new \DateTimeImmutable('2023-12-01'));
        $this->criarPasta($tenant, 'TD-OUTRO-' . $s, responsavel: $admin, dataAbertura: $dentro);
        $this->em()->flush();

        $filtros = ['data_de' => self::DATA_DE, 'data_ate' => self::DATA_ATE];
        $linha   = $this->linhaDoDashboard($tenant, $filtros, $ana);

        $this->logarComTenant($client, $admin, $tenant);
        $crawler = $client->xmlHttpRequest('GET', '/expediente/painel/acervo-geral?' . http_build_query(
            $filtros + ['responsavel' => $ana->getId()],
        ));

        self::assertResponseIsSuccessful();
        self::assertSame(3, $linha->totalDemandas, 'pré-condição: ativa + arquivada + lápide');
        self::assertSame($linha->totalDemandas, $this->contarLinhas($crawler));
    }

    #[TestDox('responsavel + status=ativo + período: a lista bate com Demandas ativas da linha')]
    public function testResponsavelAtivoBateComDemandasAtivas(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $admin  = $this->criarUsuario($tenant, 'Admin Ativas', admin: true);
        $ana    = $this->criarUsuario($tenant, 'Ana Ativas');

        $s      = strtoupper(uniqid());
        $dentro = new \DateTimeImmutable('2024-03-15 10:00:00');
        $ativa  = $this->criarPasta($tenant, 'DA-ATIVA-' . $s, responsavel: $ana, dataAbertura: $dentro);
        $arq    = $this->criarPasta($tenant, 'DA-ARQ-' . $s, responsavel: $ana, dataAbertura: $dentro);
        $arq->setSituacao(Pasta::SITUACAO_ARQUIVADA);
        $lap    = $this->criarPasta($tenant, 'DA-LAP-' . $s, responsavel: $ana, dataAbertura: $dentro);
        $lap->marcarExcluida($admin, new \DateTimeImmutable());
        $this->em()->flush();

        $filtros = ['data_de' => self::DATA_DE, 'data_ate' => self::DATA_ATE];
        $linha   = $this->linhaDoDashboard($tenant, $filtros, $ana);

        $this->logarComTenant($client, $admin, $tenant);
        $crawler = $client->xmlHttpRequest('GET', '/expediente/painel/acervo-geral?' . http_build_query(
            $filtros + ['responsavel' => $ana->getId(), 'status' => Pasta::SITUACAO_ATIVA],
        ));

        self::assertResponseIsSuccessful();
        self::assertSame(1, $linha->demandasAtivas, 'pré-condição: só a ativa (lápide é arquivada)');
        self::assertSame($linha->demandasAtivas, $this->contarLinhas($crawler));
        self::assertSame([$ativa->getNup()], $this->nupsListados($crawler));
    }

    #[TestDox('prioridade=urgente + período: a lista bate com o card Urgentes (o link precisa levar o período)')]
    public function testUrgentesComPeriodoBateComCard(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $admin  = $this->criarUsuario($tenant, 'Admin Urgentes', admin: true);

        $s      = strtoupper(uniqid());
        $dentro = new \DateTimeImmutable('2024-03-15 10:00:00');
        $this->criarPasta($tenant, 'URG-IN-' . $s, prioridade: PrioridadePasta::Urgente, dataAbertura: $dentro);
        $this->criarPasta($tenant, 'URG-IN2-' . $s, prioridade: PrioridadePasta::Urgente, responsavel: $admin, dataAbertura: $dentro);
        $this->criarPasta($tenant, 'URG-FORA-' . $s, prioridade: PrioridadePasta::Urgente, dataAbertura: new \DateTimeImmutable('2024-06-01'));
        $this->criarPasta($tenant, 'URG-NORMAL-' . $s, prioridade: PrioridadePasta::Normal, dataAbertura: $dentro);

        $filtros   = ['data_de' => self::DATA_DE, 'data_ate' => self::DATA_ATE];
        $dashboard = $this->dashboard($tenant, $filtros);

        $this->logarComTenant($client, $admin, $tenant);
        $comPeriodo = $client->xmlHttpRequest('GET', '/expediente/painel/acervo-geral?' . http_build_query(
            $filtros + ['prioridade' => PrioridadePasta::Urgente->value],
        ));
        self::assertResponseIsSuccessful();
        self::assertSame(2, $dashboard->demandasUrgentes, 'pré-condição: o card conta só as do período');
        self::assertSame($dashboard->demandasUrgentes, $this->contarLinhas($comPeriodo));

        // Sem o período a lista é MAIOR que o card: por isso o link de Urgentes leva data_de/data_ate.
        $semPeriodo = $client->xmlHttpRequest('GET', '/expediente/painel/acervo-geral?prioridade=urgente');
        self::assertSame(3, $this->contarLinhas($semPeriodo));
    }

    #[TestDox('cross-tenant: criado_por ou responsavel de outro escritório não lista nada')]
    public function testCriadoPorEResponsavelDeOutroEscritorioNaoListamNada(): void
    {
        $client  = static::createClient();
        $tenantA = $this->criarTenant();
        $tenantB = $this->criarTenant();
        $adminA  = $this->criarUsuario($tenantA, 'Admin A', admin: true);
        $intruso = $this->criarUsuario($tenantB, 'Colaborador de B');

        $s       = strtoupper(uniqid());
        $daB     = $this->criarPasta($tenantB, 'XT-B-' . $s, criadoPor: $intruso, responsavel: $intruso);
        $doA     = $this->criarPasta($tenantA, 'XT-A-' . $s, criadoPor: $adminA, responsavel: $adminA);

        $this->logarComTenant($client, $adminA, $tenantA);

        foreach (['criado_por', 'responsavel'] as $param) {
            $crawler = $client->xmlHttpRequest('GET', '/expediente/painel/acervo-geral?' . $param . '=' . $intruso->getId());
            self::assertResponseIsSuccessful();
            self::assertSame(0, $this->contarLinhas($crawler), $param . ' de outro escritório não pode listar pasta');
            $body = (string) $client->getResponse()->getContent();
            self::assertStringNotContainsString($daB->getNup(), $body);
            self::assertStringNotContainsString($doA->getNup(), $body, 'o filtro não pode ser ignorado e devolver o acervo inteiro');
        }
    }

    #[TestDox('deep-link ?painel=acervo-geral: a tela entrega ao JS a URL do painel com os filtros, sem a busca')]
    public function testDeepLinkAbreOPainelAcervoGeralComOsFiltros(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $admin  = $this->criarUsuario($tenant, 'Admin Deep', admin: true);

        $this->logarComTenant($client, $admin, $tenant);
        $crawler = $client->request('GET', '/expediente?' . http_build_query([
            'painel'     => 'acervo-geral',
            'criado_por' => '42',
            'status'     => 'ativo',
            'data_de'    => self::DATA_DE,
            'data_ate'   => self::DATA_ATE,
            'busca'      => 'NOME DE CLIENTE',
            'intruso'    => 'x',
        ]));

        self::assertResponseIsSuccessful();
        $painel = $crawler->filter('#expediente-painel');
        self::assertCount(1, $painel);

        $url = (string) $painel->attr('data-painel-inicial');
        self::assertStringStartsWith('/expediente/painel/acervo-geral?', $url);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        self::assertSame([
            'status'     => 'ativo',
            'data_de'    => self::DATA_DE,
            'data_ate'   => self::DATA_ATE,
            'criado_por' => '42',
        ], $query, 'repassa só a allowlist; busca (PII) e parâmetro desconhecido ficam de fora');
    }

    /**
     * O JS não roda aqui: o teste só prova que o bootstrap do deep-link CHAMA a limpeza da URL e
     * que ela usa replaceState sobre `painel` e os filtros da allowlist. O efeito no navegador
     * (voltar à página restaura o estado salvo) fica para o smoke.
     */
    #[TestDox('deep-link: depois de abrir o painel, o script tira painel e filtros da URL com history.replaceState')]
    public function testDeepLinkLimpaOsParametrosDaUrlDepoisDeConsumir(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $admin  = $this->criarUsuario($tenant, 'Admin Replace', admin: true);

        $this->logarComTenant($client, $admin, $tenant);
        $client->request('GET', '/expediente?painel=acervo-geral&criado_por=42');
        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();

        self::assertMatchesRegularExpression(
            '/function tentarAbrirPainelDoDeepLink\(\) \{[^}]*carregarPainel\(url, [^}]*consumirParametrosDoDeepLink\(\);\s*return true;/s',
            $html,
            'o deep-link consumido tem de limpar a URL',
        );
        self::assertSame(1, preg_match('/function consumirParametrosDoDeepLink\(\) \{(.*?)\n    \}/s', $html, $m));
        self::assertStringContainsString('window.history.replaceState(', $m[1]);
        foreach (['painel', 'status', 'responsavel', 'prioridade', 'data_de', 'data_ate', 'criado_por', 'ordenar', 'direcao', 'page'] as $chave) {
            self::assertStringContainsString("'" . $chave . "'", $m[1], $chave . ' precisa sair da URL');
        }
    }

    #[TestDox('sem ?painel=acervo-geral a tela não força painel (segue estado salvo / acervo limpo)')]
    public function testSemDeepLinkNaoMarcaPainelInicial(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $admin  = $this->criarUsuario($tenant, 'Admin Sem Deep', admin: true);

        $this->logarComTenant($client, $admin, $tenant);
        $crawler = $client->request('GET', '/expediente?criado_por=42');

        self::assertResponseIsSuccessful();
        self::assertNull($crawler->filter('#expediente-painel')->attr('data-painel-inicial'));
    }

    // ----------------------------------------------------------------- helpers

    /**
     * @param array<string, string> $filtros
     */
    private function dashboard(Tenant $tenant, array $filtros): DashboardOutput
    {
        /** @var ObterDadosDashboardUseCase $useCase */
        $useCase = static::getContainer()->get(ObterDadosDashboardUseCase::class);

        return $useCase->executar($tenant, new \DateTimeImmutable(), $filtros);
    }

    /**
     * @param array<string, string> $filtros
     */
    private function linhaDoDashboard(Tenant $tenant, array $filtros, User $user): LinhaAdvogadoDashboardOutput
    {
        foreach ($this->dashboard($tenant, $filtros)->porAdvogado as $linha) {
            if ($linha->userId === $user->getId()) {
                return $linha;
            }
        }

        self::fail('colaborador sem linha no Dashboard');
    }

    private function contarLinhas(Crawler $crawler): int
    {
        return $crawler->filter('#tabelaPastas tbody tr.pasta-row-link')->count();
    }

    /**
     * @return string[]
     */
    private function nupsListados(Crawler $crawler): array
    {
        return $crawler->filter('#tabelaPastas tbody tr.pasta-row-link td:first-child')
            ->each(static fn (Crawler $td): string => trim($td->text()));
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    private function criarTenant(): Tenant
    {
        $tenant = new Tenant();
        $tenant->setName('Tenant DEEPLINK ' . uniqid());
        $this->em()->persist($tenant);
        $this->em()->flush();

        return $tenant;
    }

    private function criarUsuario(Tenant $tenant, string $nome, bool $admin = false): User
    {
        $em     = $this->em();
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->setEmail('dl_' . uniqid() . '@test.com');
        $user->setFullName($nome);
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setPassword($hasher->hashPassword($user, 'senha123'));
        $em->persist($user);

        $userTenant = new UserTenant($user, $tenant);
        if ($admin) {
            $role = new TenantRole();
            $role->setTenant($tenant);
            $role->setName('Administrador ' . uniqid());
            $role->setIsSystem(true);
            $em->persist($role);
            $userTenant->setTenantRole($role);
        }
        $em->persist($userTenant);
        $em->flush();

        return $user;
    }

    private function criarPasta(
        Tenant $tenant,
        string $nup,
        ?User $criadoPor = null,
        ?User $responsavel = null,
        ?PrioridadePasta $prioridade = null,
        ?\DateTimeImmutable $dataAbertura = null,
    ): Pasta {
        $pasta = new Pasta();
        $pasta->setNup($nup);
        $pasta->setTenant($tenant);
        $pasta->setCriadoPor($criadoPor);
        if ($responsavel !== null) {
            $pasta->setResponsavel($responsavel);
        }
        if ($prioridade !== null) {
            $pasta->setPrioridade($prioridade);
        }
        if ($dataAbertura !== null) {
            $pasta->setDataAbertura($dataAbertura);
        }
        $this->em()->persist($pasta);
        $this->em()->flush();

        return $pasta;
    }
}
