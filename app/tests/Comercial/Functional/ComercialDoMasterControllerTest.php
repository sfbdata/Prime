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
    #[TestDox('Lista as 14 automações (as 8 do desenho e os dias 2 a 7 da ativação; a dica e a ativação desligadas) e "Nenhum disparo" vazio')]
    public function testListaPadrao(): void
    {
        $client = $this->clienteMaster();
        $client->request('GET', '/master/comercial/automacoes');

        self::assertResponseIsSuccessful();
        $dados = $this->json($client);
        self::assertCount(14, $dados['automacoes']);
        $porChave = array_column($dados['automacoes'], null, 'chave');
        self::assertFalse($porChave['dica']['ligada']);
        self::assertFalse($porChave['at2']['ligada']);
        self::assertSame('Ativação, dia 6: oferta de ajuda', $porChave['at6']['titulo']);
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

    #[TestDox('B3: "Simular novo cadastro" envia a confirmação na hora e põe as boas-vindas na fila, as duas com "[TESTE]", sem criar conta; aparecem mascaradas em Últimos disparos')]
    public function testSimular(): void
    {
        $client = $this->clienteMaster();
        $usuarios = (int) $this->conexao()->fetchOne('SELECT COUNT(*) FROM "user"');
        $this->postar($client, '/master/comercial/simular-cadastro', ['email' => ' Novo.Cliente@Exemplo.com ']);

        self::assertResponseIsSuccessful();
        self::assertSame(sprintf(SimularNovoCadastroUseCase::MENSAGEM, 'novo.cliente@exemplo.com'), $this->json($client)['mensagem']);
        self::assertEmailCount(1);
        $confirmacao = self::getMailerMessage();
        self::assertNotNull($confirmacao);
        self::assertEmailAddressContains($confirmacao, 'To', 'novo.cliente@exemplo.com');
        self::assertEmailHeaderSame($confirmacao, 'Subject', '[BlueJus] [TESTE] Confirme seu e-mail no BlueJus');
        self::assertEmailHtmlBodyContains($confirmacao, 'Colega');
        $linhas = $this->conexao()->fetchAllKeyValue("SELECT origem, situacao FROM comercial_envio WHERE destinatario = 'novo.cliente@exemplo.com' ORDER BY origem");
        self::assertSame(['simulacao:cad' => 'enviado', 'simulacao:val' => 'enfileirado'], $linhas);
        self::assertStringStartsWith('[TESTE] ', (string) $this->conexao()->fetchOne("SELECT assunto FROM comercial_envio WHERE origem = 'simulacao:val'"));
        self::assertSame($usuarios, (int) $this->conexao()->fetchOne('SELECT COUNT(*) FROM "user"'), 'nenhuma conta criada');
        self::assertSame(0, (int) $this->conexao()->fetchOne("SELECT COUNT(*) FROM comercial_envio WHERE origem LIKE 'automacao:%'"), 'fora dos números das automações');

        $client->request('GET', '/master/comercial/automacoes');
        $ultimos = $this->json($client)['ultimos'];
        self::assertSame(['E-mail confirmado', 'Conta aberta'], array_column(\array_slice($ultimos, 0, 2), 'gatilho'));
        self::assertSame('n***@exemplo.com', $ultimos[0]['destinatario']);

        // Para o endereço do próprio Master, o "Olá" leva o nome dele.
        $doMaster = (string) $this->conexao()->fetchOne("SELECT email FROM \"user\" WHERE email LIKE 'comercial_%' ORDER BY id DESC LIMIT 1");
        $this->postar($client, '/master/comercial/simular-cadastro', ['email' => $doMaster]);
        self::assertResponseIsSuccessful();
        $paraOMaster = self::getMailerMessage();
        self::assertNotNull($paraOMaster);
        self::assertEmailHtmlBodyContains($paraOMaster, 'Pessoa Comercial');
    }

    #[TestDox('B3: com as boas-vindas desligadas, sai só a confirmação; endereço inválido e o teto de 30 por hora respondem 422 sem enviar')]
    public function testSimularRecusasEBoasVindasDesligadas(): void
    {
        $client = $this->clienteMaster();
        $this->postar($client, '/master/comercial/automacoes/val', ['ligada' => false]);
        self::assertResponseIsSuccessful();
        $this->postar($client, '/master/comercial/simular-cadastro', ['email' => 'pessoa@exemplo.com']);
        self::assertResponseIsSuccessful();
        self::assertSame(sprintf(SimularNovoCadastroUseCase::MENSAGEM_SEM_BOAS_VINDAS, 'pessoa@exemplo.com'), $this->json($client)['mensagem']);
        self::assertSame(0, (int) $this->conexao()->fetchOne("SELECT COUNT(*) FROM comercial_envio WHERE origem = 'simulacao:val'"));

        $this->postar($client, '/master/comercial/simular-cadastro', ['email' => 'nao-e-email']);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(SimularNovoCadastroUseCase::ENDERECO_INVALIDO, $this->json($client)['erro']);

        for ($i = 1; $i < SimularNovoCadastroUseCase::TETO_POR_HORA; ++$i) {
            $this->conexao()->insert('comercial_envio', [
                'token' => bin2hex(random_bytes(16)), 'destinatario' => 'p' . $i . '@exemplo.com', 'assunto' => 'x', 'tipo' => 'transacional',
                'origem' => SimularNovoCadastroUseCase::ORIGEM_CONFIRMACAO, 'situacao' => 'enviado', 'criado_em' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                'aberturas' => 0, 'cliques' => 0,
            ]);
        }
        $this->postar($client, '/master/comercial/simular-cadastro', ['email' => 'pessoa@exemplo.com']);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(SimularNovoCadastroUseCase::TETO_ATINGIDO, $this->json($client)['erro']);
        self::assertEmailCount(0);
    }

    #[TestDox('Prévia do modelo: boas-vindas com o título do desenho e o rodapé legal; avisos de espaço com o limite escolhido; nada é enviado')]
    public function testPrevia(): void
    {
        $client = $this->clienteMaster();
        $client->request('GET', '/master/comercial/automacoes/val/previa');

        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Seja bem-vindo(a), Dra.!', $html);
        self::assertStringContainsString('Sua primeira pasta, passo a passo', $html);
        self::assertStringNotContainsString('Assistir ao vídeo', $html, 'sem o vídeo, sem o botão');
        self::assertStringContainsString('CNPJ 66.803.163/0001-10', $html);
        self::assertEmailCount(0);
        self::assertSame(0, (int) $this->conexao()->fetchOne('SELECT COUNT(*) FROM comercial_envio'));

        $client->request('GET', '/master/comercial/automacoes/e90/previa');
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Dra., sua conta chegou a 90% do espaço', $html, 'o padrão do dc');
        self::assertStringContainsString('está com 90% ocupado', $html);
        self::assertStringContainsString('/contribua#espaco', $html);

        $this->conexao()->executeStatement("UPDATE comercial_automacao SET atraso = '80' WHERE chave = 'e90'");
        $client->request('GET', '/master/comercial/automacoes/e90/previa');
        self::assertStringContainsString('Dra., sua conta chegou a 80% do espaço', (string) $client->getResponse()->getContent(), 'o limite escolhido, não o 90% fixo');

        $client->request('GET', '/master/comercial/automacoes/e100/previa');
        self::assertStringContainsString('Dra., o espaço da sua conta acabou', (string) $client->getResponse()->getContent());
        $client->request('GET', '/master/comercial/automacoes/ped/previa');
        self::assertStringContainsString('Obrigado, Dra.! Já estamos cuidando disso', (string) $client->getResponse()->getContent());
        self::assertEmailCount(0);
        self::assertSame(0, (int) $this->conexao()->fetchOne('SELECT COUNT(*) FROM comercial_envio'));

        $client->request('GET', '/master/comercial/automacoes/at7/previa');
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Dra., obrigado pela primeira semana', $html);
        self::assertStringContainsString('/contribua#sug', $html, 'o dia 7 leva às sugestões');
        self::assertEmailCount(0);

        $client->request('GET', '/master/comercial/automacoes/xyz/previa');
        self::assertResponseStatusCodeSame(404);
    }

    #[TestDox('M5.8: com o padrão da Central editado, a prévia da automação mostra a versão do Master')]
    public function testPreviaComVersaoEditada(): void
    {
        $client = $this->clienteMaster();
        $this->conexao()->executeStatement(
            "INSERT INTO comercial_modelo (nome, icone, modo, assunto, selo, titulo, conteudo, fixo_chave, criado_em, alterado_em)
             VALUES ('Boas-vindas', 'bi-stars', 'blocos', 'Assunto do Master', 'OLÁ', 'Oi, {nome}, versão do Master', :conteudo, 'boasvindas', NOW(), NOW())",
            ['conteudo' => json_encode([['t' => 'texto', 'a' => 'Texto que o Master escreveu']])],
        );

        $client->request('GET', '/master/comercial/automacoes/val/previa');

        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Oi, Dra., versão do Master', $html);
        self::assertStringContainsString('Texto que o Master escreveu', $html);
        self::assertStringNotContainsString('Comece por aqui', $html);
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
