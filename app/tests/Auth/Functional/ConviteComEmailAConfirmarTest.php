<?php

declare(strict_types=1);

namespace App\Tests\Auth\Functional;

use App\Auth\Controller\ConviteController;
use App\Entity\Auth\Invitation;
use App\Entity\Auth\User;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * O cadastro do desenho 10 entra antes de o e-mail ser confirmado. Quem cria a conta com o e-mail de outra pessoa
 * não pode, por isso, ver o convite de escritório mandado para aquele e-mail: as listas entregam o token do aceite.
 */
#[CoversClass(ConviteController::class)]
final class ConviteComEmailAConfirmarTest extends JusPrimeWebTestCase
{
    /** @return iterable<string, array{string}> */
    public static function listas(): iterable
    {
        yield 'meus convites' => ['/convites'];
        yield 'seleção de escritório' => ['/escritorio/selecionar'];
    }

    #[DataProvider('listas')]
    #[TestDox('E-mail a confirmar: o convite para o mesmo e-mail não aparece com o token')]
    public function testEmailAConfirmarNaoVeOToken(string $lista): void
    {
        $client = static::createClient();
        [$user, $convite] = $this->cenario(emailConfirmado: false);
        $client->loginUser($user);
        $this->marcarTermosAceitos($client);
        $client->followRedirects(true);

        $client->request('GET', $lista);

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString($convite->getToken(), (string) $client->getResponse()->getContent());
    }

    #[DataProvider('listas')]
    #[TestDox('E-mail confirmado: o convite continua aparecendo como antes')]
    public function testEmailConfirmadoVeOConvite(string $lista): void
    {
        $client = static::createClient();
        [$user, $convite] = $this->cenario(emailConfirmado: true);
        $client->loginUser($user);
        $this->marcarTermosAceitos($client);
        $client->followRedirects(true);

        $client->request('GET', $lista);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString($convite->getToken(), (string) $client->getResponse()->getContent());
    }

    /** @return array{User, Invitation} */
    private function cenario(bool $emailConfirmado): array
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $email = 'a_confirmar_' . uniqid() . '@adv.com';

        $user = new User();
        $user->setEmail($email);
        $user->setFullName('Pessoa Sem Escritório');
        $user->setPassword('x');
        $user->setRoles(['ROLE_USER']);
        if (!$emailConfirmado) {
            $user->marcarEmailAConfirmar(new \DateTimeImmutable());
        }
        $em->persist($user);

        $tenant = new \App\Entity\Tenant\Tenant();
        $tenant->setName('Escritório que convidou');
        $em->persist($tenant);

        $convite = new Invitation(email: $email, token: bin2hex(random_bytes(16)), type: 'escritorio', expiresAt: new \DateTimeImmutable('+7 days'));
        $convite->setTenant($tenant);
        $em->persist($convite);
        $em->flush();

        return [$user, $convite];
    }
}
