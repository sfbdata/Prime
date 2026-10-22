<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Functional;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Ponto\Controller\FeriadoController;
use App\Ponto\Entity\Feriado;
use App\Ponto\Form\FeriadoType;
use App\Ponto\Repository\FeriadoRepository;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Formulário de feriado com a data vazia: antes estourava TypeError em Feriado::setData(null)
 * (erro 500). Agora volta 422 com o erro no campo Data e nada é gravado.
 */
#[CoversClass(FeriadoController::class)]
#[CoversClass(FeriadoType::class)]
final class FeriadoDataVaziaControllerTest extends JusPrimeWebTestCase
{
    #[TestDox('Novo feriado com a data vazia volta 422 com o erro no campo e não grava')]
    public function testNovoComDataVaziaNaoGrava(): void
    {
        [$client, $tenant] = $this->prepararAdminLogado();
        $nome = 'Feriado sem data ' . uniqid();

        $crawler = $client->request('GET', '/tenant/' . $tenant->getId() . '/feriados/novo');
        self::assertResponseIsSuccessful();

        $form = $crawler->selectButton('Salvar Feriado')->form([
            'feriado[nome]' => $nome,
            'feriado[data]' => '',
        ]);
        $client->submit($form);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#feriado_data + .invalid-feedback', 'Informe a data do feriado.');
        self::assertNull($this->repositorio()->findOneBy(['nome' => $nome, 'tenant' => $tenant]));
    }

    #[TestDox('Editar feriado com a data vazia volta 422 com o erro no campo e o feriado fica como estava')]
    public function testEditarComDataVaziaMantemFeriado(): void
    {
        [$client, $tenant] = $this->prepararAdminLogado();

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $feriado = new Feriado();
        $feriado->setTenant($tenant);
        $feriado->setNome('Natal');
        $feriado->setData(new \DateTime('2026-12-25'));
        $feriado->setRecorrente(true);
        $em->persist($feriado);
        $em->flush();
        $id = $feriado->getId();

        $crawler = $client->request('GET', '/tenant/' . $tenant->getId() . '/feriados/' . $id . '/editar');
        self::assertResponseIsSuccessful();

        $form = $crawler->filter('form[name="feriado"]')->form([
            'feriado[nome]' => 'Natal alterado',
            'feriado[data]' => '',
        ]);
        $client->submit($form);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#feriado_data + .invalid-feedback', 'Informe a data do feriado.');

        $em->clear();
        $salvo = $this->repositorio()->find($id);
        self::assertNotNull($salvo);
        self::assertSame('Natal', $salvo->getNome());
        self::assertSame('2026-12-25', $salvo->getData()?->format('Y-m-d'));
    }

    #[TestDox('Novo feriado com data válida continua gravando e redireciona para a lista')]
    public function testNovoComDataValidaGrava(): void
    {
        [$client, $tenant] = $this->prepararAdminLogado();
        $nome = 'Aniversário da cidade ' . uniqid();

        $crawler = $client->request('GET', '/tenant/' . $tenant->getId() . '/feriados/novo');
        $form = $crawler->selectButton('Salvar Feriado')->form([
            'feriado[nome]' => $nome,
            'feriado[data]' => '2026-08-15',
        ]);
        $client->submit($form);

        self::assertResponseRedirects('/tenant/' . $tenant->getId() . '/feriados');

        $salvo = $this->repositorio()->findOneBy(['nome' => $nome, 'tenant' => $tenant]);
        self::assertNotNull($salvo);
        self::assertSame('2026-08-15', $salvo->getData()?->format('Y-m-d'));
    }

    #[TestDox('Novo feriado com o nome vazio volta 422 e não grava')]
    public function testNovoComNomeVazioNaoGrava(): void
    {
        [$client, $tenant] = $this->prepararAdminLogado();

        $crawler = $client->request('GET', '/tenant/' . $tenant->getId() . '/feriados/novo');
        $form = $crawler->selectButton('Salvar Feriado')->form([
            'feriado[nome]' => '',
            'feriado[data]' => '2026-09-07',
        ]);
        $client->submit($form);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#feriado_nome + .invalid-feedback', 'O nome é obrigatório.');
        self::assertSame([], $this->repositorio()->findBy(['tenant' => $tenant]));
    }

    #[TestDox('Editar feriado com o nome vazio volta 422 e o feriado fica como estava')]
    public function testEditarComNomeVazioMantemFeriado(): void
    {
        [$client, $tenant] = $this->prepararAdminLogado();

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $feriado = new Feriado();
        $feriado->setTenant($tenant);
        $feriado->setNome('Natal');
        $feriado->setData(new \DateTime('2026-12-25'));
        $feriado->setRecorrente(true);
        $em->persist($feriado);
        $em->flush();
        $id = $feriado->getId();

        $crawler = $client->request('GET', '/tenant/' . $tenant->getId() . '/feriados/' . $id . '/editar');
        $form = $crawler->filter('form[name="feriado"]')->form([
            'feriado[nome]' => '',
            'feriado[data]' => '2026-12-31',
        ]);
        $client->submit($form);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#feriado_nome + .invalid-feedback', 'O nome é obrigatório.');

        $em->clear();
        $salvo = $this->repositorio()->find($id);
        self::assertNotNull($salvo);
        self::assertSame('Natal', $salvo->getNome());
        self::assertSame('2026-12-25', $salvo->getData()?->format('Y-m-d'));
    }

    /** @return array{0: KernelBrowser, 1: Tenant} */
    private function prepararAdminLogado(): array
    {
        $client = static::createClient();
        $client->disableReboot();

        $container = static::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $hasher = $container->get(UserPasswordHasherInterface::class);

        $tenant = new Tenant();
        $tenant->setName('Tenant Feriado ' . uniqid());
        $em->persist($tenant);

        $user = new User();
        $user->setEmail('feriado_admin_' . uniqid() . '@test.com');
        $user->setFullName('Admin Feriado');
        $user->setRoles(['ROLE_SUPER_ADMIN']);
        $user->setIsActive(true);
        $user->setPassword($hasher->hashPassword($user, 'senha123'));
        $em->persist($user);
        $em->persist(new UserTenant($user, $tenant));
        $em->flush();

        $this->logarComTenant($client, $user, $tenant);

        return [$client, $tenant];
    }

    private function repositorio(): FeriadoRepository
    {
        return static::getContainer()->get(FeriadoRepository::class);
    }
}
