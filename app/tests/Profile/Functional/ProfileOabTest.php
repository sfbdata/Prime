<?php

declare(strict_types=1);

namespace App\Tests\Profile\Functional;

use App\Auth\Enum\StatusOab;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Profile\Controller\ProfileController;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;

#[CoversClass(ProfileController::class)]
final class ProfileOabTest extends JusPrimeWebTestCase
{
    #[TestDox('POST /perfil/oab sem autenticação redireciona para login')]
    public function testPostOabSemAutenticacaoRedireciona(): void
    {
        $client = static::createClient();
        $client->request('POST', '/perfil/oab');

        self::assertResponseRedirects();
        self::assertStringContainsString('login', (string) $client->getResponse()->headers->get('Location'));
    }

    #[TestDox('GET /perfil/oab retorna 405 (só POST)')]
    public function testGetOabRetorna405(): void
    {
        $client = static::createClient();
        $client->request('GET', '/perfil/oab');

        self::assertResponseStatusCodeSame(405);
    }

    #[TestDox('Informar OAB nova grava número/UF e status NaoVerificada (dormente)')]
    public function testInformarOabPersiste(): void
    {
        $client = static::createClient();
        [$user, $tenant] = $this->criarUsuarioComTenant();
        $this->logarComTenant($client, $user, $tenant);

        $crawler = $client->request('GET', '/perfil');
        self::assertResponseIsSuccessful();

        $form = $crawler->filter('#formOab')->form();
        $form['oab_perfil[oabNumero]'] = '12345';
        $form['oab_perfil[oabUf]']     = 'sp';
        $client->submit($form);

        self::assertResponseRedirects('/perfil');

        $recarregado = $this->recarregar($user);
        self::assertSame('12345', $recarregado->getOabNumero());
        self::assertSame('SP', $recarregado->getOabUf());
        self::assertSame(StatusOab::NaoVerificada, $recarregado->getOabStatus());
    }

    #[TestDox('Botão "verificar" com backend dormente mantém NaoVerificada e mostra mensagem clara')]
    public function testVerificarDormenteMostraMensagem(): void
    {
        $client = static::createClient();
        [$user, $tenant] = $this->criarUsuarioComTenant(oabNumero: '999', oabUf: 'SP', status: StatusOab::NaoVerificada);
        $this->logarComTenant($client, $user, $tenant);

        $crawler = $client->request('GET', '/perfil');
        $form    = $crawler->filter('form[action$="/perfil/oab/verificar"]')->form();
        $client->submit($form);

        self::assertResponseRedirects('/perfil');
        $client->followRedirect();
        self::assertStringContainsString('será revisada por um administrador', (string) $client->getResponse()->getContent());

        self::assertSame(StatusOab::NaoVerificada, $this->recarregar($user)->getOabStatus());
    }

    #[TestDox('Editar para número diferente reseta um status Confirmada')]
    public function testEditarDiferenteReseta(): void
    {
        $client = static::createClient();
        [$user, $tenant] = $this->criarUsuarioComTenant(oabNumero: '111', oabUf: 'SP', status: StatusOab::Confirmada);
        $this->logarComTenant($client, $user, $tenant);

        $crawler = $client->request('GET', '/perfil');
        $form    = $crawler->filter('#formOab')->form();
        $form['oab_perfil[oabNumero]'] = '222';
        $form['oab_perfil[oabUf]']     = 'SP';
        $client->submit($form);

        $recarregado = $this->recarregar($user);
        self::assertSame('222', $recarregado->getOabNumero());
        self::assertSame(StatusOab::NaoVerificada, $recarregado->getOabStatus());
    }

    // ----------------------------------------------------------------- helpers

    /** @return array{0: User, 1: Tenant} */
    private function criarUsuarioComTenant(
        ?string $oabNumero = null,
        ?string $oabUf = null,
        ?StatusOab $status = null,
    ): array {
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $tenant = new Tenant();
        $tenant->setName('Tenant OAB ' . uniqid());
        $em->persist($tenant);

        $user = new User();
        $user->setEmail('adv_' . uniqid() . '@test.com');
        $user->setFullName('João da Silva');
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setOabNumero($oabNumero);
        $user->setOabUf($oabUf);
        $user->setOabStatus($status);
        $em->persist($user);
        $em->persist(new UserTenant($user, $tenant));
        $em->flush();

        return [$user, $tenant];
    }

    private function recarregar(User $user): User
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $id = $user->getId();
        $em->clear();

        return $em->getRepository(User::class)->find($id);
    }
}
