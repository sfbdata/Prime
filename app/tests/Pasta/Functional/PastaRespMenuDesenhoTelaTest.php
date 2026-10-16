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
        // PAS-013b: o contexto vai no chip, para o _resp_script redesenhar igual depois do salvar.
        self::assertSame('pasta', $chip->attr('data-contexto'));
    }

    #[TestDox('PAS-013b: depois de salvar "Sem responsável", o _resp_script redesenha o chip vazio pelo contexto — bi-person-dash sem .text-muted na pasta, bi-person apagado no Expediente')]
    public function testRedesenhoDoChipVazioSegueOContexto(): void
    {
        $js = (string) file_get_contents(__DIR__ . '/../../../templates/pasta/_resp_script.html.twig');
        $ini = strpos($js, 'function renderChip(chip, userId, nome, fotoUrl) {');
        self::assertNotFalse($ini);
        $fim = strpos($js, "html += '<i class=\"bi bi-chevron-down pasta-resp-caret\"></i>';", $ini);
        self::assertNotFalse($fim);
        $render = substr($js, $ini, $fim - $ini);

        self::assertStringContainsString("var naPasta = chip.dataset.contexto === 'pasta';", $render);
        self::assertStringContainsString("<i class=\"bi ' + (naPasta ? 'bi-person-dash' : 'bi-person') + '\"></i></span>'", $render);
        self::assertStringContainsString("'<span class=\"pasta-resp-nome' + (naPasta ? '' : ' text-muted') + '\">Sem responsável</span>'", $render);
        // O desenho antigo, fixo, não pode voltar: era ele que desfazia o chip da pasta.
        self::assertStringNotContainsString('<i class="bi bi-person"></i>', $render);
        self::assertStringNotContainsString('<span class="pasta-resp-nome text-muted">Sem responsável</span>', $render);
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

        // A tabela inclui o chip; o cartão do desenho 1.1 (E12) mostra o responsável só como avatar e nome.
        $chipTabela = $crawler->filter('#tabelaPastas .pasta-resp-chip');
        self::assertCount(1, $chipTabela);
        self::assertCount(1, $chipTabela->filter('.pasta-resp-chip > .resp-avatar-vazio > i.bi-person'));
        self::assertCount(1, $chipTabela->filter('.pasta-resp-chip > .pasta-resp-nome.text-muted'));
        self::assertSame('expediente', $chipTabela->attr('data-contexto'));

        $peCartao = $crawler->filter('.pastas-lista > .exp-cartao > .exp-cartao-pe');
        self::assertCount(1, $peCartao);
        self::assertSame('?', trim($peCartao->filter('.exp-simples-av.is-vazio')->text()));
        self::assertSame('Sem responsável', trim($peCartao->filter('.exp-cartao-resp')->text()));

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
