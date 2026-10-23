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
        // Pelo id, sem o token do link do e-mail (achado de auditoria interna F6).
        self::assertSelectorExists(sprintf('a[href="/convites/%d"]', $convite->getId()));
        self::assertStringNotContainsString($convite->getToken(), (string) $client->getResponse()->getContent());
    }

    #[TestDox('"Meus convites" não mostra convite pendente de outro e-mail')]
    public function testMeusConvitesNaoMostraConviteDeOutroEmail(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = (new User())->setEmail('eu_' . uniqid() . '@adv.com')->setFullName('Eu Mesma')->setPassword('x')->setRoles(['ROLE_USER']);
        $em->persist($user);
        $meu = (new Tenant())->setName('Meu escritório');
        $em->persist($meu);
        $em->persist(new UserTenant($user, $meu));
        $em->flush();
        $alheio = $this->convite(Invitation::TIPO_ESCRITORIO, 'outra_' . uniqid() . '@adv.com');
        $this->logarComTenant($client, $user, $meu);

        $client->request('GET', '/convites');

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists(sprintf('a[href="/convites/%d"]', $alheio->getId()));
        self::assertStringNotContainsString($alheio->getToken(), (string) $client->getResponse()->getContent());
    }

    #[TestDox('Aceite de plataforma de ponta a ponta: cria a conta sem escritório e marca o convite como aceito')]
    public function testAceiteDePlataformaCriaAConta(): void
    {
        $client = static::createClient();
        $email = 'plataforma_' . uniqid() . '@adv.com';
        $convite = $this->convite(Invitation::TIPO_PLATAFORMA, $email);

        $this->aceitarPlataforma($client, $convite, senha: 'SenhaBoa123');

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $user = $em->getRepository(User::class)->findOneBy(['email' => $email]);
        self::assertNotNull($user, 'A conta tinha que nascer.');
        self::assertSame([], $em->getRepository(UserTenant::class)->findBy(['user' => $user]), 'Conta de plataforma nasce sem escritório.');
        self::assertSame('accepted', $em->getRepository(Invitation::class)->find($convite->getId())?->getStatus());
    }

    #[TestDox('Aceite de plataforma recusa senha curta enviada por POST direto (sem o required do navegador)')]
    public function testAceiteDePlataformaRecusaSenhaCurta(): void
    {
        $client = static::createClient();
        $email = 'curta_' . uniqid() . '@adv.com';
        $convite = $this->convite(Invitation::TIPO_PLATAFORMA, $email);

        $this->aceitarPlataforma($client, $convite, senha: 'a');

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        self::assertNull($em->getRepository(User::class)->findOneBy(['email' => $email]));
        self::assertSame('pending', $em->getRepository(Invitation::class)->find($convite->getId())?->getStatus());
    }

    #[TestDox('Convite de escritório mandado para a rota de plataforma não cria conta nem é aceito')]
    public function testConviteDeEscritorioNaRotaDePlataformaERecusado(): void
    {
        $client = static::createClient();
        $email = 'escritorio_' . uniqid() . '@adv.com';
        $convite = $this->convite(Invitation::TIPO_ESCRITORIO, $email);
        // O CSRF é o mesmo nos dois formulários (convite_<token>): tira-se da página do próprio convite.
        $crawler = $client->request('GET', '/convite/' . $convite->getToken());
        $token = (string) $crawler->filter('form[method="post"] input[name="_token"]')->attr('value');

        $client->request('POST', '/convite/' . $convite->getToken() . '/plataforma', [
            '_token' => $token, 'full_name' => 'Pessoa Teste', 'senha' => 'SenhaBoa123',
            'oab_numero' => '123456', 'oab_uf' => 'DF', 'aceite_termos' => '1',
        ]);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        self::assertNull($em->getRepository(User::class)->findOneBy(['email' => $email]));
        self::assertSame('pending', $em->getRepository(Invitation::class)->find($convite->getId())?->getStatus());
    }

    private function aceitarPlataforma(\Symfony\Bundle\FrameworkBundle\KernelBrowser $client, Invitation $convite, string $senha): void
    {
        $crawler = $client->request('GET', '/convite/' . $convite->getToken());
        $form = $crawler->filter('form[method="post"]')->form([
            'full_name' => 'Pessoa Teste',
            'senha' => $senha,
            'oab_numero' => '123456',
            'oab_uf' => 'DF',
        ]);
        $form['aceite_termos']->tick();
        $client->submit($form);
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
