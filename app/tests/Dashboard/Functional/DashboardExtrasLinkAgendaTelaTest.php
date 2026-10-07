<?php

declare(strict_types=1);

namespace App\Tests\Dashboard\Functional;

use App\Controller\AgendaController;
use App\Dashboard\Controller\DashboardController;
use App\Dashboard\Preferencia\CatalogoDePreferenciasDoDashboard;
use App\Dashboard\Repository\PreferenciaDoUsuarioRepository;
use App\Entity\Agenda\Evento;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Permission\Permission;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRolePermission;
use App\Repository\EventoRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;

/**
 * DASH-009 — o número da extra "Eventos na agenda" vira link para /agenda/lista com a pessoa
 * (`responsavel`) e o período do painel.
 *
 * O contrato é "número da célula = total da lista de destino". O teste lê o número e o href NA
 * TELA do Dashboard, segue o href e conta as linhas da lista — com e sem período, para cada linha.
 * A massa tem as pegadinhas da régua do contador: cancelado, "Somente para mim", recorrente (conta
 * uma vez), criador que também é participante (conta uma vez), evento entre DOIS colegas sem a
 * gestora (é "Para Todos": entra para eles), fora do período e evento de OUTRO escritório com a
 * própria gestora — que não entra de nenhum lado.
 */
#[CoversClass(DashboardController::class)]
#[CoversClass(AgendaController::class)]
#[CoversClass(EventoRepository::class)]
#[Group('dashboard')]
final class DashboardExtrasLinkAgendaTelaTest extends DashboardWebTestCase
{
    private const PERIODO = ['data_de' => '2024-02-01', 'data_ate' => '2024-02-29'];

    /** O número da célula: direto no td, ou dentro de .db-cel quando a coluna tem seta. */
    private const NUMERO = 'td > .db-num, td > .db-cel > .db-num';
    private const LINK   = 'td > a.db-num-link, td > .db-cel > a.db-num-link';

    private const LISTA = 'table.table > tbody > tr[data-agenda-evento]';

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    /** Dá ao papel do usuário, no escritório, o módulo `agenda` (o gestor da base só tem `bi`). */
    private function darModuloAgenda(User $user, Tenant $tenant): void
    {
        $em   = $this->em();
        $perm = $em->getRepository(Permission::class)->findOneBy(['code' => 'modules.agenda.view']);
        if ($perm === null) {
            $perm = new Permission();
            $perm->setCode('modules.agenda.view');
            $perm->setDescription('Acesso ao módulo Agenda');
            $perm->setGroup('modules');
            $em->persist($perm);
        }

        $role = $em->getRepository(UserTenant::class)->findOneBy(['user' => $user, 'tenant' => $tenant])?->getTenantRole();
        self::assertNotNull($role);
        $trp = new TenantRolePermission();
        $trp->setTenantRole($role);
        $trp->setPermission($perm);
        $em->persist($trp);
        $role->getTenantRolePermissions()->add($trp);
        $em->flush();
    }

    /** @param list<User> $participantes */
    private function evento(
        Tenant $tenant,
        User $criador,
        array $participantes,
        string $inicio,
        string $titulo,
        string $status = Evento::STATUS_AGENDADO,
        string $visibilidade = Evento::VISIBILIDADE_TODOS,
        bool $recorrente = false,
    ): Evento {
        $evento = new Evento();
        $evento->setTitulo($titulo);
        $evento->setDataInicio(new \DateTimeImmutable($inicio));
        $evento->setDataFim((new \DateTimeImmutable($inicio))->modify('+1 hour'));
        $evento->setCriador($criador);
        $evento->setTenant($tenant);
        $evento->setStatus($status);
        $evento->setVisibilidade($visibilidade);
        if ($recorrente) {
            $evento->setRecorrente(true);
            $evento->setTipoRecorrencia('semanal');
        }
        foreach ($participantes as $p) {
            $evento->addParticipante($p);
        }
        $this->em()->persist($evento);
        $this->em()->flush();

        return $evento;
    }

    /**
     * Sem período: Gestora 4 (E1, E2, E5, E6) · Bruno 4 (E1, E2, E5, E7) · Carla 1 (E7).
     * Fevereiro: Gestora 3 · Bruno 3 · Carla 1 (E5 é de janeiro).
     *
     * @return array{0: User, 1: Tenant, 2: User, 3: User}
     */
    private function semear(KernelBrowser $client, bool $comAgenda = true): array
    {
        [$gestora, $tenant] = $this->criarGestorLogado($client);
        if ($comAgenda) {
            $this->darModuloAgenda($gestora, $tenant);
        }
        $bruno = $this->criarColaborador($tenant, 'Bruno Agenda');
        $carla = $this->criarColaborador($tenant, 'Carla Agenda');

        $this->evento($tenant, $gestora, [$bruno], '2024-02-05 09:00', 'E1 reunião');
        $this->evento($tenant, $bruno, [$bruno, $gestora], '2024-02-10 09:00', 'E2 audiência', Evento::STATUS_CONCLUIDO);
        $this->evento($tenant, $gestora, [$bruno], '2024-02-12 09:00', 'E3 cancelado', Evento::STATUS_CANCELADO);
        $this->evento($tenant, $gestora, [], '2024-02-15 09:00', 'E4 privado', Evento::STATUS_AGENDADO, Evento::VISIBILIDADE_SOMENTE_EU);
        $this->evento($tenant, $bruno, [$gestora], '2024-01-10 09:00', 'E5 janeiro');
        $this->evento($tenant, $gestora, [], '2024-02-01 08:00', 'E6 semanal', Evento::STATUS_AGENDADO, Evento::VISIBILIDADE_TODOS, true);
        $this->evento($tenant, $carla, [$bruno], '2024-02-20 14:00', 'E7 entre colegas');

        $outro = $this->criarTenant();
        $this->em()->persist(new UserTenant($gestora, $outro));
        $this->em()->flush();
        $this->evento($outro, $gestora, [], '2024-02-06 09:00', 'Evento do outro escritório');
        $this->evento($outro, $gestora, [], '2023-12-06 09:00', 'Evento do outro escritório');

        static::getContainer()->get(PreferenciaDoUsuarioRepository::class)->gravar(
            $tenant,
            $gestora,
            CatalogoDePreferenciasDoDashboard::COLUNAS_EXTRAS,
            ['eventos_agenda'],
        );

        return [$gestora, $tenant, $bruno, $carla];
    }

    private function linhaDe(Crawler $crawler, string $nome): Crawler
    {
        $linha = $crawler->filter('.db-table-card table.db-table > tbody > tr')->reduce(
            static fn (Crawler $tr): bool => str_contains($tr->filter('.db-colab-nome')->attr('title') ?? '', $nome),
        );
        self::assertCount(1, $linha, 'Linha de ' . $nome);

        return $linha;
    }

    /**
     * Para cada linha: o número na célula é o total da lista que o link abre.
     *
     * @param array<string, string> $filtros
     * @param list<string>          $nomes
     *
     * @return array<string, int> nome => número
     */
    private function assertParidade(KernelBrowser $client, array $filtros, array $nomes): array
    {
        $dashboard = $client->request('GET', '/dashboard' . ($filtros !== [] ? '?' . http_build_query($filtros) : ''));
        self::assertResponseIsSuccessful();

        $vistos = [];
        foreach ($nomes as $nome) {
            $celula = $this->linhaDe($dashboard, $nome)->filter('td[data-coluna="eventos_agenda"]');
            $num    = $celula->filter(self::NUMERO);
            self::assertCount(1, $num, 'um só número para ' . $nome);
            $numero         = (int) trim($num->text());
            $vistos[$nome]  = $numero;
            self::assertGreaterThan(0, $numero, 'paridade entre zeros não prova nada');

            $link = $celula->filter(self::LINK);
            self::assertCount(1, $link, sprintf('Eventos de %s (%d) é link', $nome, $numero));
            self::assertSame('Abrir: Eventos na agenda de ' . $nome, $link->attr('aria-label'));
            $href = (string) $link->attr('href');
            self::assertSame('/agenda/lista', parse_url($href, PHP_URL_PATH));
            parse_str((string) parse_url($href, PHP_URL_QUERY), $q);
            self::assertArrayHasKey('responsavel', $q);
            foreach (['data_de', 'data_ate'] as $chave) {
                self::assertSame($filtros[$chave] ?? null, $q[$chave] ?? null, 'o link leva o período: ' . $chave);
            }

            $lista = $client->request('GET', $href);
            self::assertResponseIsSuccessful();
            self::assertSame($numero, $lista->filter(self::LISTA)->count(), sprintf('%s: célula %d × lista', $nome, $numero));
            self::assertSame((string) $numero, $lista->filter('[data-agenda-total]')->attr('data-agenda-total'));
            self::assertStringContainsString('na agenda de ' . $nome, $lista->filter('[data-agenda-filtro-pessoa]')->text());
            $corpo = (string) $client->getResponse()->getContent();
            self::assertStringNotContainsStringIgnoringCase('do outro escritório', $corpo);
            self::assertStringNotContainsStringIgnoringCase('E3 cancelado', $corpo);
            self::assertStringNotContainsStringIgnoringCase('E4 privado', $corpo);
        }

        return $vistos;
    }

    #[TestDox('Sem período: o número de Eventos na agenda de cada linha = total da lista que o link abre')]
    public function testParidadeSemPeriodo(): void
    {
        $client = static::createClient();
        $this->semear($client);

        $vistos = $this->assertParidade($client, [], ['Gestora da Tela', 'Bruno Agenda', 'Carla Agenda']);

        // cancelado, privado e o outro escritório fora; E2 (criador e participante) e o semanal uma vez
        self::assertSame(['Gestora da Tela' => 4, 'Bruno Agenda' => 4, 'Carla Agenda' => 1], $vistos);
    }

    #[TestDox('Com período: o link leva data_de/data_ate e a lista recorta pela data de início, como a célula')]
    public function testParidadeComPeriodo(): void
    {
        $client = static::createClient();
        $this->semear($client);

        $vistos = $this->assertParidade($client, self::PERIODO, ['Gestora da Tela', 'Bruno Agenda', 'Carla Agenda']);

        self::assertSame(['Gestora da Tela' => 3, 'Bruno Agenda' => 3, 'Carla Agenda' => 1], $vistos);
    }

    #[TestDox('O filtro de status da lista mantém a pessoa e o período (campos ocultos) e só estreita a régua')]
    public function testStatusMantemPessoaEPeriodo(): void
    {
        $client = static::createClient();
        [, , $bruno] = $this->semear($client);

        $base  = ['responsavel' => (string) $bruno->getId()] + self::PERIODO;
        $lista = $client->request('GET', '/agenda/lista?' . http_build_query($base));
        self::assertResponseIsSuccessful();
        $form = $lista->filter('form[action="/agenda/lista"]');
        self::assertSame((string) $bruno->getId(), $form->filter('input[type="hidden"][name="responsavel"]')->attr('value'));
        self::assertSame('2024-02-01', $form->filter('input[type="hidden"][name="data_de"]')->attr('value'));
        self::assertSame('2024-02-29', $form->filter('input[type="hidden"][name="data_ate"]')->attr('value'));

        $concluidos = $client->request('GET', '/agenda/lista?' . http_build_query($base + ['status' => 'concluido']));
        self::assertSame(1, $concluidos->filter(self::LISTA)->count(), 'só a E2');
        $cancelados = $client->request('GET', '/agenda/lista?' . http_build_query($base + ['status' => 'cancelado']));
        self::assertSame(0, $cancelados->filter(self::LISTA)->count(), 'cancelado não é da régua');
    }

    #[TestDox('responsavel de OUTRO escritório, inexistente ou não numérico: lista VAZIA, nunca a lista sem filtro')]
    public function testResponsavelInvalidoEsvazia(): void
    {
        $client = static::createClient();
        [$gestora, $tenant] = $this->semear($client);
        // Evento "Para Todos" sem participantes: a lista SEM filtro o mostra (prova que o vazio
        // abaixo é o filtro, e não a lista que já viria vazia).
        $this->evento($tenant, $gestora, [], '2024-02-07 09:00', 'Evento aberto sem participantes');
        $semFiltro = $client->request('GET', '/agenda/lista');
        self::assertGreaterThan(0, $semFiltro->filter(self::LISTA)->count(), 'pré-condição: a lista sem filtro tem linhas');

        $outro    = $this->criarTenant();
        $estranho = $this->criarColaborador($outro, 'Pessoa De Fora');
        $this->evento($outro, $estranho, [], '2024-02-08 09:00', 'Evento do outro escritório');

        foreach ([(string) $estranho->getId(), '999999999', 'abc', '1 OR 1=1'] as $valor) {
            $crawler = $client->request('GET', '/agenda/lista?' . http_build_query(['responsavel' => $valor]));
            self::assertResponseIsSuccessful();
            self::assertSame(0, $crawler->filter(self::LISTA)->count(), 'responsavel=' . $valor);
            $corpo = (string) $client->getResponse()->getContent();
            self::assertStringNotContainsStringIgnoringCase('Pessoa De Fora', $corpo);
            self::assertStringNotContainsStringIgnoringCase('do outro escritório', $corpo);
        }
    }

    #[TestDox('Guarda da Agenda: sem o módulo agenda, o número fica simples (sem link) e a lista redireciona')]
    public function testSemModuloAgendaNaoHaLink(): void
    {
        $client = static::createClient();
        [, , $bruno] = $this->semear($client, false);

        $dash   = $client->request('GET', '/dashboard');
        $celula = $this->linhaDe($dash, 'Bruno Agenda')->filter('td[data-coluna="eventos_agenda"]');
        self::assertSame('4', trim($celula->filter(self::NUMERO)->text()), 'o número continua lá');
        self::assertCount(0, $celula->filter('a'), 'sem Agenda, não há lista que bata');

        $client->request('GET', '/agenda/lista?' . http_build_query(['responsavel' => $bruno->getId()]));
        self::assertResponseRedirects('/');
    }
}
