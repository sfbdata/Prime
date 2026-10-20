<?php

declare(strict_types=1);

namespace App\Tests\Central\Functional;

use App\Tests\Central\Support\CentralHttpTrait;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Tests\Inteligencia\Support\CriaFixturesInteligenciaTrait;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Selo de presença no avatar (desenho 03 VS 18.10.26, L.67; spec `presenca-e-ponto-no-cabecalho.md` §2): no menu do
 * usuário, fora do botão do menu, só com vínculo ativo; o painel é montado pelo JS com os dados da Central.
 */
final class PresencaNoCabecalhoTest extends JusPrimeWebTestCase
{
    use CriaFixturesInteligenciaTrait;
    use CentralHttpTrait;

    #[TestDox('Com vínculo: o selo fica no .menu-usuario, fora do botão do menu, com a equipe da Central e a chave do usuário neste escritório')]
    public function testSeloComVinculo(): void
    {
        $client = $this->cliente();
        [, $tenant] = $this->criarAdmin();
        $ana = $this->colega($tenant, 'ana');
        $this->logarComTenant($client, $ana, $tenant);

        $client->request('GET', '/notificacoes');
        self::assertResponseIsSuccessful();

        $raiz = $client->getCrawler()->filter('.menu-usuario > .presenca-cab[data-presenca-cabecalho]');
        self::assertCount(1, $raiz);
        self::assertSame('/central/equipe', $raiz->attr('data-url-equipe'));
        self::assertSame($tenant->getId() . ':' . $ana->getId(), $raiz->attr('data-chave'));
        self::assertSame((string) $ana->getId(), $raiz->attr('data-eu-id'));
        self::assertSelectorExists('.presenca-cab > button.presenca-cab-selo[data-presenca-selo][hidden][aria-haspopup="dialog"][aria-expanded="false"]');
        self::assertSelectorNotExists('[data-mu-toggle] [data-presenca-selo]');
        self::assertCount(1, $client->getCrawler()->filter('script[src$="/js/presenca-cabecalho.js"]'));
        self::assertCount(1, $client->getCrawler()->filter('link[href$="/css/presenca-cabecalho.css"]'));
        // A janela da Central (quem bate) diz o limite de inatividade.
        self::assertSelectorExists('[data-central][data-inativo-apos-min="60"]');
        // Só suas: nada de ponto nem de horas sai no HTML.
        self::assertStringNotContainsString('presenca-horas:', (string) $client->getResponse()->getContent());
    }

    #[TestDox('Master sem vínculo no escritório da sessão: sem selo (o bypass de super admin não vale para a Central)')]
    public function testMasterSemVinculo(): void
    {
        $client = $this->cliente();
        [, $tenant] = $this->criarAdmin();
        $hasher = static::getContainer()->get(\Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface::class);
        $master = new \App\Entity\Auth\User();
        $master->setEmail('master_presenca_' . uniqid() . '@test.com');
        $master->setFullName('Master Presenca');
        $master->setRoles(['ROLE_SUPER_ADMIN']);
        $master->setIsActive(true);
        $master->setPassword($hasher->hashPassword($master, 'senha123'));
        $this->em()->persist($master);
        $this->em()->flush();
        $this->logarComTenant($client, $master, $tenant);

        $client->request('GET', '/notificacoes');
        if ($client->getResponse()->isRedirect()) {
            $client->followRedirect();
        }
        self::assertSelectorNotExists('[data-presenca-cabecalho]');
    }

    #[TestDox('Vínculo inativo: sem selo (a Central é posse, não permissão)')]
    public function testSemSeloSemVinculo(): void
    {
        $client = $this->cliente();
        [, $tenant] = $this->criarAdmin();
        $ana = $this->colega($tenant, 'ana');
        $this->logarComTenant($client, $ana, $tenant);
        $this->em()->getConnection()->executeStatement(
            'UPDATE user_tenant SET is_active = false WHERE user_id = ? AND tenant_id = ?',
            [$ana->getId(), $tenant->getId()],
        );

        $client->request('GET', '/notificacoes');
        if ($client->getResponse()->isRedirect()) {
            $client->followRedirect();
        }
        self::assertSelectorNotExists('[data-presenca-cabecalho]');
    }
}
