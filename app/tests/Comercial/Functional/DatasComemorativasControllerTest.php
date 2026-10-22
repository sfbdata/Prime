<?php

declare(strict_types=1);

namespace App\Tests\Comercial\Functional;

use App\Comercial\Controller\ComercialDoMasterController;
use App\Comercial\UseCase\AlterarDataComemorativaUseCase;
use App\Comercial\UseCase\ListarDatasComemorativasUseCase;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

/**
 * As rotas das "Datas comemorativas" (spec comercial-e-emails.md §13, F4; desenho 05 VS 21.10.26 L335-339): só o
 * Master, CSRF `master_comercial` no header, 422 com `{erro}` fora das listas do dc, o caminho feliz gravando quem e
 * quando (auditado) e a prévia do "Ver mensagem" pelo modelo `data-<id>`.
 */
#[CoversClass(ComercialDoMasterController::class)]
#[CoversClass(ListarDatasComemorativasUseCase::class)]
#[CoversClass(AlterarDataComemorativaUseCase::class)]
final class DatasComemorativasControllerTest extends JusPrimeWebTestCase
{
    #[TestDox('Lista as 17 da migration (ligadas, no dia, para Todos), o "N ativas" e a chave geral desligada por padrão')]
    public function testLista(): void
    {
        $client = $this->clienteMaster();
        $client->request('GET', '/master/comercial/datas');

        self::assertResponseIsSuccessful();
        $dados = $this->json($client);
        self::assertCount(17, $dados['datas']);
        self::assertSame(17, $dados['ativas']);
        self::assertFalse($dados['chaveLigada']);
        $natal = array_column($dados['datas'], null, 'chave')['natal'];
        self::assertSame(['Natal', 'bi-gift', true, 0, 'todos'], [$natal['nome'], $natal['icone'], $natal['ligada'], $natal['antecedencia'], $natal['publico']]);
        self::assertSame(['chave', 'nome', 'icone', 'ligada', 'antecedencia', 'publico', 'data', 'diaDaSemana', 'diasQueFaltam', 'envio', 'perto'], array_keys($natal));
        $faltam = array_column($dados['datas'], 'diasQueFaltam');
        $ordenado = $faltam;
        sort($ordenado);
        self::assertSame($ordenado, $faltam, 'na ordem da próxima data');
    }

    #[TestDox('Alterar grava ligada, antecedência e público, quem e quando, na trilha; a lista reflete')]
    public function testAlterar(): void
    {
        $client = $this->clienteMaster();
        $this->postar($client, '/master/comercial/datas/natal', ['ligada' => false, 'antecedencia' => 3, 'publico' => 'leads']);

        self::assertResponseIsSuccessful();
        $linha = $this->json($client);
        self::assertSame(['natal', false, 3, 'leads'], [$linha['chave'], $linha['ligada'], $linha['antecedencia'], $linha['publico']]);
        $banco = $this->conexao()->fetchAssociative("SELECT ligada, antecedencia, publico, alterada_por_id, alterada_em FROM comercial_data_comemorativa WHERE chave = 'natal'");
        self::assertIsArray($banco);
        self::assertSame([false, 3, 'leads'], [(bool) $banco['ligada'], (int) $banco['antecedencia'], $banco['publico']]);
        self::assertNotNull($banco['alterada_por_id']);
        self::assertNotNull($banco['alterada_em']);
        self::assertGreaterThan(0, (int) $this->conexao()->fetchOne("SELECT COUNT(*) FROM audit_log WHERE entity_class LIKE '%DataComemorativa'"), 'auditado');

        $client->request('GET', '/master/comercial/datas');
        self::assertSame(16, $this->json($client)['ativas']);
    }

    #[TestDox('Fora das listas do dc: 422 com {erro}; nada muda')]
    public function testRecusas(): void
    {
        $client = $this->clienteMaster();
        foreach ([
            ['/master/comercial/datas/natal', ['ligada' => true, 'antecedencia' => 2, 'publico' => 'todos'], AlterarDataComemorativaUseCase::ANTECEDENCIA_INVALIDA],
            ['/master/comercial/datas/natal', ['ligada' => true, 'antecedencia' => 0, 'publico' => 'sec'], AlterarDataComemorativaUseCase::PUBLICO_INVALIDO],
            ['/master/comercial/datas/zzz', ['ligada' => true, 'antecedencia' => 0, 'publico' => 'todos'], AlterarDataComemorativaUseCase::DESCONHECIDA],
            ['/master/comercial/datas/natal', ['ligada' => 'sim', 'antecedencia' => 0, 'publico' => 'todos'], 'Informe se a data fica ligada, a antecedência e para quem.'],
            ['/master/comercial/datas/natal', ['ligada' => true, 'antecedencia' => '1', 'publico' => 'todos'], 'Informe se a data fica ligada, a antecedência e para quem.'],
        ] as [$url, $corpo, $erro]) {
            $this->postar($client, $url, $corpo);
            self::assertResponseStatusCodeSame(422, json_encode($corpo) ?: '');
            self::assertSame($erro, $this->json($client)['erro']);
        }
        self::assertSame(['ligada' => true, 'antecedencia' => 0, 'publico' => 'todos'], $this->estado('natal'));
    }

    #[TestDox('Sem o token CSRF: 403 e nada muda')]
    public function testCsrf(): void
    {
        $client = $this->clienteMaster();
        $client->request('POST', '/master/comercial/datas/natal', [], [], ['CONTENT_TYPE' => 'application/json'], '{"ligada":false,"antecedencia":0,"publico":"todos"}');

        self::assertResponseStatusCodeSame(403);
        self::assertTrue($this->estado('natal')['ligada']);
    }

    #[TestDox('Quem não é Master não lê nem altera: 403')]
    public function testSoMaster(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $tenant = (new Tenant())->setName('Escritório Datas ' . uniqid());
        $em->persist($tenant);
        $usuario = $this->usuario(['ROLE_USER']);
        $em->persist(new UserTenant($usuario, $tenant));
        $em->flush();
        $this->logarComTenant($client, $usuario, $tenant);

        $client->request('GET', '/master/comercial/datas');
        self::assertResponseStatusCodeSame(403);
        $client->request('POST', '/master/comercial/datas/natal', [], [], ['CONTENT_TYPE' => 'application/json'], '{"ligada":false,"antecedencia":0,"publico":"todos"}');
        self::assertResponseStatusCodeSame(403);
        self::assertTrue($this->estado('natal')['ligada']);
    }

    #[TestDox('"Ver mensagem": a prévia da Central com o modelo data-<id> da grade, o título com o nome e o botão para o app')]
    public function testPreviaDoModeloDaData(): void
    {
        $client = $this->clienteMaster();
        $client->request('GET', '/master/comercial/modelos');
        $modelo = array_column($this->json($client)['modelos'], null, 'chave')['data-advogado'] ?? null;
        self::assertIsArray($modelo);

        $this->postar($client, '/master/comercial/previa-campanha', $modelo);

        self::assertResponseIsSuccessful();
        $html = (string) $this->json($client)['html'];
        self::assertStringContainsString('Feliz Dia do Advogado, Dra. Ana!', $html);
        self::assertStringContainsString('DIA DO ADVOGADO', $html);
        self::assertStringContainsString('Abrir o BlueJus', $html);
        self::assertEmailCount(0);
    }

    /** @return array{ligada: bool, antecedencia: int, publico: string} */
    private function estado(string $chave): array
    {
        $l = $this->conexao()->fetchAssociative('SELECT ligada, antecedencia, publico FROM comercial_data_comemorativa WHERE chave = ?', [$chave]);
        self::assertIsArray($l);

        return ['ligada' => (bool) $l['ligada'], 'antecedencia' => (int) $l['antecedencia'], 'publico' => (string) $l['publico']];
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
        $storage = new class () implements ClearableTokenStorageInterface {
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
        };
        static::getContainer()->set('security.csrf.token_storage', $storage);
        $client->loginUser($this->usuario(['ROLE_SUPER_ADMIN']));
        $this->marcarTermosAceitos($client);

        return $client;
    }

    /** @param list<string> $papeis */
    private function usuario(array $papeis): User
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = (new User())->setEmail('comercial_' . uniqid() . '@test.com')->setFullName('Pessoa Comercial')->setRoles($papeis)->setIsActive(true);
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
