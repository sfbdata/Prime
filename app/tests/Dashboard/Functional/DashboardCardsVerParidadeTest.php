<?php

declare(strict_types=1);

namespace App\Tests\Dashboard\Functional;

use App\Dashboard\Controller\DashboardController;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tarefa\Tarefa;
use App\Entity\Tenant\Cargo;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Pasta\Entity\Pasta;
use App\Pasta\Repository\PastaRepository;
use App\Tarefa\UseCase\ListarMetasDaEquipeUseCase;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;

/**
 * DASH-006: "ver pastas" (card Pastas criadas) e "ver metas" (card Metas ativas).
 *
 * O contrato é "número do card = total da lista que o link abre". O teste lê o número e o href
 * NA TELA do Dashboard, segue o href (o Acervo pelo XHR do painel; /tarefas/equipe direto) e
 * compara — com e sem período, com cargo e com responsável. A massa tem as pegadinhas que
 * separam os critérios: lápide, pasta sem criador, colaborador desligado, meta sem responsável,
 * meta concluída, fora do período e dado de OUTRO escritório (com o mesmo colaborador).
 */
#[CoversClass(DashboardController::class)]
#[CoversClass(PastaRepository::class)]
#[CoversClass(ListarMetasDaEquipeUseCase::class)]
#[Group('dashboard')]
final class DashboardCardsVerParidadeTest extends JusPrimeWebTestCase
{
    private const PERIODO = ['data_de' => '2024-03-01', 'data_ate' => '2024-03-31'];

    /** @var array<string, string> NUPs da massa, por apelido */
    private array $nups = [];

    #[TestDox('ver pastas: card Pastas criadas = lista do Acervo, sem período e com período; lápide, sem criador, desligado e outro escritório fora')]
    public function testVerPastasBateComOCard(): void
    {
        $client = static::createClient();
        [$admin, $tenant] = $this->semear();
        $this->logarComTenant($client, $admin, $tenant);

        foreach ([[], self::PERIODO] as $filtros) {
            [$card, $lista] = $this->cardEListaDePastas($client, $filtros);
            self::assertGreaterThan(0, $card, 'paridade entre zeros não prova nada');
            self::assertSame($card, $this->contarPastas($lista), 'número do card = tamanho da lista ' . json_encode($filtros));

            $nups = $this->nupsListados($lista);
            self::assertNotContains($this->nups['lapide'], $nups, 'lápide não conta no card');
            self::assertNotContains($this->nups['semCriador'], $nups, 'pasta sem criador não conta no card');
            self::assertNotContains($this->nups['desligada'], $nups, 'criador desligado não é linha da tabela');
            self::assertNotContains($this->nups['outroEscritorio'], $nups, 'pasta de outro escritório não pode aparecer');
        }

        // Com período: Ana 2 + Bruno 1 (a de fora do período só entra sem período).
        [$card] = $this->cardEListaDePastas($client, self::PERIODO);
        self::assertSame(3, $card, 'pré-condição da massa');
        [$cardSem] = $this->cardEListaDePastas($client, []);
        self::assertSame(4, $cardSem, 'sem período entra a de maio');
    }

    #[TestDox('ver pastas com cargo e com responsável: o link leva o recorte do card e a lista bate')]
    public function testVerPastasComCargoEResponsavel(): void
    {
        $client = static::createClient();
        [$admin, $tenant, $ana] = $this->semear();
        $this->logarComTenant($client, $admin, $tenant);

        $casos = [
            ['cargo' => 'Advogado'],
            ['cargo' => '__sem__'],
            ['responsavel' => (string) $ana->getId()],
        ];
        foreach ($casos as $faceta) {
            [$card, $lista, $query] = $this->cardEListaDePastas($client, self::PERIODO + $faceta);
            self::assertSame($card, $this->contarPastas($lista), 'card = lista com ' . json_encode($faceta));
            if (isset($faceta['cargo'])) {
                self::assertSame('equipe:' . $faceta['cargo'], $query['criado_por']);
            } else {
                self::assertSame((string) $ana->getId(), $query['criado_por']);
            }
        }

        [$cardAdv] = $this->cardEListaDePastas($client, self::PERIODO + ['cargo' => 'Advogado']);
        self::assertSame(2, $cardAdv, 'só as da Ana (Advogado)');
        [$cardSem] = $this->cardEListaDePastas($client, self::PERIODO + ['cargo' => '__sem__']);
        self::assertSame(1, $cardSem, 'só a do Bruno (sem cargo)');
    }

    #[TestDox('ver pastas some quando o responsável não vira linha (card 0 com cargo de outro): a lista não bateria')]
    public function testVerPastasSomeQuandoResponsavelForaDoCargo(): void
    {
        $client = static::createClient();
        [$admin, $tenant, , $bruno] = $this->semear();
        $this->logarComTenant($client, $admin, $tenant);

        $crawler = $client->request('GET', '/dashboard?' . http_build_query(self::PERIODO + ['responsavel' => $bruno->getId(), 'cargo' => 'Advogado']));
        self::assertResponseIsSuccessful();
        self::assertSame('0', trim($crawler->filter('.db-stat-card--pastas .db-stat-num')->text()));
        self::assertCount(0, $crawler->filter('.db-stat-card--pastas a.db-card-ver'));
    }

    #[TestDox('ver metas: card Metas ativas = lista /tarefas/equipe (escopo card), com e sem período e com responsável; outro escritório fora')]
    public function testVerMetasBateComOCard(): void
    {
        $client = static::createClient();
        [$admin, $tenant, $ana] = $this->semear();
        $this->logarComTenant($client, $admin, $tenant);

        foreach ([[], self::PERIODO, self::PERIODO + ['responsavel' => (string) $ana->getId()], self::PERIODO + ['cargo' => 'Advogado']] as $filtros) {
            [$card, $lista, $query] = $this->cardEListaDeMetas($client, $filtros);
            self::assertGreaterThan(0, $card, 'paridade entre zeros não prova nada');
            self::assertSame($card, $this->totalDeMetas($lista), 'número do card = total da lista ' . json_encode($filtros));
            self::assertSame('card', $query['escopo']);
            self::assertArrayNotHasKey('cargo', $query, 'o card não usa cargo: o link não pode levá-lo');
            // Setter de título pode gravar em maiúsculas: compara sem caixa.
            self::assertStringNotContainsStringIgnoringCase('Meta de outro escritório', $lista->text());
        }

        // Com período: sem responsável + Ana + desligada (+ em revisão da Ana) = 4; a concluída e a
        // de fevereiro ficam fora. O universo da TABELA (sem escopo) não conta a sem responsável
        // nem a da desligada — é por isso que o link precisa do escopo do card.
        [$card] = $this->cardEListaDeMetas($client, self::PERIODO);
        self::assertSame(4, $card, 'pré-condição da massa');
        $semEscopo = $client->request('GET', '/tarefas/equipe?' . http_build_query(['status' => 'ativas'] + self::PERIODO));
        self::assertSame(2, $this->totalDeMetas($semEscopo), 'a lista da tabela conta menos que o card');

        [$cardAna] = $this->cardEListaDeMetas($client, self::PERIODO + ['responsavel' => (string) $ana->getId()]);
        self::assertSame(2, $cardAna);
    }

    #[TestDox('ver metas: a paginação mantém o escopo do card')]
    public function testPaginacaoMantemEscopo(): void
    {
        $client = static::createClient();
        [$admin, $tenant] = $this->semear();
        $pasta = $this->criarPasta($tenant, 'PAG', null, '2024-03-05');
        for ($i = 0; $i < ListarMetasDaEquipeUseCase::POR_PAGINA; ++$i) {
            $this->criarMeta($tenant, $pasta, [], Tarefa::STATUS_PENDENTE, 'Meta extra ' . $i, '2024-03-06 10:00:00');
        }
        $this->logarComTenant($client, $admin, $tenant);

        $crawler = $client->request('GET', '/tarefas/equipe?' . http_build_query(['status' => 'ativas', 'escopo' => 'card'] + self::PERIODO));
        self::assertResponseIsSuccessful();
        $hrefs = $crawler->filter('[data-equipe-paginacao] a.page-link')->each(static fn (Crawler $a): string => (string) $a->attr('href'));
        self::assertNotSame([], $hrefs, 'há mais de uma página');
        foreach ($hrefs as $href) {
            self::assertStringContainsString('escopo=card', $href);
        }
    }

    // ------------------------------------------------------------------ leitura da tela

    /**
     * @param array<string, string> $filtros
     *
     * @return array{0: int, 1: Crawler, 2: array<string, string>} número do card, lista do acervo, query do link
     */
    private function cardEListaDePastas(KernelBrowser $client, array $filtros): array
    {
        $crawler = $client->request('GET', '/dashboard?' . http_build_query($filtros));
        self::assertResponseIsSuccessful();
        $card = (int) trim($crawler->filter('.db-cards-row > * > .db-stat-card--pastas .db-stat-num')->text());

        $link = $crawler->filter('.db-cards-row > * > .db-stat-card--pastas > .db-card-corpo > .db-card-valor-linha > a.db-card-ver');
        self::assertCount(1, $link, '"ver pastas" do card');
        self::assertSame('ver pastas', trim($link->text()));
        $partes = parse_url((string) $link->attr('href'));
        self::assertSame('/expediente', $partes['path'] ?? '');
        parse_str($partes['query'] ?? '', $query);
        self::assertSame('acervo-geral', $query['painel'] ?? null);

        // O Expediente repassa a query ao XHR do painel; o teste chama o XHR direto com ela.
        unset($query['painel']);
        $lista = $client->xmlHttpRequest('GET', '/expediente/painel/acervo-geral?' . http_build_query($query));
        self::assertResponseIsSuccessful();

        return [$card, $lista, $query];
    }

    /**
     * @param array<string, string> $filtros
     *
     * @return array{0: int, 1: Crawler, 2: array<string, string>}
     */
    private function cardEListaDeMetas(KernelBrowser $client, array $filtros): array
    {
        $crawler = $client->request('GET', '/dashboard?' . http_build_query($filtros));
        self::assertResponseIsSuccessful();
        $card = (int) trim($crawler->filter('.db-cards-row > * > .db-stat-card--metas .db-stat-num')->text());

        $link = $crawler->filter('.db-cards-row > * > .db-stat-card--metas > .db-card-corpo > .db-card-valor-linha > a.db-card-ver');
        self::assertCount(1, $link, '"ver metas" do card');
        self::assertSame('ver metas', trim($link->text()));
        $partes = parse_url((string) $link->attr('href'));
        self::assertSame('/tarefas/equipe', $partes['path'] ?? '');
        parse_str($partes['query'] ?? '', $query);
        self::assertSame('ativas', $query['status'] ?? null);

        $lista = $client->request('GET', (string) $link->attr('href'));
        self::assertResponseIsSuccessful();

        return [$card, $lista, $query];
    }

    private function contarPastas(Crawler $crawler): int
    {
        return $crawler->filter('#tabelaPastas tbody tr.pasta-row-link')->count();
    }

    /** @return string[] */
    private function nupsListados(Crawler $crawler): array
    {
        return $crawler->filter('#tabelaPastas tbody tr.pasta-row-link td:first-child')
            ->each(static fn (Crawler $td): string => trim($td->text()));
    }

    private function totalDeMetas(Crawler $crawler): int
    {
        $total = $crawler->filter('[data-equipe-total]');

        return $total->count() === 0 ? 0 : (int) $total->attr('data-equipe-total');
    }

    // ------------------------------------------------------------------ massa

    /**
     * Escritório A: admin (papel de sistema: abre Dashboard, Acervo e Metas da equipe), Ana
     * (Advogado), Bruno (sem cargo), Carla (vínculo DESLIGADO). Escritório B: a Ana também é de
     * lá, com pasta e meta no período — nada disso pode entrar em A.
     *
     * Pastas de A (criador → data): Ana 10/03, Ana 20/03, Ana lápide 12/03, Ana 10/05/2024,
     * Bruno 15/03, Carla 15/03, sem criador 15/03.
     * Metas de A (status, responsável, criação): pendente sem responsável 05/03; pendente Ana
     * 06/03; em revisão Ana 07/03; pendente Carla 08/03; concluída Ana 09/03; pendente Ana 10/02.
     *
     * @return array{0: User, 1: Tenant, 2: User, 3: User} admin, escritório A, Ana, Bruno
     */
    private function semear(): array
    {
        $tenant = $this->criarTenant();
        $outro  = $this->criarTenant();
        $admin  = $this->criarUsuario($tenant, 'Admin Paridade Card', admin: true);
        $ana    = $this->criarUsuario($tenant, 'Ana Paridade Card', cargo: 'Advogado');
        $bruno  = $this->criarUsuario($tenant, 'Bruno Paridade Card');
        $carla  = $this->criarUsuario($tenant, 'Carla Paridade Card', cargo: 'Advogado');
        $this->desligar($carla, $tenant);
        $this->em()->persist(new UserTenant($ana, $outro));
        $this->em()->flush();

        $this->criarPasta($tenant, 'ana1', $ana, '2024-03-10');
        $this->criarPasta($tenant, 'ana2', $ana, '2024-03-20');
        $lapide = $this->criarPasta($tenant, 'lapide', $ana, '2024-03-12');
        $lapide->marcarExcluida($admin, new \DateTimeImmutable());
        $this->criarPasta($tenant, 'maio', $ana, '2024-05-10');
        $this->criarPasta($tenant, 'bruno', $bruno, '2024-03-15');
        $this->criarPasta($tenant, 'desligada', $carla, '2024-03-15');
        $hospedeira = $this->criarPasta($tenant, 'semCriador', null, '2024-03-15');
        $this->criarPasta($outro, 'outroEscritorio', $ana, '2024-03-15');
        $this->em()->flush();

        $this->criarMeta($tenant, $hospedeira, [], Tarefa::STATUS_PENDENTE, 'Meta sem responsável', '2024-03-05 10:00:00');
        $this->criarMeta($tenant, $hospedeira, [$ana], Tarefa::STATUS_PENDENTE, 'Meta da Ana', '2024-03-06 10:00:00');
        $this->criarMeta($tenant, $hospedeira, [$ana], Tarefa::STATUS_EM_REVISAO, 'Meta da Ana em revisão', '2024-03-07 10:00:00');
        $this->criarMeta($tenant, $hospedeira, [$carla], Tarefa::STATUS_PENDENTE, 'Meta da desligada', '2024-03-08 10:00:00');
        $this->criarMeta($tenant, $hospedeira, [$ana], Tarefa::STATUS_CONCLUIDA, 'Meta concluída', '2024-03-09 10:00:00');
        $this->criarMeta($tenant, $hospedeira, [$ana], Tarefa::STATUS_PENDENTE, 'Meta de fevereiro', '2024-02-10 10:00:00');
        $pastaB = $this->criarPasta($outro, 'hospedeiraB', null, '2024-03-15');
        $this->criarMeta($outro, $pastaB, [$ana], Tarefa::STATUS_PENDENTE, 'Meta de outro escritório', '2024-03-06 10:00:00');

        return [$admin, $tenant, $ana, $bruno];
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    private function criarTenant(): Tenant
    {
        $tenant = new Tenant();
        $tenant->setName('Tenant Ver Card ' . uniqid());
        $this->em()->persist($tenant);
        $this->em()->flush();

        return $tenant;
    }

    private function criarUsuario(Tenant $tenant, string $nome, bool $admin = false, ?string $cargo = null): User
    {
        $em   = $this->em();
        $user = new User();
        $user->setEmail('vercard_' . uniqid() . '@test.com');
        $user->setFullName($nome);
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $em->persist($user);

        $ut = new UserTenant($user, $tenant);
        if ($admin) {
            $role = new TenantRole();
            $role->setTenant($tenant);
            $role->setName('Administrador ' . uniqid());
            $role->setIsSystem(true);
            $em->persist($role);
            $ut->setTenantRole($role);
        }
        if ($cargo !== null) {
            $c = new Cargo();
            $c->setNome($cargo);
            $c->setTenant($tenant);
            $em->persist($c);
            $ut->setCargo($c);
        }
        $em->persist($ut);
        $em->flush();

        return $user;
    }

    /** Desliga o vínculo (a entidade só tem `reativar()`; o desligamento real é do UseCase de remoção). */
    private function desligar(User $user, Tenant $tenant): void
    {
        $ut = $this->em()->getRepository(UserTenant::class)->findOneBy(['user' => $user, 'tenant' => $tenant]);
        self::assertInstanceOf(UserTenant::class, $ut);
        (new \ReflectionProperty(UserTenant::class, 'isActive'))->setValue($ut, false);
        $this->em()->flush();
    }

    private function criarPasta(Tenant $tenant, string $apelido, ?User $criadoPor, string $dataAbertura): Pasta
    {
        $nup = 'VC-' . strtoupper($apelido) . '-' . strtoupper(uniqid());
        $this->nups[$apelido] = $nup;

        $pasta = new Pasta();
        $pasta->setNup($nup);
        $pasta->setTenant($tenant);
        $pasta->setCriadoPor($criadoPor);
        $pasta->setDataAbertura(new \DateTimeImmutable($dataAbertura . ' 10:00:00'));
        $this->em()->persist($pasta);
        $this->em()->flush();

        return $pasta;
    }

    /** @param list<User> $responsaveis */
    private function criarMeta(Tenant $tenant, Pasta $pasta, array $responsaveis, string $status, string $titulo, string $dataCriacao): void
    {
        $tarefa = new Tarefa();
        $tarefa->setTitulo($titulo);
        $tarefa->setDescricao('—');
        $tarefa->setPasta($pasta);
        $tarefa->setTenant($tenant);
        $tarefa->setStatus($status);
        foreach ($responsaveis as $r) {
            $tarefa->addResponsavel($r);
        }
        $this->em()->persist($tarefa);
        $this->em()->flush();

        $this->em()->getConnection()->executeStatement(
            'UPDATE tarefa SET data_criacao = :d WHERE id = :id',
            ['d' => $dataCriacao, 'id' => $tarefa->getId()],
        );
        $this->em()->refresh($tarefa);
    }
}
