<?php

declare(strict_types=1);

namespace App\Tests\Auth\Functional;

use App\Auth\Controller\ConviteController;
use App\Entity\Auth\Invitation;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * O `type` do convite é gravado em inglês ('platform', 'office') e a leitura comparava com o português
 * ('plataforma', 'escritorio'): o convite de plataforma caía no formulário de escritório e "Meus convites"
 * nunca listava convite nenhum. As duas pontas agora usam as constantes de Invitation.
 */
#[CoversClass(ConviteController::class)]
final class TipoDoConviteTest extends JusPrimeWebTestCase
{
    #[TestDox('Convite de plataforma abre o formulário de plataforma, não o de escritório')]
    public function testConviteDePlataformaAbreOFormularioDePlataforma(): void
    {
        $client = static::createClient();
        $convite = $this->convite(Invitation::TIPO_PLATAFORMA, 'plataforma_' . uniqid() . '@adv.com');

        $crawler = $client->request('GET', '/convite/' . $convite->getToken());

        self::assertResponseIsSuccessful();
        self::assertSame(
            '/convite/' . $convite->getToken() . '/plataforma',
            $crawler->filter('form[method="post"]')->attr('action'),
        );
    }

    #[TestDox('"Meus convites" lista o convite de escritório pendente para o e-mail de quem está logado')]
    public function testMeusConvitesListaOConviteDeEscritorio(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $email = 'convidado_' . uniqid() . '@adv.com';

        $user = (new User())->setEmail($email)->setFullName('Pessoa Convidada')->setPassword('x')->setRoles(['ROLE_USER']);
        $em->persist($user);
        $meu = (new Tenant())->setName('Meu escritório');
        $em->persist($meu);
        $em->persist(new UserTenant($user, $meu));
        $em->flush();
        $convite = $this->convite(Invitation::TIPO_ESCRITORIO, $email);
        $this->logarComTenant($client, $user, $meu);

        $client->request('GET', '/convites');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('/convite/' . $convite->getToken(), (string) $client->getResponse()->getContent());
    }

    private function convite(string $tipo, string $email): Invitation
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $convite = new Invitation(email: $email, token: bin2hex(random_bytes(16)), type: $tipo, expiresAt: new \DateTimeImmutable('+7 days'));
        if ($tipo === Invitation::TIPO_ESCRITORIO) {
            $escritorio = (new Tenant())->setName('Escritório que convidou');
            $em->persist($escritorio);
            $convite->setTenant($escritorio);
        }
        $em->persist($convite);
        $em->flush();

        return $convite;
    }
}
