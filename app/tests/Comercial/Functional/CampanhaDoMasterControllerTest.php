<?php

declare(strict_types=1);

namespace App\Tests\Comercial\Functional;

use App\Comercial\Campanha\ComposicaoDaCampanha;
use App\Comercial\Controller\CampanhaDoMasterController;
use App\Comercial\Repository\ModeloDeCampanhaRepository;
use App\Comercial\UseCase\EnviarTesteDaCampanhaUseCase;
use App\Comercial\UseCase\ListarModelosDeCampanhaUseCase;
use App\Comercial\UseCase\PreviaDaCampanhaUseCase;
use App\Comercial\UseCase\SalvarModeloDeCampanhaUseCase;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Shared\Email\Descadastro\DescadastroDeEmailInterface;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

/**
 * O bloco "Nova campanha de e-mail" da 2a (spec comercial-e-emails.md §8.2 a §8.4, §8.6, §8.7): só o Master, CSRF no
 * header, 422 com {erro}; modelos próprios com a trilha manual sem o conteúdo; a prévia em JSON sem enviar nada; o
 * "Enviar teste" ao Master logado, com o teto de 10 por hora e o descadastro respeitado.
 */
#[CoversClass(CampanhaDoMasterController::class)]
#[CoversClass(ListarModelosDeCampanhaUseCase::class)]
#[CoversClass(PreviaDaCampanhaUseCase::class)]
#[CoversClass(EnviarTesteDaCampanhaUseCase::class)]
#[CoversClass(ComposicaoDaCampanha::class)]
#[CoversClass(ModeloDeCampanhaRepository::class)]
final class CampanhaDoMasterControllerTest extends JusPrimeWebTestCase
{
    private User $master;

    #[TestDox('Quem não é Master não lê os modelos, não vê a prévia nem manda teste: 403')]
    public function testSoMaster(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $tenant = (new Tenant())->setName('Escritório Campanha ' . uniqid());
        $em->persist($tenant);
        $usuario = $this->usuario(['ROLE_USER']);
        $em->persist(new UserTenant($usuario, $tenant));
        $em->flush();
        $this->logarComTenant($client, $usuario, $tenant);

        $client->request('GET', '/master/comercial/modelos');
        self::assertResponseStatusCodeSame(403);
        $this->postar($client, '/master/comercial/previa-campanha', $this->convite());
        self::assertResponseStatusCodeSame(403);
        $this->postar($client, '/master/comercial/teste', $this->convite());
        self::assertResponseStatusCodeSame(403);
        self::assertSame(0, $this->contar('comercial_envio'));
    }

    #[TestDox('Sem o token CSRF: 403 em criar, salvar, excluir, prévia e teste; pedido inválido: 422 com {erro}')]
    public function testCsrfE422(): void
    {
        $client = $this->clienteMaster();
        foreach (['/master/comercial/modelos', '/master/comercial/modelos/1', '/master/comercial/modelos/1/excluir', '/master/comercial/previa-campanha', '/master/comercial/teste'] as $url) {
            $client->request('POST', $url, [], [], ['CONTENT_TYPE' => 'application/json'], '{"acao":"novo"}');
            self::assertResponseStatusCodeSame(403, $url);
        }

        $this->postar($client, '/master/comercial/previa-campanha', ['modo' => 'qualquer']);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('Modo do modelo inválido.', $this->json($client)['erro']);
        $this->postar($client, '/master/comercial/modelos', ['acao' => 'apagar-tudo']);
        self::assertResponseStatusCodeSame(422);
        $this->postar($client, '/master/comercial/modelos/9999', $this->convite());
        self::assertSame('Modelo não encontrado.', $this->json($client)['erro']);
    }

    #[TestDox('A grade traz os 11 fixos do dc e os próprios depois; "Novo modelo" grava e audita só {nome, modo}')]
    public function testListaENovo(): void
    {
        $client = $this->clienteMaster();
        $this->postar($client, '/master/comercial/modelos', ['acao' => 'novo']);
        self::assertResponseIsSuccessful();
        $novo = $this->json($client);
        self::assertSame('Modelo criado. Monte os blocos e clique em Salvar modelo.', $novo['mensagem']);

        $client->request('GET', '/master/comercial/modelos');
        $modelos = $this->json($client)['modelos'];
        self::assertCount(12, $modelos);
        self::assertSame('convite', $modelos[0]['chave']);
        self::assertSame(['cu' . $novo['modelo']['id'], true], [$modelos[11]['chave'], $modelos[11]['proprio']]);
        self::assertSame(['nome' => 'Novo modelo', 'modo' => 'blocos'], $this->trilha('modelo_new', (int) $novo['modelo']['id']));
    }

    #[TestDox('Salvar: num fixo, a mensagem do dc (422); no próprio, grava; a trilha nunca leva o conteúdo')]
    public function testSalvar(): void
    {
        $client = $this->clienteMaster();
        $this->postar($client, '/master/comercial/modelos/news', $this->convite());
        self::assertResponseStatusCodeSame(422);
        self::assertSame(SalvarModeloDeCampanhaUseCase::FIXO_NAO_SE_ALTERA, $this->json($client)['erro']);

        $this->postar($client, '/master/comercial/modelos', ['acao' => 'novo']);
        $id = (int) $this->json($client)['modelo']['id'];
        $this->postar($client, '/master/comercial/modelos/' . $id, ['modo' => 'blocos', 'nome' => 'Promoção', 'assunto' => 'Oferta secreta', 'blocos' => [['t' => 'texto', 'a' => 'Conteúdo sigiloso da oferta']]]);

        self::assertResponseIsSuccessful();
        self::assertSame('Modelo salvo.', $this->json($client)['mensagem']);
        $trilha = (string) $this->conexao()->fetchOne("SELECT changes FROM audit_log WHERE action = 'modelo_upd' AND entity_id = ?", [(string) $id]);
        self::assertSame(['nome' => 'Promoção', 'modo' => 'blocos'], json_decode($trilha, true));
        self::assertStringNotContainsString('sigiloso', $trilha);
        self::assertStringNotContainsString('Oferta secreta', $trilha);
    }

    #[TestDox('Importar HTML: guardado sanitizado, com o aviso do <style>; Excluir apaga e audita')]
    public function testImportarEExcluir(): void
    {
        $client = $this->clienteMaster();
        $this->postar($client, '/master/comercial/modelos', ['acao' => 'importar', 'arquivo' => 'oferta.html', 'html' => '<head><style>p{}</style></head><p onclick="x()">Oi {nome}</p><script>alert(1)</script>']);

        self::assertResponseIsSuccessful();
        $saida = $this->json($client);
        self::assertStringEndsWith('Estilos em bloco (<style>) não são mantidos; use estilos na própria tag.', $saida['mensagem']);
        $id = (int) $saida['modelo']['id'];
        self::assertSame('<p>Oi {nome}</p>', $this->conexao()->fetchOne('SELECT conteudo FROM comercial_modelo WHERE id = ?', [$id]));

        $this->postar($client, '/master/comercial/modelos/' . $id . '/excluir', []);
        self::assertResponseIsSuccessful();
        self::assertFalse($this->conexao()->fetchOne('SELECT 1 FROM comercial_modelo WHERE id = ?', [$id]));
        self::assertSame(['nome' => 'oferta', 'modo' => 'html'], $this->trilha('modelo_del', $id));
    }

    #[TestDox('Prévia: JSON {html} com o nome de exemplo do dc, o rodapé legal e CSP sandbox; nada é enviado nem gravado')]
    public function testPrevia(): void
    {
        $client = $this->clienteMaster();
        $this->postar($client, '/master/comercial/previa-campanha', $this->convite());

        self::assertResponseIsSuccessful();
        self::assertSame('sandbox', $client->getResponse()->headers->get('Content-Security-Policy'));
        $html = (string) $this->json($client)['html'];
        self::assertStringContainsString('Dra. Ana, conheça o escritório digital BlueJus', $html);
        self::assertStringContainsString('Criar minha conta grátis', $html);
        self::assertStringContainsString('Você recebeu este e-mail porque se cadastrou no BlueJus ou autorizou o envio de novidades.', $html);
        self::assertEmailCount(0);
        self::assertSame(0, $this->contar('comercial_envio'));

        $this->postar($client, '/master/comercial/previa-campanha', ['modo' => 'html', 'assunto' => 'X', 'html' => '<p>Oi {nome}</p><script>alert(1)</script><img src="x" onerror="alert(1)">']);
        $cru = (string) $this->json($client)['html'];
        self::assertStringContainsString('<p>Oi Dra. Ana</p>', $cru);
        self::assertStringNotContainsString('<script', $cru);
        self::assertStringNotContainsString('onerror', $cru);
    }

    #[TestDox('Salvar como meu modelo (dc 21.10 L653): a cópia do fixo com o nome dado e no mesmo modo; sem nome, 422; o fixo continua')]
    public function testSalvarComoMeuModelo(): void
    {
        $client = $this->clienteMaster();
        $convite = $this->convite();
        $this->postar($client, '/master/comercial/modelos', ['acao' => 'salvar_como', 'modelo' => $convite, 'nome' => '  Convite   do Ricardo  ']);

        self::assertResponseIsSuccessful();
        $saida = $this->json($client);
        self::assertSame('Modelo "Convite do Ricardo" salvo em Meus modelos.', $saida['mensagem']);
        $id = (int) $saida['modelo']['id'];
        $linha = $this->conexao()->fetchAssociative('SELECT nome, modo, assunto FROM comercial_modelo WHERE id = ?', [$id]);
        self::assertSame(['Convite do Ricardo', $convite['modo'], $convite['assunto']], [$linha['nome'] ?? null, $linha['modo'] ?? null, $linha['assunto'] ?? null], 'o mesmo modo do fixo (o Duplicar converte em blocos)');
        self::assertSame(['nome' => 'Convite do Ricardo', 'modo' => $convite['modo']], $this->trilha('modelo_new', $id));

        $this->postar($client, '/master/comercial/modelos', ['acao' => 'salvar_como', 'modelo' => $convite, 'nome' => '   ']);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('Dê um nome ao seu modelo.', $this->json($client)['erro']);
    }

    #[TestDox('Prévia com o "Nome na prévia" (dc 21.10 L270): o nome digitado troca o {nome} como foi escrito, só na prévia')]
    public function testPreviaComNomeDigitado(): void
    {
        $client = $this->clienteMaster();
        $this->postar($client, '/master/comercial/previa-campanha', ['modo' => 'html', 'assunto' => 'X', 'html' => '<p>Oi {nome}</p>', 'nomeDaPrevia' => 'Dr. Paulo Lima']);

        self::assertStringContainsString('<p>Oi Dr. Paulo Lima</p>', (string) $this->json($client)['html']);
    }

    #[TestDox('Enviar teste: vai ao Master logado, lista comercial_campanhas, origem teste_campanha, layout do modo')]
    public function testEnviarTeste(): void
    {
        $client = $this->clienteMaster();
        $this->postar($client, '/master/comercial/teste', $this->convite());

        self::assertResponseIsSuccessful();
        self::assertSame('Teste enviado para ' . $this->master->getEmail() . '.', $this->json($client)['mensagem']);
        $envio = $this->conexao()->fetchAssociative(
            'SELECT e.destinatario, e.tipo, e.lista, e.origem, e.situacao, c.layout FROM comercial_envio e JOIN comercial_envio_conteudo c ON c.envio_id = e.id',
        );
        self::assertSame([
            'destinatario' => $this->master->getEmail(), 'tipo' => 'lista', 'lista' => 'comercial_campanhas',
            'origem' => 'teste_campanha', 'situacao' => 'enfileirado', 'layout' => 'linhas',
        ], $envio);
        self::assertSame(1, $this->contar('comercial_envio'), 'só uma pessoa');
    }

    #[TestDox('Enviar teste: o 11º na mesma hora é recusado com a mensagem da spec')]
    public function testTetoPorHora(): void
    {
        $client = $this->clienteMaster();
        for ($i = 0; $i < 10; ++$i) {
            $this->conexao()->executeStatement(
                "INSERT INTO comercial_envio (token, destinatario, assunto, tipo, lista, origem, situacao, criado_em, aberturas, cliques)
                 VALUES (:token, :para, 'x', 'lista', 'comercial_campanhas', 'teste_campanha', 'enviado', :em, 0, 0)",
                ['token' => bin2hex(random_bytes(16)), 'para' => $this->master->getEmail(), 'em' => (new \DateTimeImmutable('-10 minutes'))->format('Y-m-d H:i:s')],
            );
        }
        $this->postar($client, '/master/comercial/teste', $this->convite());

        self::assertResponseStatusCodeSame(422);
        self::assertSame('Limite de 10 testes por hora atingido.', $this->json($client)['erro']);
        self::assertSame(10, $this->contar('comercial_envio'));
    }

    #[TestDox('Enviar teste: descadastrado da lista de campanhas não recebe e a tela diz por quê')]
    public function testDescadastrado(): void
    {
        $client = $this->clienteMaster();
        static::getContainer()->get(DescadastroDeEmailInterface::class)->descadastrar(null, (string) $this->master->getEmail(), ComposicaoDaCampanha::LISTA, 'teste');
        $this->postar($client, '/master/comercial/teste', $this->convite());

        self::assertResponseStatusCodeSame(422);
        self::assertSame('Você está descadastrado da lista de campanhas; o teste não foi enviado.', $this->json($client)['erro']);
        self::assertSame('bloqueado_descadastro', $this->conexao()->fetchOne('SELECT situacao FROM comercial_envio'));
        self::assertSame(0, $this->contar('comercial_envio_conteudo'));
    }

    /** @return array<string, mixed> o fixo "convite" como a tela manda */
    private function convite(): array
    {
        $fixos = new \App\Comercial\Campanha\ModelosDeCampanha();

        return (array) $fixos->fixo('convite');
    }

    /** @return array<string, mixed> */
    private function trilha(string $acao, int $id): array
    {
        $linha = $this->conexao()->fetchAssociative('SELECT entity_class, changes FROM audit_log WHERE action = ? AND entity_id = ?', [$acao, (string) $id]);
        self::assertIsArray($linha, 'a linha da trilha existe');
        self::assertSame(\App\Comercial\Entity\ModeloDeCampanha::class, $linha['entity_class']);

        return (array) json_decode((string) $linha['changes'], true);
    }

    private function contar(string $tabela): int
    {
        return (int) $this->conexao()->fetchOne('SELECT COUNT(*) FROM ' . $tabela);
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
        $this->master = $this->usuario(['ROLE_SUPER_ADMIN']);
        $client->loginUser($this->master);
        $this->marcarTermosAceitos($client);

        return $client;
    }

    /** @param list<string> $papeis */
    private function usuario(array $papeis): User
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = (new User())->setEmail('campanha_' . uniqid() . '@test.com')->setFullName('Pessoa Master')->setRoles($papeis)->setIsActive(true);
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
