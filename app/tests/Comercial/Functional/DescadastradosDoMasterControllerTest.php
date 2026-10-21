<?php

declare(strict_types=1);

namespace App\Tests\Comercial\Functional;

use App\Comercial\Controller\DescadastradosDoMasterController;
use App\Comercial\UseCase\ExportarDescadastradosDoComercialUseCase;
use App\Comercial\UseCase\ListarDescadastradosDoComercialUseCase;
use App\Comercial\UseCase\ReativarDescadastradoDoComercialUseCase;
use App\Comercial\UseCase\RegistrarDescadastroDoComercialUseCase;
use App\Comercial\UseCase\ResumirComercialUseCase;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Shared\Email\Descadastro\DescadastroDeEmailInterface;
use App\Shared\Email\Descadastro\Repository\EmailDescadastroRepository;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

/**
 * KPIs e Descadastrados do Comercial (spec comercial-e-emails §10, itens 9 e 10; desenho 05 VS 21.10.26, L245-249,
 * L325, L690-697). Os números se medem por diferença: o banco de teste pode já ter linhas.
 */
#[CoversClass(DescadastradosDoMasterController::class)]
#[CoversClass(ResumirComercialUseCase::class)]
#[CoversClass(ListarDescadastradosDoComercialUseCase::class)]
#[CoversClass(RegistrarDescadastroDoComercialUseCase::class)]
#[CoversClass(ReativarDescadastradoDoComercialUseCase::class)]
#[CoversClass(ExportarDescadastradosDoComercialUseCase::class)]
#[CoversClass(EmailDescadastroRepository::class)]
final class DescadastradosDoMasterControllerTest extends JusPrimeWebTestCase
{
    private User $master;

    #[TestDox('Quem não é Master não lê o resumo, a lista nem o CSV, e não registra nem reativa: 403')]
    public function testSoMaster(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $tenant = (new Tenant())->setName('Escritório Dsc ' . uniqid());
        $em->persist($tenant);
        $usuario = $this->usuario(['ROLE_USER']);
        $em->persist(new UserTenant($usuario, $tenant));
        $em->flush();
        $this->logarComTenant($client, $usuario, $tenant);

        foreach (['/master/comercial/resumo', '/master/comercial/descadastrados', '/master/comercial/descadastrados.csv'] as $url) {
            $client->request('GET', $url);
            self::assertResponseStatusCodeSame(403, $url);
        }
        $this->postar($client, '/master/comercial/descadastrados', ['email' => 'a@b.com']);
        self::assertResponseStatusCodeSame(403);
        $this->postar($client, '/master/comercial/descadastrados/reativar', ['email' => 'a@b.com', 'assunto' => 'todos']);
        self::assertResponseStatusCodeSame(403);
    }

    #[TestDox('A lista traz só comercial_campanhas e todos da plataforma (sem o de outro assunto nem o de escritório), com a busca e os rótulos do desenho')]
    public function testListaSoDoComercial(): void
    {
        $client = $this->clienteMaster();
        $marca = uniqid('dsc');
        $d = $this->descadastro();
        $d->descadastrar(null, "camp.$marca@ex.com", 'comercial_campanhas', 'link_do_email', null);
        $d->descadastrar(null, "todos.$marca@ex.com", 'todos', 'one_click', 'Não quero nada');
        $d->descadastrar(null, "vagas.$marca@ex.com", 'alerta_de_vagas', 'link_do_email', null);

        $client->request('GET', '/master/comercial/descadastrados?q=' . $marca);
        self::assertResponseIsSuccessful();
        $lista = $this->json($client);
        self::assertSame(2, $lista['total']);
        $porEmail = array_column($lista['itens'], null, 'email');
        self::assertSame(["todos.$marca@ex.com", "camp.$marca@ex.com"], array_keys($porEmail), 'do mais recente para o mais antigo');
        self::assertSame(['Não informado', 'Link no e-mail', 'comercial_campanhas'], [$porEmail["camp.$marca@ex.com"]['motivo'], $porEmail["camp.$marca@ex.com"]['origem'], $porEmail["camp.$marca@ex.com"]['assunto']]);
        self::assertSame(['Não quero nada', 'Link no e-mail', 'todos'], [$porEmail["todos.$marca@ex.com"]['motivo'], $porEmail["todos.$marca@ex.com"]['origem'], $porEmail["todos.$marca@ex.com"]['assunto']]);
    }

    #[TestDox('Registrar pedido: grava em comercial_campanhas com origem manual e o motivo do desenho, e audita; e-mail inválido 422; sem o token 403')]
    public function testRegistrar(): void
    {
        $client = $this->clienteMaster();
        $email = uniqid('reg') . '@ex.com';

        $this->postar($client, '/master/comercial/descadastrados', ['email' => '  ' . strtoupper($email) . ' ']);
        self::assertResponseIsSuccessful();
        self::assertTrue($this->descadastro()->estaDescadastrado(null, $email, 'comercial_campanhas'));
        $linha = $this->conexao()->fetchAssociative('SELECT origem, motivo FROM email_descadastro WHERE email = ? AND tenant_id IS NULL AND reativado_em IS NULL', [$email]);
        self::assertSame(['origem' => 'manual_master', 'motivo' => 'Pedido registrado pelo Master'], $linha);
        self::assertSame(1, (int) $this->conexao()->fetchOne("SELECT count(*) FROM audit_log WHERE action = 'dsc_reg' AND entity_id = ? AND actor_user_id = ?", [$email, $this->master->getId()]));

        $this->postar($client, '/master/comercial/descadastrados', ['email' => 'sem-arroba']);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(['erro' => 'Informe um e-mail válido.'], $this->json($client));

        $client->request('POST', '/master/comercial/descadastrados', [], [], ['CONTENT_TYPE' => 'application/json'], '{"email":"x@y.com"}');
        self::assertResponseStatusCodeSame(403);
    }

    #[TestDox('Excluir (reativar): um por vez, volta a poder receber e audita; assunto fora da lista do Comercial 422')]
    public function testReativar(): void
    {
        $client = $this->clienteMaster();
        $email = uniqid('rea') . '@ex.com';
        $this->descadastro()->descadastrar(null, $email, 'comercial_campanhas', 'link_do_email', null);

        $this->postar($client, '/master/comercial/descadastrados/reativar', ['email' => $email, 'assunto' => 'alerta_de_vagas']);
        self::assertResponseStatusCodeSame(422);
        self::assertTrue($this->descadastro()->estaDescadastrado(null, $email, 'comercial_campanhas'));

        $this->postar($client, '/master/comercial/descadastrados/reativar', ['email' => $email, 'assunto' => 'comercial_campanhas']);
        self::assertResponseIsSuccessful();
        self::assertFalse($this->descadastro()->estaDescadastrado(null, $email, 'comercial_campanhas'));
        self::assertSame(1, (int) $this->conexao()->fetchOne("SELECT count(*) FROM audit_log WHERE action = 'dsc_reat' AND entity_id = ?", [$email]));
    }

    #[TestDox('Resumo: descadastrados e base mudam com a lista; a base tira quem pediu para não receber (inclusive pelo todos)')]
    public function testResumo(): void
    {
        $client = $this->clienteMaster();
        $marca = uniqid('res');
        $client->request('GET', '/master/comercial/resumo');
        self::assertResponseIsSuccessful();
        $antes = $this->json($client);
        self::assertSame(['campanhas', 'naFila', 'base', 'descadastrados'], array_keys($antes));

        foreach (['a', 'b', 'c'] as $l) {
            $this->conexao()->insert('comercial_confirmacao_endereco', ['lista' => 'comercial_campanhas', 'email' => "$l.$marca@ex.com", 'pedido_em' => '2026-10-01 10:00:00', 'confirmado_em' => '2026-10-01 10:05:00', 'tenant_id' => null]);
        }
        $this->descadastro()->descadastrar(null, "b.$marca@ex.com", 'todos', 'link_do_email', null);

        $client->request('GET', '/master/comercial/resumo');
        $depois = $this->json($client);
        self::assertSame($antes['base'] + 2, $depois['base'], 'três confirmados, um descadastrado pelo todos');
        self::assertSame($antes['descadastrados'] + 1, $depois['descadastrados']);
    }

    #[TestDox('CSV: anexo descadastrados-bluejus.csv, com BOM, cabeçalho do desenho, ";" e a busca da tela')]
    public function testCsv(): void
    {
        $client = $this->clienteMaster();
        $marca = uniqid('csv');
        $this->descadastro()->descadastrar(null, "x.$marca@ex.com", 'comercial_campanhas', 'manual_master', '=HYPERLINK("x")');

        $client->request('GET', '/master/comercial/descadastrados.csv?q=' . $marca);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('attachment; filename="descadastrados-bluejus.csv"', (string) $client->getResponse()->headers->get('Content-Disposition'));
        $linhas = explode("\n", (string) $client->getResponse()->getContent());
        self::assertSame("\u{FEFF}\"E-mail\";\"Data\";\"Motivo\";\"Origem\"", $linhas[0]);
        self::assertCount(2, $linhas);
        self::assertStringStartsWith("\"x.$marca@ex.com\";\"", $linhas[1]);
        self::assertStringEndsWith(";\"'=HYPERLINK(\"\"x\"\")\";\"Manual\"", $linhas[1], 'o motivo não vira fórmula na planilha');
    }

    private function descadastro(): DescadastroDeEmailInterface
    {
        return static::getContainer()->get(DescadastroDeEmailInterface::class);
    }

    /** @param array<string, mixed> $corpo */
    private function postar(KernelBrowser $client, string $url, array $corpo): void
    {
        $client->request('POST', $url, [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => 'TOKEN_master_comercial'], (string) json_encode($corpo));
    }

    /** @return array<string, mixed> */
    private function json(KernelBrowser $client): array
    {
        return (array) json_decode((string) $client->getResponse()->getContent(), true);
    }

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
        $user = (new User())->setEmail('dsc_' . uniqid() . '@test.com')->setFullName('Pessoa Master')->setRoles($papeis)->setIsActive(true);
        $user->setPassword(static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($user, 'senha123'));
        $em->persist($user);
        $em->flush();

        return $user;
    }

    private function conexao(): Connection
    {
        return static::getContainer()->get(Connection::class);
    }
}
