<?php

declare(strict_types=1);

namespace App\Tests\Comercial\Functional;

use App\Comercial\Controller\ComercialDoMasterController;
use App\Comercial\UseCase\AlterarAutomacaoUseCase;
use App\Comercial\UseCase\ListarAutomacoesUseCase;
use App\Comercial\UseCase\SimularNovoCadastroUseCase;
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
 * O bloco "Automações" do /master (spec comercial-e-emails.md §3.5): só o Master, CSRF no header, o `cad`
 * obrigatório, atraso só da lista do desenho, `ligada_em` renovado ao ligar, e o "Simular" sem enviar nada.
 */
#[CoversClass(ComercialDoMasterController::class)]
#[CoversClass(AlterarAutomacaoUseCase::class)]
#[CoversClass(ListarAutomacoesUseCase::class)]
#[CoversClass(SimularNovoCadastroUseCase::class)]
final class ComercialDoMasterControllerTest extends JusPrimeWebTestCase
{
    #[TestDox('Lista as 8 automações com o padrão do desenho (só a dica desligada) e "Nenhum disparo" vazio')]
    public function testListaPadrao(): void
    {
        $client = $this->clienteMaster();
        $client->request('GET', '/master/comercial/automacoes');

        self::assertResponseIsSuccessful();
        $dados = $this->json($client);
        self::assertCount(8, $dados['automacoes']);
        $porChave = array_column($dados['automacoes'], null, 'chave');
        self::assertFalse($porChave['dica']['ligada']);
        self::assertTrue($porChave['val']['ligada']);
        self::assertTrue($porChave['cad']['obrigatoria']);
        self::assertSame(['24h', '48h', '72h'], $porChave['lemb']['opcoes']);
        self::assertSame('24h', $porChave['lemb']['atraso']);
    }

    #[TestDox('Ligar a dica grava o corte (ligada_em) e fica na trilha; o atraso só vale entre as opções do desenho')]
    public function testLigarEAtraso(): void
    {
        $client = $this->clienteMaster();
        $this->postar($client, '/master/comercial/automacoes/dica', ['ligada' => true, 'atraso' => '15d']);
        self::assertResponseIsSuccessful();
        $resposta = $this->json($client);
        self::assertSame([true, '15d'], [$resposta['ligada'], $resposta['atraso']]);
        $linha = $this->conexao()->fetchAssociative("SELECT ligada_em, alterada_por_id FROM comercial_automacao WHERE chave = 'dica'");
        self::assertNotNull($linha['ligada_em'], 'ligar grava o corte: nada para o passado');
        self::assertNotNull($linha['alterada_por_id']);
        self::assertGreaterThan(0, (int) $this->conexao()->fetchOne("SELECT COUNT(*) FROM audit_log WHERE entity_class LIKE '%AutomacaoDeEmail'"), 'auditado');

        $this->postar($client, '/master/comercial/automacoes/lemb', ['ligada' => true, 'atraso' => '99h']);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('Escolha um dos atrasos da lista.', $this->json($client)['erro']);
    }

    #[TestDox('O cad é obrigatório: desligar responde 422 e ele segue ligado')]
    public function testCadObrigatorio(): void
    {
        $client = $this->clienteMaster();
        $this->postar($client, '/master/comercial/automacoes/cad', ['ligada' => false]);

        self::assertResponseStatusCodeSame(422);
        self::assertTrue((bool) $this->conexao()->fetchOne("SELECT ligada FROM comercial_automacao WHERE chave = 'cad'"));
    }

    #[TestDox('Sem o token CSRF: 403; chave desconhecida: 422')]
    public function testCsrfEChave(): void
    {
        $client = $this->clienteMaster();
        $client->request('POST', '/master/comercial/automacoes/dica', [], [], ['CONTENT_TYPE' => 'application/json'], '{"ligada":true}');
        self::assertResponseStatusCodeSame(403);

        $this->postar($client, '/master/comercial/automacoes/zzz', ['ligada' => true]);
        self::assertResponseStatusCodeSame(422);
    }

    #[TestDox('Quem não é Master não lê nem altera: 403')]
    public function testSoMaster(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $tenant = (new Tenant())->setName('Escritório Comercial ' . uniqid());
        $em->persist($tenant);
        $usuario = $this->usuario(['ROLE_USER']);
        $em->persist(new UserTenant($usuario, $tenant));
        $em->flush();
        $this->logarComTenant($client, $usuario, $tenant);

        $client->request('GET', '/master/comercial/automacoes');
        self::assertResponseStatusCodeSame(403);
    }

    #[TestDox('"Simular novo cadastro" grava 2 disparos simulados, mostra a mensagem do desenho, não envia nada; o destinatário vem mascarado')]
    public function testSimular(): void
    {
        $client = $this->clienteMaster();
        $this->postar($client, '/master/comercial/simular-cadastro', []);

        self::assertResponseIsSuccessful();
        self::assertSame(SimularNovoCadastroUseCase::MENSAGEM, $this->json($client)['mensagem']);
        self::assertEmailCount(0);
        self::assertSame(2, (int) $this->conexao()->fetchOne("SELECT COUNT(*) FROM comercial_envio WHERE situacao = 'simulado'"));
        self::assertSame(0, (int) $this->conexao()->fetchOne('SELECT COUNT(*) FROM comercial_envio_conteudo'), 'simulação não tem conteúdo nem mensagem na fila');

        $client->request('GET', '/master/comercial/automacoes');
        $ultimos = $this->json($client)['ultimos'];
        self::assertSame(['E-mail confirmado', 'Conta aberta'], array_column($ultimos, 'gatilho'));
        self::assertSame('n***@exemplo.com', $ultimos[0]['destinatario']);
    }

    #[TestDox('Prévia do modelo: boas-vindas com o título do desenho e o rodapé legal; avisos de espaço ainda não; nada é enviado')]
    public function testPrevia(): void
    {
        $client = $this->clienteMaster();
        $client->request('GET', '/master/comercial/automacoes/val/previa');

        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Seja bem-vindo(a), Dra.!', $html);
        self::assertStringContainsString('Comece por aqui', $html);
        self::assertStringContainsString('CNPJ 66.803.163/0001-10', $html);
        self::assertEmailCount(0);
        self::assertSame(0, (int) $this->conexao()->fetchOne('SELECT COUNT(*) FROM comercial_envio'));

        $client->request('GET', '/master/comercial/automacoes/e90/previa');
        self::assertStringContainsString('entra com os avisos de espaço', (string) $client->getResponse()->getContent());
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
