<?php

declare(strict_types=1);

namespace App\Tests\Comercial\Functional;

use App\Comercial\Controller\BaseDeContatosDoMasterController;
use App\Comercial\Entity\ContatoComercial;
use App\Comercial\Repository\ContatoComercialRepository;
use App\Entity\Audit\AuditLog;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Shared\Email\Descadastro\DescadastroDeEmailInterface;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

/** A Base de contatos do Comercial no /master: só o Master, CSRF nas escritas, lista, incluir, editar, excluir, importar, exportar. */
#[CoversClass(BaseDeContatosDoMasterController::class)]
final class BaseDeContatosDoMasterControllerTest extends JusPrimeWebTestCase
{
    private const URL = '/master/comercial/base-de-contatos';

    #[TestDox('Quem não é Master não lista, não exporta nem escreve: 403')]
    public function testSoMaster(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $tenant = (new Tenant())->setName('Escritório Base ' . uniqid());
        $em->persist($tenant);
        $usuario = $this->usuario(['ROLE_USER']);
        $em->persist(new UserTenant($usuario, $tenant));
        $em->flush();
        $this->logarComTenant($client, $usuario, $tenant);

        foreach ([self::URL, self::URL . '.csv'] as $url) {
            $client->request('GET', $url);
            self::assertResponseStatusCodeSame(403, $url);
        }
        foreach ([self::URL, self::URL . '/importar', self::URL . '/excluir', self::URL . '/1'] as $url) {
            $this->postar($client, $url, ['email' => 'a@b.com']);
            self::assertResponseStatusCodeSame(403, $url);
        }
    }

    #[TestDox('Escrever sem o token CSRF do Comercial: 403 e nada é gravado')]
    public function testCsrf(): void
    {
        $client = $this->clienteMaster();
        $email = uniqid('csrf') . '@ex.com';

        foreach ([self::URL => ['email' => $email], self::URL . '/importar' => ['csv' => $email], self::URL . '/excluir' => ['ids' => [1]], self::URL . '/1' => ['nome' => 'X']] as $url => $corpo) {
            $client->request('POST', $url, server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => 'errado'], content: json_encode($corpo, \JSON_THROW_ON_ERROR));
            self::assertResponseStatusCodeSame(403, $url);
        }
        self::assertNull($this->repo()->porEmail($email));
    }

    #[TestDox('Incluir, listar com a situação do descadastro, buscar sem acento, editar o nome e excluir; cada escrita vai ao audit_log')]
    public function testIncluirListarEditarExcluir(): void
    {
        $client = $this->clienteMaster();
        $m = uniqid('bcf');
        $this->postar($client, self::URL, ['email' => "Joao.$m@Ex.com", 'nome' => 'João Conceição']);
        self::assertResponseIsSuccessful();
        $this->postar($client, self::URL, ['email' => "Joao.$m@ex.com"]);
        self::assertResponseStatusCodeSame(422, 'repetido');
        $this->postar($client, self::URL, ['email' => 'sem-arroba']);
        self::assertResponseStatusCodeSame(422);
        $this->postar($client, self::URL, ['email' => "sai.$m@ex.com"]);
        static::getContainer()->get(DescadastroDeEmailInterface::class)->descadastrar(null, "sai.$m@ex.com", 'comercial_campanhas', 'link_do_email');

        $client->request('GET', self::URL . '?q=' . $m);
        $lista = $this->json($client);
        self::assertSame(2, $lista['total']);
        self::assertSame(20, $lista['porPagina']);
        $porEmail = array_column($lista['itens'], null, 'email');
        self::assertSame('ativo', $porEmail["joao.$m@ex.com"]['situacao']);
        self::assertSame('manual', $porEmail["joao.$m@ex.com"]['origem']);
        self::assertSame('descadastrado', $porEmail["sai.$m@ex.com"]['situacao']);
        self::assertNull($porEmail["sai.$m@ex.com"]['ultimoConvite']);

        $client->request('GET', self::URL . '?q=' . rawurlencode('joao conceicao'));
        self::assertGreaterThanOrEqual(1, $this->json($client)['total']);

        $id = $porEmail["joao.$m@ex.com"]['id'];
        $this->postar($client, self::URL . '/' . $id, ['nome' => 'Joana']);
        self::assertResponseIsSuccessful();
        self::assertSame('Joana', $this->repo()->porEmail("joao.$m@ex.com")?->getNome());
        $this->postar($client, self::URL . '/999999999', ['nome' => 'X']);
        self::assertResponseStatusCodeSame(422);

        $this->postar($client, self::URL . '/excluir', ['ids' => [$id, $porEmail["sai.$m@ex.com"]['id']]]);
        self::assertSame(2, $this->json($client)['excluidos']);
        $this->postar($client, self::URL . '/excluir', ['ids' => []]);
        self::assertResponseStatusCodeSame(422);
        $client->request('GET', self::URL . '?q=' . $m);
        self::assertSame(0, $this->json($client)['total']);

        $acoes = static::getContainer()->get(EntityManagerInterface::class)->getRepository(AuditLog::class)->findBy(['actorUserId' => $this->master->getId(), 'entityClass' => ContatoComercial::class]);
        self::assertEqualsCanonicalizing(['ctt_inc', 'ctt_inc', 'ctt_nome', 'ctt_exc'], array_map(static fn (AuditLog $a): string => $a->getAction(), $acoes));
    }

    #[TestDox('Importar: resposta com novos, atualizados e inválidos; arquivo sem e-mail é 422; o descadastro não é tocado')]
    public function testImportar(): void
    {
        $client = $this->clienteMaster();
        $m = uniqid('imp');
        $csv = "email,nome\nA.$m@ex.com,Ana\nb.$m@ex.com;Beto\nruim@x\n";
        $this->postar($client, self::URL . '/importar', ['csv' => $csv, 'arquivo' => 'clientes.csv']);
        self::assertResponseIsSuccessful();
        self::assertSame(['novos' => 2, 'atualizados' => 0, 'jaExistiam' => 0, 'invalidos' => 1], $this->json($client));

        $this->postar($client, self::URL . '/importar', ['csv' => "a.$m@ex.com;Ana Paula\nc.$m@ex.com\n", 'arquivo' => 'clientes2.csv']);
        self::assertSame(['novos' => 1, 'atualizados' => 1, 'jaExistiam' => 0, 'invalidos' => 0], $this->json($client));
        self::assertSame('Ana Paula', $this->repo()->porEmail("a.$m@ex.com")?->getNome());
        self::assertSame('clientes.csv', $this->repo()->porEmail("a.$m@ex.com")?->getOrigemDetalhe());

        $this->postar($client, self::URL . '/importar', ['csv' => "só texto\n"]);
        self::assertResponseStatusCodeSame(422);
        $this->postar($client, self::URL . '/importar', ['csv' => str_repeat('x', 1048577)]);
        self::assertResponseStatusCodeSame(422);
        $this->postar($client, self::URL . '/importar', ['arquivo' => 'vazio.csv']);
        self::assertResponseStatusCodeSame(422);
    }

    #[TestDox('Exportar: CSV com BOM e colunas, só o que a busca atual traz')]
    public function testExportar(): void
    {
        $client = $this->clienteMaster();
        $m = uniqid('exp');
        $this->postar($client, self::URL . '/importar', ['csv' => "x.$m@ex.com;Xis\ny.$m@ex.com;Ypsilon\nfora@" . uniqid() . ".com;Fora\n", 'arquivo' => 'e.csv']);

        $client->request('GET', self::URL . '.csv?q=' . $m);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('text/csv', (string) $client->getResponse()->headers->get('Content-Type'));
        self::assertStringContainsString('base-de-contatos-bluejus.csv', (string) $client->getResponse()->headers->get('Content-Disposition'));
        $corpo = (string) $client->getResponse()->getContent();
        self::assertStringStartsWith("\u{FEFF}\"E-mail\";\"Nome\";\"Origem\";\"Situação\";\"Último convite\";\"Criado em\"", $corpo);
        self::assertCount(3, explode("\n", $corpo));
        self::assertStringContainsString("\"x.$m@ex.com\";\"Xis\";\"Importação\";\"Ativo\"", $corpo);
        self::assertStringNotContainsString('Fora', $corpo);

        $exportacoes = array_values(array_filter(
            static::getContainer()->get(EntityManagerInterface::class)->getRepository(AuditLog::class)->findBy(['actorUserId' => $this->master->getId(), 'action' => 'ctt_exp']),
        ));
        self::assertCount(1, $exportacoes);
        self::assertSame(['linhas' => 2, 'busca' => $m], $exportacoes[0]->getChanges());
    }

    /** @param array<mixed> $corpo */
    private function postar(KernelBrowser $client, string $url, array $corpo): void
    {
        $client->request('POST', $url, server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => 'TOKEN_master_comercial'], content: json_encode($corpo, \JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed> */
    private function json(KernelBrowser $client): array
    {
        return json_decode((string) $client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
    }

    private function repo(): ContatoComercialRepository
    {
        static::getContainer()->get(EntityManagerInterface::class)->clear();

        return static::getContainer()->get(ContatoComercialRepository::class);
    }

    private User $master;

    private function clienteMaster(): KernelBrowser
    {
        $client = static::createClient();
        $client->disableReboot();
        static::getContainer()->set('security.csrf.token_storage', new class () implements ClearableTokenStorageInterface {
            public function getToken(string $tokenId): string
            {
                return 'TOKEN_' . $tokenId;
            }

            public function setToken(string $tokenId, string $token): void
            {
            }

            public function removeToken(string $tokenId): ?string
            {
                return null;
            }

            public function hasToken(string $tokenId): bool
            {
                return true;
            }

            public function clear(): void
            {
            }
        });
        $this->master = $this->usuario(['ROLE_SUPER_ADMIN']);
        $client->loginUser($this->master);
        $this->marcarTermosAceitos($client);

        return $client;
    }

    /** @param list<string> $papeis */
    private function usuario(array $papeis): User
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = (new User())->setEmail('bc_' . uniqid() . '@test.com')->setFullName('Pessoa Master')->setRoles($papeis)->setIsActive(true);
        $user->setPassword(static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($user, 'senha123'));
        $em->persist($user);
        $em->flush();

        return $user;
    }
}
