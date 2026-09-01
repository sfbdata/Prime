<?php

declare(strict_types=1);

namespace App\Tests\Expediente\Functional;

use App\Cliente\Entity\ClientePF;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Expediente\Controller\ExpedienteController;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PrioridadePasta;
use App\Profile\Entity\UserProfile;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Cobre os filtros da listagem de pastas do Expediente (acervo geral, endpoint AJAX):
 * busca livre, prioridade e período por data de abertura. Confirma que o controller
 * lê os parâmetros da query string e devolve apenas o subconjunto correto.
 */
#[CoversClass(ExpedienteController::class)]
final class ExpedienteFiltroPastasControllerTest extends JusPrimeWebTestCase
{
    #[TestDox('acervo geral: busca livre retorna só a pasta cujo nome da ação casa com o termo')]
    public function testBuscaLivreFiltraPorNomeAcao(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $admin  = $this->criarUsuario($tenant, 'Admin Filtro', admin: true);

        $sufixo = strtoupper(uniqid());
        $match  = $this->criarPasta($tenant, 'BUSCA-MATCH-' . $sufixo, nomeAcao: 'AcaoAlvo ' . $sufixo);
        $outra  = $this->criarPasta($tenant, 'BUSCA-OUTRA-' . $sufixo, nomeAcao: 'Coisa Diferente');

        $this->logarComTenant($client, $admin, $tenant);
        $client->xmlHttpRequest('GET', '/expediente/painel/acervo-geral?busca=' . rawurlencode('acaoalvo ' . $sufixo));

        self::assertResponseIsSuccessful();
        $body = (string) $client->getResponse()->getContent();
        self::assertStringContainsString($match->getNup(), $body);
        self::assertStringNotContainsString($outra->getNup(), $body);
    }

    #[TestDox('acervo geral: busca livre ignora acento e ç (reclamacao acha "Reclamação")')]
    public function testBuscaLivreIgnoraAcentoECedilha(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $admin  = $this->criarUsuario($tenant, 'Admin Acento', admin: true);

        $sufixo = strtoupper(uniqid());
        $match  = $this->criarPasta($tenant, 'ACENTO-' . $sufixo, nomeAcao: 'Reclamação Trabalhista');
        $outra  = $this->criarPasta($tenant, 'OUTRA-' . $sufixo, nomeAcao: 'Assunto Diferente');

        $this->logarComTenant($client, $admin, $tenant);
        // termo SEM ç, SEM ~ e em minúsculas ("reclamacao") deve achar "Reclamação"
        $client->xmlHttpRequest('GET', '/expediente/painel/acervo-geral?busca=' . rawurlencode('reclamacao'));

        self::assertResponseIsSuccessful();
        $body = (string) $client->getResponse()->getContent();
        self::assertStringContainsString($match->getNup(), $body);
        self::assertStringNotContainsString($outra->getNup(), $body);
    }

    #[TestDox('acervo geral: filtro de prioridade retorna só as pastas urgentes')]
    public function testFiltraPorPrioridade(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $admin  = $this->criarUsuario($tenant, 'Admin Prioridade', admin: true);

        $sufixo  = strtoupper(uniqid());
        $urgente = $this->criarPasta($tenant, 'PRIO-URG-' . $sufixo, prioridade: PrioridadePasta::Urgente);
        $normal  = $this->criarPasta($tenant, 'PRIO-NOR-' . $sufixo, prioridade: PrioridadePasta::Normal);

        $this->logarComTenant($client, $admin, $tenant);
        $client->xmlHttpRequest('GET', '/expediente/painel/acervo-geral?prioridade=urgente');

        self::assertResponseIsSuccessful();
        $body = (string) $client->getResponse()->getContent();
        self::assertStringContainsString($urgente->getNup(), $body);
        self::assertStringNotContainsString($normal->getNup(), $body);
    }

    #[TestDox('acervo geral: filtro de período retorna só as pastas abertas no intervalo')]
    public function testFiltraPorPeriodo(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $admin  = $this->criarUsuario($tenant, 'Admin Periodo', admin: true);

        $sufixo = strtoupper(uniqid());
        $dentro = $this->criarPasta($tenant, 'PER-IN-' . $sufixo, dataAbertura: new \DateTimeImmutable('2024-03-10 09:00:00'));
        $fora   = $this->criarPasta($tenant, 'PER-OUT-' . $sufixo, dataAbertura: new \DateTimeImmutable('2024-05-20 09:00:00'));

        $this->logarComTenant($client, $admin, $tenant);
        $client->xmlHttpRequest('GET', '/expediente/painel/acervo-geral?data_de=2024-03-01&data_ate=2024-03-31');

        self::assertResponseIsSuccessful();
        $body = (string) $client->getResponse()->getContent();
        self::assertStringContainsString($dentro->getNup(), $body);
        self::assertStringNotContainsString($fora->getNup(), $body);
    }

    #[TestDox('acervo geral: fragmento traz o modo lista (cartões), o toggle e o "Ordenar por"')]
    public function testAcervoGeralRenderizaCartoesDoModoLista(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $admin  = $this->criarUsuario($tenant, 'Admin Lista', admin: true);

        $sufixo = strtoupper(uniqid());
        $pasta  = $this->criarPasta($tenant, 'LISTA-' . $sufixo, nomeAcao: 'Ação Lista ' . $sufixo);

        $this->logarComTenant($client, $admin, $tenant);
        $client->xmlHttpRequest('GET', '/expediente/painel/acervo-geral');

        self::assertResponseIsSuccessful();
        $body = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('id="pastasView"', $body);
        self::assertStringContainsString('pastas-lista', $body);
        self::assertStringContainsString('pasta-card', $body);
        self::assertStringContainsString('js-view-toggle', $body);
        self::assertStringContainsString('pasta-view-toggle', $body);
        self::assertStringContainsString('js-pasta-ordenar', $body);
        // responsável agora é chip (avatar + nome) + menu compartilhado
        self::assertStringContainsString('pasta-resp-chip', $body);
        self::assertStringContainsString('id="pastaRespMenu"', $body);
        self::assertStringContainsString('pasta-resp-opcao', $body);
        // admin sem foto → avatar de iniciais (fallback) aparece no menu
        self::assertStringContainsString('resp-avatar-ini', $body);
        self::assertStringContainsString($pasta->getNup(), $body);
    }

    #[TestDox('acervo geral: pasta com responsável (com foto) mostra o chip com nome e avatar de foto')]
    public function testResponsavelComFotoRenderizaChipComNomeEFoto(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $admin  = $this->criarUsuario($tenant, 'Admin Resp', admin: true);
        $colab  = $this->criarUsuario($tenant, 'Mariana Prestes', fotoUrl: 'foto-mariana-teste.jpg');

        $sufixo = strtoupper(uniqid());
        $pasta  = $this->criarPasta($tenant, 'RESP-' . $sufixo, responsavel: $colab);

        $this->logarComTenant($client, $admin, $tenant);
        $client->xmlHttpRequest('GET', '/expediente/painel/acervo-geral');

        self::assertResponseIsSuccessful();
        $body = (string) $client->getResponse()->getContent();
        // chip do responsável com o nome e avatar de FOTO (não iniciais)
        self::assertStringContainsString('pasta-resp-chip', $body);
        self::assertStringContainsString('Mariana Prestes', $body);
        self::assertStringContainsString('resp-avatar-img', $body);
        self::assertStringContainsString('foto-mariana-teste.jpg', $body);
        // o colaborador aparece como opção no menu compartilhado
        self::assertStringContainsString('data-nome="Mariana Prestes"', $body);
    }

    // ----------------------------------------------------------------- helpers

    private function criarTenant(): Tenant
    {
        $em     = static::getContainer()->get(EntityManagerInterface::class);
        $tenant = new Tenant();
        $tenant->setName('Tenant FILTRO ' . uniqid());
        $em->persist($tenant);
        $em->flush();

        return $tenant;
    }

    private function criarUsuario(Tenant $tenant, string $nome, bool $admin = false, ?string $fotoUrl = null): User
    {
        $container = static::getContainer();
        $em        = $container->get(EntityManagerInterface::class);
        $hasher    = $container->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->setEmail('f_' . uniqid() . '@test.com');
        $user->setFullName($nome);
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setPassword($hasher->hashPassword($user, 'senha123'));
        $em->persist($user);

        if ($fotoUrl !== null) {
            $profile = new UserProfile($user);
            $profile->setFotoUrl($fotoUrl);
            $em->persist($profile);
            // EM compartilhado no teste: sem setar o lado inverso, o request leria
            // profile=null em memória (não faz lazy-load de entidade já gerenciada).
            $user->setProfile($profile);
        }

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

    #[TestDox('acervo geral: a coluna Cliente mostra o nome da PASTA, não o do cliente cadastrado')]
    public function testColunaClienteMostraONomeDaPastaMesmoComClienteCadastrado(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $admin  = $this->criarUsuario($tenant, 'Admin Coluna', admin: true);

        $sufixo = strtoupper(uniqid());
        $pasta  = $this->criarPasta($tenant, 'COLUNA-' . $sufixo, nomeAcao: 'AÇÃO MONITÓRIA');
        $pasta->setNomeCliente('APLC TOP LIFE 1 - JOAO BATISTA FERREIRA GOMES');
        $this->vincularClienteCadastrado($pasta, $tenant, 'JOAO BATISTA FERREIRA GOMES');

        $this->logarComTenant($client, $admin, $tenant);
        $crawler = $client->xmlHttpRequest('GET', '/expediente/painel/acervo-geral?busca=' . rawurlencode('COLUNA-' . $sufixo));

        self::assertResponseIsSuccessful();

        // ⚠️ Ancorar em CADA modo separadamente, não no corpo inteiro: o fragmento traz a TABELA e os
        // CARTÕES juntos, então uma asserção de "contém o texto" fica verde com a tabela errada,
        // satisfeita pelo cartão. Isso foi medido — a primeira versão deste teste passava com o
        // defeito reintroduzido na tabela.
        self::assertSame(
            'APLC TOP LIFE 1 - JOAO BATISTA FERREIRA GOMES',
            trim($crawler->filter('#tabelaPastas tbody tr td')->eq(1)->text()),
            'a coluna Cliente da TABELA mostra o nome da pasta, onde vive o padrão do escritório',
        );

        self::assertSame(
            'APLC TOP LIFE 1 - JOAO BATISTA FERREIRA GOMES',
            trim($crawler->filter('.pasta-card-cliente')->first()->text()),
            'o CARTÃO (modo lista) mostra o mesmo — os dois modos da tela não podem divergir',
        );
    }

    /**
     * Cliente PF cadastrado e vinculado — é o que fazia a listagem trocar o nome da pasta pelo nome
     * do cadastro, escondendo o prefixo da carteira.
     */
    private function vincularClienteCadastrado(Pasta $pasta, Tenant $tenant, string $nomeCompleto): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $cliente = new ClientePF();
        $cliente->setTenant($tenant);
        $cliente->setNomeCompleto($nomeCompleto);
        $cliente->setCpf('111.222.333-44');
        $cliente->setRg('');
        $cliente->setRgOrgaoExpedidor('');
        $cliente->setEmail('cli_' . uniqid() . '@test.com');
        $cliente->setCep('');
        $cliente->setEndereco('');
        $cliente->setCidade('');
        $cliente->setEstado('');
        $em->persist($cliente);

        $pasta->addCliente($cliente);
        $em->flush();
    }

    private function criarPasta(
        Tenant $tenant,
        string $nup,
        ?string $nomeAcao = null,
        ?PrioridadePasta $prioridade = null,
        ?\DateTimeImmutable $dataAbertura = null,
        ?User $responsavel = null,
    ): Pasta {
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $pasta = new Pasta();
        $pasta->setNup($nup);
        $pasta->setTenant($tenant);
        if ($nomeAcao !== null) {
            $pasta->setNomeAcao($nomeAcao);
        }
        if ($prioridade !== null) {
            $pasta->setPrioridade($prioridade);
        }
        if ($dataAbertura !== null) {
            $pasta->setDataAbertura($dataAbertura);
        }
        if ($responsavel !== null) {
            $pasta->setResponsavel($responsavel);
        }
        $em->persist($pasta);
        $em->flush();

        return $pasta;
    }
}
