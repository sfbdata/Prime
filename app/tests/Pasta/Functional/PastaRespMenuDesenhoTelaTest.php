<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Controller\PastaController;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Expediente\Controller\ExpedienteController;
use App\Pasta\Entity\Pasta;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * PAS-013: menu e chip do responsável seguem o desenho NA PASTA — dica "Filtrar
 * responsável", ✓ em cada opção (o CSS só o mostra na `.ativa`), chip vazio com
 * `bi-person-dash` e nome sem `.text-muted`.
 *
 * O `_resp_menu`/`_resp_chip` são os mesmos do Expediente, que NÃO muda: o
 * `_tabela` passa `respContexto: 'expediente'`. Por isso cada teste da pasta tem
 * o seu par no Expediente.
 */
#[CoversClass(PastaController::class)]
#[CoversClass(ExpedienteController::class)]
final class PastaRespMenuDesenhoTelaTest extends JusPrimeWebTestCase
{
    #[TestDox('pasta_show: a busca do menu diz "Filtrar responsável"')]
    public function testPastaMostraADicaDoDesenho(): void
    {
        $client                   = static::createClient();
        [$admin, $tenant, $pasta] = $this->criarCenario(comResponsavel: true);

        $this->logarComTenant($client, $admin, $tenant);
        $crawler = $client->request('GET', '/pasta/' . $pasta->getId());
        self::assertResponseIsSuccessful();

        self::assertCount(
            1,
            $crawler->filter('#pastaRespMenu > .pasta-resp-busca-wrap > input.pasta-resp-busca[placeholder="Filtrar responsável"]'),
            'na pasta a dica do campo é a do desenho'
        );
        self::assertSame(
            'Filtrar responsável',
            $crawler->filter('#pastaRespMenu .pasta-resp-busca')->attr('aria-label')
        );
    }

    #[TestDox('pasta_show: toda opção do menu leva o ✓, inclusive "Sem responsável", e o CSS só o mostra na ativa')]
    public function testPastaTemOCheckEmCadaOpcao(): void
    {
        $client                   = static::createClient();
        [$admin, $tenant, $pasta] = $this->criarCenario(comResponsavel: true);

        $this->logarComTenant($client, $admin, $tenant);
        $crawler = $client->request('GET', '/pasta/' . $pasta->getId());
        self::assertResponseIsSuccessful();

        $opcoes = $crawler->filter('#pastaRespMenu > .pasta-resp-lista > .pasta-resp-opcao');
        // admin + colega + "Sem responsável" (o usuário do outro escritório não entra).
        self::assertCount(3, $opcoes);
        self::assertCount(
            3,
            $crawler->filter('#pastaRespMenu > .pasta-resp-lista > .pasta-resp-opcao > i.bi-check2.pasta-resp-check'),
            'o ✓ é filho direto de cada opção; quem decide se aparece é a classe .ativa'
        );
        self::assertCount(
            0,
            $crawler->filter('#pastaRespMenu .pasta-resp-opcao .pasta-resp-nome.text-muted'),
            '"Sem responsável" no menu da pasta não é apagado (o !important do Bootstrap venceria o desenho)'
        );

        // O ✓ só aparece na ativa: sem a regra de CSS ele estaria em TODAS as linhas.
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('#pastaRespMenu .pasta-resp-check { display: none;', $html);
        self::assertStringContainsString('#pastaRespMenu .pasta-resp-opcao.ativa .pasta-resp-check { display: inline-block; }', $html);

        // O <style> não pode cair dentro do menu: ele tem só busca e lista.
        self::assertCount(2, $crawler->filter('#pastaRespMenu')->children());
    }

    #[TestDox('pasta_show: o menu não oferece colaborador de outro escritório')]
    public function testMenuDaPastaNaoVazaColaboradorDeOutroEscritorio(): void
    {
        $client                             = static::createClient();
        [$admin, $tenant, $pasta, $intruso] = $this->criarCenario(comResponsavel: true);

        $this->logarComTenant($client, $admin, $tenant);
        $crawler = $client->request('GET', '/pasta/' . $pasta->getId());
        self::assertResponseIsSuccessful();

        self::assertCount(
            0,
            $crawler->filter('#pastaRespMenu .pasta-resp-opcao[data-user-id="' . $intruso->getId() . '"]'),
            'colaborador de outro escritório não pode aparecer no menu'
        );
    }

    #[TestDox('pasta_show: o chip sem responsável usa bi-person-dash e o nome sem .text-muted')]
    public function testChipVazioDaPastaSegueODesenho(): void
    {
        $client                   = static::createClient();
        [$admin, $tenant, $pasta] = $this->criarCenario(comResponsavel: false);

        $this->logarComTenant($client, $admin, $tenant);
        $crawler = $client->request('GET', '/pasta/' . $pasta->getId());
        self::assertResponseIsSuccessful();

        $chip = $crawler->filter('.ps-cab-dados [data-campo="responsavel"] .pasta-resp-chip');
        self::assertCount(1, $chip);
        self::assertSame('', $chip->attr('data-resp-id'));
        self::assertCount(1, $chip->filter('.pasta-resp-chip > .resp-avatar-vazio > i.bi-person-dash'));
        self::assertCount(0, $chip->filter('i.bi-person'), 'o ícone antigo (sem o traço) saiu da pasta');
        self::assertCount(1, $chip->filter('.pasta-resp-chip > .pasta-resp-nome'));
        self::assertCount(0, $chip->filter('.pasta-resp-nome.text-muted'));
        self::assertSame('Sem responsável', trim($chip->filter('.pasta-resp-nome')->text()));
    }

    #[TestDox('Expediente: menu e chip continuam como eram — "Filtrar…", sem ✓, chip vazio com bi-person e apagado')]
    public function testExpedienteNaoMuda(): void
    {
        $client                   = static::createClient();
        [$admin, $tenant, $pasta] = $this->criarCenario(comResponsavel: false);

        $this->logarComTenant($client, $admin, $tenant);
        $crawler = $client->xmlHttpRequest('GET', '/expediente/painel/acervo-geral?busca=' . rawurlencode($pasta->getNup()));
        self::assertResponseIsSuccessful();

        self::assertCount(1, $crawler->filter('#pastaRespMenu .pasta-resp-busca[placeholder="Filtrar…"]'));
        self::assertSame(
            'Filtrar colaboradores pelo nome',
            $crawler->filter('#pastaRespMenu .pasta-resp-busca')->attr('aria-label')
        );
        self::assertCount(0, $crawler->filter('.pasta-resp-check'), 'o ✓ é só da pasta');
        self::assertCount(
            1,
            $crawler->filter('#pastaRespMenu .pasta-resp-opcao[data-user-id=""] .pasta-resp-nome.text-muted'),
            '"Sem responsável" segue apagado no menu do Expediente'
        );
        self::assertStringNotContainsString(
            '#pastaRespMenu .pasta-resp-check',
            (string) $client->getResponse()->getContent(),
            'o <style> do ✓ não vai para o Expediente'
        );

        // Tabela E cartão: os dois incluem o chip, por caminhos diferentes.
        $chipTabela = $crawler->filter('#tabelaPastas .pasta-resp-chip');
        self::assertCount(1, $chipTabela);
        self::assertCount(1, $chipTabela->filter('.pasta-resp-chip > .resp-avatar-vazio > i.bi-person'));
        self::assertCount(1, $chipTabela->filter('.pasta-resp-chip > .pasta-resp-nome.text-muted'));

        $chipCartao = $crawler->filter('.pastas-lista .pasta-card .pasta-resp-chip');
        self::assertCount(1, $chipCartao);
        self::assertCount(1, $chipCartao->filter('.pasta-resp-chip > .resp-avatar-vazio > i.bi-person'));
        self::assertCount(1, $chipCartao->filter('.pasta-resp-chip > .pasta-resp-nome.text-muted'));

        self::assertCount(0, $crawler->filter('.pasta-resp-chip i.bi-person-dash'), 'o ícone do desenho é só da pasta');
    }

    /** @return array{0: User, 1: Tenant, 2: Pasta, 3: User} */
    private function criarCenario(bool $comResponsavel): array
    {
        $container = static::getContainer();
        $em        = $container->get(EntityManagerInterface::class);
        $hasher    = $container->get(UserPasswordHasherInterface::class);

        $tenant = new Tenant();
        $tenant->setName('Tenant RespMenu ' . uniqid());
        $em->persist($tenant);

        $outro = new Tenant();
        $outro->setName('Tenant RespMenu Outro ' . uniqid());
        $em->persist($outro);

        $role = new TenantRole();
        $role->setTenant($tenant);
        $role->setName('Administrador ' . uniqid());
        $role->setIsSystem(true);
        $em->persist($role);

        // ROLE_USER + papel de sistema: sem o bypass do super admin, para o teste
        // de isolamento provar o filtro de tenant e não um atalho de papel.
        $admin = $this->criarUsuario($em, $hasher, 'Admin Do Menu', ['ROLE_USER']);
        $vinculo = new UserTenant($admin, $tenant);
        $vinculo->setTenantRole($role);
        $em->persist($vinculo);

        $colega = $this->criarUsuario($em, $hasher, 'Colega Do Menu', ['ROLE_USER']);
        $em->persist(new UserTenant($colega, $tenant));

        $intruso = $this->criarUsuario($em, $hasher, 'Intruso De Outro Escritorio', ['ROLE_USER']);
        $em->persist(new UserTenant($intruso, $outro));

        $pasta = new Pasta();
        $pasta->setNup('NUP-RMENU-' . strtoupper(uniqid()));
        $pasta->setTenant($tenant);
        $pasta->setCriadoPor($admin);
        if ($comResponsavel) {
            $pasta->setResponsavel($admin);
        }
        $em->persist($pasta);

        $em->flush();

        return [$admin, $tenant, $pasta, $intruso];
    }

    /** @param list<string> $roles */
    private function criarUsuario(
        EntityManagerInterface $em,
        UserPasswordHasherInterface $hasher,
        string $nome,
        array $roles,
    ): User {
        $user = new User();
        $user->setEmail('rmenu_' . uniqid() . '@test.com');
        $user->setFullName($nome);
        $user->setRoles($roles);
        $user->setIsActive(true);
        $user->setPassword($hasher->hashPassword($user, 'senha123'));
        $em->persist($user);

        return $user;
    }
}
