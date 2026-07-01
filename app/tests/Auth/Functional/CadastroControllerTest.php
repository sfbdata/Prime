<?php

declare(strict_types=1);

namespace App\Tests\Auth\Functional;

use App\Auth\Controller\CadastroController;
use App\Auth\Entity\CadastroPendente;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[CoversClass(CadastroController::class)]
final class CadastroControllerTest extends JusPrimeWebTestCase
{
    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    private function criarPendente(string $email): CadastroPendente
    {
        $cadastro = new CadastroPendente(
            $email,
            'tok_' . uniqid(),
            'Dra. Nova',
            'Banca Nova ' . uniqid(),
            '12345',
            'SP',
            'HASH_QUALQUER',
            '1.2.3.4',
            new \DateTimeImmutable('+1 hour'),
            'UA',
        );
        $this->em()->persist($cadastro);
        $this->em()->flush();

        return $cadastro;
    }

    #[TestDox('GET /cadastro é acessível para anônimo e mostra o formulário')]
    public function testFormAcessivelAnonimo(): void
    {
        $client = static::createClient();
        $client->request('GET', '/cadastro');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form input[name="cadastro_publico[email]"]');
    }

    #[TestDox('POST /cadastro válido cria o cadastro pendente e redireciona para "verifique seu e-mail"')]
    public function testPostCriaCadastroPendente(): void
    {
        $client = static::createClient();
        $email  = 'ana_' . uniqid() . '@adv.com';

        $crawler = $client->request('GET', '/cadastro');
        $form = $crawler->selectButton('Criar conta e escritório')->form();
        $form['cadastro_publico[nomeCompleto]']   = 'Dra. Ana';
        $form['cadastro_publico[email]']          = $email;
        $form['cadastro_publico[senha]']          = 'segredo123';
        $form['cadastro_publico[oabNumero]']      = '12345';
        $form['cadastro_publico[oabUf]']          = 'SP';
        $form['cadastro_publico[nomeEscritorio]'] = 'Banca Ana';
        $form['cadastro_publico[aceiteTermos]']->tick();
        $client->submit($form);

        self::assertResponseRedirects();
        self::assertStringContainsString('verifique-email', (string) $client->getResponse()->headers->get('Location'));

        $cadastro = $this->em()->getRepository(CadastroPendente::class)->findOneBy(['email' => $email]);
        self::assertNotNull($cadastro);
        self::assertSame('pending', $cadastro->getStatus());
        self::assertNotSame('segredo123', $cadastro->getSenhaHash());
    }

    #[TestDox('POST /cadastro com e-mail já existente não cria pendente e re-renderiza o formulário')]
    public function testPostEmailExistenteRejeitado(): void
    {
        $client = static::createClient();
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $email  = 'existente_' . uniqid() . '@adv.com';

        $user = new User();
        $user->setEmail($email);
        $user->setFullName('Já Existe');
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setPassword($hasher->hashPassword($user, 'x'));
        $this->em()->persist($user);
        $this->em()->flush();

        $crawler = $client->request('GET', '/cadastro');
        $form = $crawler->selectButton('Criar conta e escritório')->form();
        $form['cadastro_publico[nomeCompleto]']   = 'Outro';
        $form['cadastro_publico[email]']          = $email;
        $form['cadastro_publico[senha]']          = 'segredo123';
        $form['cadastro_publico[oabNumero]']      = '999';
        $form['cadastro_publico[oabUf]']          = 'RJ';
        $form['cadastro_publico[nomeEscritorio]'] = 'Banca X';
        $form['cadastro_publico[aceiteTermos]']->tick();
        $client->submit($form);

        self::assertResponseIsSuccessful();
        self::assertNull($this->em()->getRepository(CadastroPendente::class)->findOneBy(['email' => $email]));
    }

    #[TestDox('GET /cadastro/confirmar/{token} cria conta ativa + escritório (dono) e redireciona ao login')]
    public function testConfirmarCriaContaEEscritorio(): void
    {
        $client   = static::createClient();
        $email    = 'nova_' . uniqid() . '@adv.com';
        $cadastro = $this->criarPendente($email);
        $nomeEsc  = $cadastro->getNomeEscritorio();

        $client->request('GET', '/cadastro/confirmar/' . $cadastro->getToken());

        self::assertResponseRedirects('/login');

        $em   = $this->em();
        $user = $em->getRepository(User::class)->findOneBy(['email' => $email]);
        self::assertNotNull($user);
        self::assertTrue($user->isActive());
        self::assertSame('12345', $user->getOabNumero());

        $tenant = $em->getRepository(Tenant::class)->findOneBy(['name' => $nomeEsc]);
        self::assertNotNull($tenant);
        self::assertSame($user->getId(), $tenant->getCriadoPor()?->getId());

        $vinculo = $em->getRepository(UserTenant::class)->findOneBy(['user' => $user, 'tenant' => $tenant]);
        self::assertNotNull($vinculo);
        self::assertTrue($vinculo->isActive());

        $atualizado = $em->getRepository(CadastroPendente::class)->find($cadastro->getId());
        self::assertSame('confirmado', $atualizado->getStatus());
    }

    #[TestDox('GET /cadastro/confirmar com token inválido mostra a página de erro (não redireciona)')]
    public function testConfirmarTokenInvalido(): void
    {
        $client = static::createClient();
        $client->request('GET', '/cadastro/confirmar/token-invalido-xyz');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Não foi possível confirmar');
    }

    #[TestDox('Usuário logado em /cadastro/confirmar é redirecionado e não cria conta nova (I1)')]
    public function testConfirmarBloqueadoParaUsuarioLogado(): void
    {
        $client   = static::createClient();
        $emailPen = 'pend_' . uniqid() . '@adv.com';
        $cadastro = $this->criarPendente($emailPen);

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $tenant = new Tenant();
        $tenant->setName('Escritório Logado ' . uniqid());
        $this->em()->persist($tenant);

        $logado = new User();
        $logado->setEmail('logado_' . uniqid() . '@adv.com');
        $logado->setFullName('Usuário Logado');
        $logado->setRoles(['ROLE_USER']);
        $logado->setIsActive(true);
        $logado->setPassword($hasher->hashPassword($logado, 'x'));
        $this->em()->persist($logado);
        $this->em()->persist(new UserTenant($logado, $tenant));
        $this->em()->flush();

        $this->logarComTenant($client, $logado, $tenant);
        $client->request('GET', '/cadastro/confirmar/' . $cadastro->getToken());

        self::assertResponseRedirects('/escritorio/selecionar');
        self::assertNull($this->em()->getRepository(User::class)->findOneBy(['email' => $emailPen]));
        self::assertSame('pending', $this->em()->getRepository(CadastroPendente::class)->find($cadastro->getId())->getStatus());
    }
}
