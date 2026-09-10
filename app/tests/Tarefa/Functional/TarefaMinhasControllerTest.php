<?php

declare(strict_types=1);

namespace App\Tests\Tarefa\Functional;

use App\Controller\TarefaController;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tarefa\Tarefa;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Pasta\Entity\Pasta;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[CoversClass(TarefaController::class)]
final class TarefaMinhasControllerTest extends JusPrimeWebTestCase
{
    private function autenticar(KernelBrowser $client): array
    {
        $container = static::getContainer();
        $em        = $container->get(EntityManagerInterface::class);
        $hasher    = $container->get(UserPasswordHasherInterface::class);

        $tenant = new Tenant();
        $tenant->setName('Tenant Metas ' . uniqid());
        $em->persist($tenant);

        $user = new User();
        $user->setEmail('metas_' . uniqid() . '@test.com');
        $user->setFullName('Usuário Metas');
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setPassword($hasher->hashPassword($user, 'senha123'));
        $em->persist($user);

        // Papel isSystem → bypassa o PermissionChecker (módulo 'tarefas').
        $role = new TenantRole();
        $role->setTenant($tenant);
        $role->setName('Admin ' . uniqid());
        $role->setIsSystem(true);
        $em->persist($role);

        $userTenant = new UserTenant($user, $tenant);
        $userTenant->setTenantRole($role);
        $em->persist($userTenant);
        $em->flush();

        $this->logarComTenant($client, $user, $tenant);

        return [$user, $tenant];
    }

    /**
     * Meta que ALGUÉM atribuiu ao usuário — sem criador definido de propósito: uma meta que
     * ele mesmo tivesse criado apareceria (com razão) também na aba "Criei", e não serviria
     * para provar que os papéis não se misturam.
     */
    private function criarMeta(Tenant $tenant, User $responsavel, string $titulo, string $status = Tarefa::STATUS_PENDENTE): Tarefa
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $pasta = new Pasta();
        $pasta->setNup('META-' . uniqid());
        $pasta->setNomeCliente('Cliente Meta');
        $pasta->setResponsavel($responsavel);
        $pasta->setTenant($tenant);
        $em->persist($pasta);

        $tarefa = new Tarefa();
        $tarefa->setTitulo($titulo);
        $tarefa->setDescricao('Descrição da meta');
        $tarefa->setPasta($pasta);
        $tarefa->setTenant($tenant);
        $tarefa->setStatus($status);
        $tarefa->addResponsavel($responsavel);
        $em->persist($tarefa);
        $em->flush();

        return $tarefa;
    }

    #[TestDox('GET /tarefas/minhas autenticado retorna 200 e renderiza a barra de filtro')]
    public function testExibeBarraDeFiltro(): void
    {
        $client = static::createClient();
        $this->autenticar($client);

        $client->request('GET', '/tarefas/minhas');

        self::assertResponseIsSuccessful();
        $body = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('data-filtro-root', $body);
        self::assertStringContainsString('js-filtro-busca', $body);
    }

    #[TestDox('GET /tarefas/minhas exibe a meta do usuário')]
    public function testExibeMinhaMeta(): void
    {
        $client            = static::createClient();
        [$usuario, $tenant] = $this->autenticar($client);
        $this->criarMeta($tenant, $usuario, 'Protocolar contestação');

        $client->request('GET', '/tarefas/minhas');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Protocolar contestação', (string) $client->getResponse()->getContent());
    }

    #[TestDox('XHR em /tarefas/minhas devolve só o fragmento, sem o layout nem a barra')]
    public function testXhrRetornaFragmentoSemLayout(): void
    {
        $client            = static::createClient();
        [$usuario, $tenant] = $this->autenticar($client);
        $this->criarMeta($tenant, $usuario, 'Elaborar parecer');

        $client->xmlHttpRequest('GET', '/tarefas/minhas');

        self::assertResponseIsSuccessful();
        $body = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Elaborar parecer', $body);
        self::assertStringNotContainsString('<!DOCTYPE', $body);
        self::assertStringNotContainsString('data-filtro-root', $body);
    }

    #[TestDox('XHR com busca filtra as metas por título')]
    public function testXhrFiltraPorBusca(): void
    {
        $client            = static::createClient();
        [$usuario, $tenant] = $this->autenticar($client);
        $this->criarMeta($tenant, $usuario, 'Protocolar recurso');
        $this->criarMeta($tenant, $usuario, 'Agendar audiência');

        $client->xmlHttpRequest('GET', '/tarefas/minhas', ['busca' => 'recurso']);

        self::assertResponseIsSuccessful();
        $body = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Protocolar recurso', $body);
        self::assertStringNotContainsString('Agendar audiência', $body);
    }

    #[TestDox('XHR com busca sem correspondência mostra o estado vazio filtrado')]
    public function testXhrBuscaSemResultado(): void
    {
        $client            = static::createClient();
        [$usuario, $tenant] = $this->autenticar($client);
        $this->criarMeta($tenant, $usuario, 'Protocolar recurso');

        $client->xmlHttpRequest('GET', '/tarefas/minhas', ['busca' => 'zzz-inexistente-zzz']);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Nenhuma meta encontrada', (string) $client->getResponse()->getContent());
    }

    #[TestDox('A aba padrão é "Sou responsável" e não traz o que o usuário delegou')]
    public function testAbaPadraoNaoMisturaOsPapeis(): void
    {
        $client             = static::createClient();
        [$usuario, $tenant] = $this->autenticar($client);
        $colega             = $this->criarColega($tenant);

        $this->criarMeta($tenant, $usuario, 'Está comigo');
        $this->criarMetaDelegada($tenant, $usuario, $colega, 'Deleguei ao colega');

        $client->request('GET', '/tarefas/minhas');

        self::assertResponseIsSuccessful();
        $body = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Está comigo', $body);
        self::assertStringNotContainsString('Deleguei ao colega', $body, 'A aba padrão não pode trazer o que foi delegado.');
    }

    #[TestDox('A aba "Criei" mostra o que o usuário delegou')]
    public function testAbaCriei(): void
    {
        $client             = static::createClient();
        [$usuario, $tenant] = $this->autenticar($client);
        $colega             = $this->criarColega($tenant);

        $this->criarMeta($tenant, $usuario, 'Está comigo');
        $this->criarMetaDelegada($tenant, $usuario, $colega, 'Deleguei ao colega');

        $client->request('GET', '/tarefas/minhas?aba=criei');

        self::assertResponseIsSuccessful();
        $body = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Deleguei ao colega', $body);
        self::assertStringNotContainsString('Está comigo', $body);
    }

    #[TestDox('Aba desconhecida cai no padrão, sem erro e sem virar "Todas"')]
    public function testAbaInvalidaCaiNoPadrao(): void
    {
        $client             = static::createClient();
        [$usuario, $tenant] = $this->autenticar($client);
        $colega             = $this->criarColega($tenant);

        $this->criarMeta($tenant, $usuario, 'Está comigo');
        $this->criarMetaDelegada($tenant, $usuario, $colega, 'Deleguei ao colega');

        $client->request('GET', '/tarefas/minhas?aba=inventada');

        self::assertResponseIsSuccessful();
        $body = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Está comigo', $body);
        self::assertStringNotContainsString(
            'Deleguei ao colega',
            $body,
            'Aba inválida não pode degradar para a lista mais ampla — seria voltar ao defeito.',
        );
    }

    #[TestDox('As quatro abas respondem 200 e marcam a aba pedida como ativa')]
    public function testTodasAsAbasRespondem(): void
    {
        $client             = static::createClient();
        [$usuario, $tenant] = $this->autenticar($client);
        $this->criarMeta($tenant, $usuario, 'Uma meta qualquer');

        foreach (['responsavel', 'criei', 'acompanhando', 'todas'] as $aba) {
            $client->request('GET', '/tarefas/minhas?aba=' . $aba);

            self::assertResponseIsSuccessful("A aba '{$aba}' não respondeu 200.");
            $ativa = $client->getCrawler()->filter('.mm-aba.is-ativa');
            self::assertCount(1, $ativa, "A aba '{$aba}' deveria marcar exatamente uma aba ativa.");
            self::assertSame($aba, $ativa->attr('data-aba'));
        }
    }

    #[TestDox('A lista fica ao LADO do trilho, não abaixo dele (combinador de filho direto)')]
    public function testArranjoDaListaComTrilho(): void
    {
        $client             = static::createClient();
        [$usuario, $tenant] = $this->autenticar($client);
        $this->criarMeta($tenant, $usuario, 'Meta com trilho');

        $client->request('GET', '/tarefas/minhas');

        self::assertResponseIsSuccessful();
        $crawler = $client->getCrawler();
        self::assertCount(1, $crawler->filter('.mm-arranjo > .mm-trilho'), 'O trilho tem de ser filho direto do arranjo de duas colunas.');
        self::assertCount(1, $crawler->filter('.mm-arranjo > .mm-coluna-lista > .mm-cartao'), 'A lista tem de ser a outra coluna do mesmo arranjo.');
    }

    #[TestDox('O modo lista troca os cartões pela tabela e dispensa o trilho')]
    public function testModoLista(): void
    {
        $client             = static::createClient();
        [$usuario, $tenant] = $this->autenticar($client);
        $this->criarMeta($tenant, $usuario, 'Meta em lista');

        $client->request('GET', '/tarefas/minhas?modo=lista');

        self::assertResponseIsSuccessful();
        $crawler = $client->getCrawler();
        self::assertGreaterThan(0, $crawler->filter('.mm-tabela')->count());
        self::assertCount(0, $crawler->filter('.mm-trilho'), 'O modo lista não desenha o trilho.');
        self::assertStringContainsString('Meta em lista', (string) $client->getResponse()->getContent());
    }

    #[TestDox('Os quatro KPIs aparecem no topo com as contagens do usuário')]
    public function testKpisNoTopo(): void
    {
        $client             = static::createClient();
        [$usuario, $tenant] = $this->autenticar($client);

        $atrasada = $this->criarMeta($tenant, $usuario, 'Atrasada');
        $atrasada->setPrazo(new \DateTimeImmutable('-2 days'));
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        $client->request('GET', '/tarefas/minhas');

        self::assertResponseIsSuccessful();
        $kpis = $client->getCrawler()->filter('.mm-kpi');
        self::assertCount(4, $kpis, 'A faixa tem exatamente quatro atalhos.');
        self::assertSame('1', trim($kpis->eq(0)->filter('.mm-kpi-valor')->text()), 'O primeiro KPI conta as atrasadas.');
    }

    #[TestDox('Filtrar dentro de uma aba preserva a aba na recarga por XHR')]
    public function testFiltroPreservaAAba(): void
    {
        $client             = static::createClient();
        [$usuario, $tenant] = $this->autenticar($client);
        $colega             = $this->criarColega($tenant);

        $this->criarMeta($tenant, $usuario, 'Protocolar comigo');
        $this->criarMetaDelegada($tenant, $usuario, $colega, 'Protocolar delegada');

        $client->xmlHttpRequest('GET', '/tarefas/minhas', ['aba' => 'criei', 'busca' => 'protocolar']);

        self::assertResponseIsSuccessful();
        $body = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Protocolar delegada', $body);
        self::assertStringNotContainsString('Protocolar comigo', $body, 'O filtro não pode furar o escopo da aba.');
    }

    #[TestDox('Marcar acompanhamento coloca a meta na aba "Em acompanhamento"')]
    public function testMarcarAcompanhamento(): void
    {
        $client             = static::createClient();
        [$usuario, $tenant] = $this->autenticar($client);
        $colega             = $this->criarColega($tenant);

        $meta = $this->criarMetaDelegada($tenant, $usuario, $colega, 'Vou acompanhar esta');
        $id   = (int) $meta->getId();

        $client->request('GET', '/tarefas/minhas?aba=acompanhando');
        self::assertStringNotContainsString('Vou acompanhar esta', (string) $client->getResponse()->getContent());

        $client->request('GET', '/tarefas/minhas?aba=criei');
        $botao = $client->getCrawler()->filter('[data-acompanhar]')->first();
        self::assertGreaterThan(0, $botao->count(), 'O cartão precisa oferecer o marcador.');

        $client->request('POST', "/tarefas/{$id}/acompanhar", ['_token' => $botao->attr('data-token')]);
        self::assertResponseIsSuccessful();
        self::assertTrue(json_decode((string) $client->getResponse()->getContent(), true)['acompanhando']);

        $client->request('GET', '/tarefas/minhas?aba=acompanhando');
        self::assertStringContainsString('Vou acompanhar esta', (string) $client->getResponse()->getContent());
    }

    #[TestDox('Clicar duas vezes no marcador desmarca — é um alternador')]
    public function testMarcadorAlterna(): void
    {
        $client             = static::createClient();
        [$usuario, $tenant] = $this->autenticar($client);
        $meta               = $this->criarMeta($tenant, $usuario, 'Liga e desliga');
        $id                 = (int) $meta->getId();

        $client->request('GET', '/tarefas/minhas');
        $token = $client->getCrawler()->filter('[data-acompanhar]')->first()->attr('data-token');

        $client->request('POST', "/tarefas/{$id}/acompanhar", ['_token' => $token]);
        self::assertTrue(json_decode((string) $client->getResponse()->getContent(), true)['acompanhando']);

        $client->request('POST', "/tarefas/{$id}/acompanhar", ['_token' => $token]);
        self::assertFalse(json_decode((string) $client->getResponse()->getContent(), true)['acompanhando']);
    }

    private function criarColega(Tenant $tenant): User
    {
        $container = static::getContainer();
        $em        = $container->get(EntityManagerInterface::class);
        $hasher    = $container->get(UserPasswordHasherInterface::class);

        $colega = new User();
        $colega->setEmail('colega_' . uniqid() . '@test.com');
        $colega->setFullName('Colega de Escritório');
        $colega->setRoles(['ROLE_USER']);
        $colega->setIsActive(true);
        $colega->setPassword($hasher->hashPassword($colega, 'senha123'));
        $em->persist($colega);

        $vinculo = new UserTenant($colega, $tenant);
        $em->persist($vinculo);
        $em->flush();

        return $colega;
    }

    /** Meta que o usuário logado CRIOU para outra pessoa — o caso que poluía a lista dele. */
    private function criarMetaDelegada(Tenant $tenant, User $criador, User $responsavel, string $titulo): Tarefa
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $pasta = new Pasta();
        $pasta->setNup('DEL-' . uniqid());
        $pasta->setNomeCliente('Cliente Delegada');
        $pasta->setResponsavel($criador);
        $pasta->setTenant($tenant);
        $em->persist($pasta);

        $tarefa = new Tarefa();
        $tarefa->setTitulo($titulo);
        $tarefa->setDescricao('Descrição da meta delegada');
        $tarefa->setPasta($pasta);
        $tarefa->setTenant($tenant);
        $tarefa->setCriadoPor($criador);
        $tarefa->addResponsavel($responsavel);
        $em->persist($tarefa);
        $em->flush();

        return $tarefa;
    }
}
