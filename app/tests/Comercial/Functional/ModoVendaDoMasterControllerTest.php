<?php

declare(strict_types=1);

namespace App\Tests\Comercial\Functional;

use App\Comercial\Campanha\ChaveDasCampanhas;
use App\Comercial\Campanha\ModelosDeCampanha;
use App\Comercial\Controller\CampanhaDoMasterController;
use App\Comercial\Controller\ComercialDoMasterController;
use App\Comercial\Repository\FatoDeConsentimentoRepository;
use App\Comercial\UseCase\CadastrarContatoDoMasterUseCase;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Shared\Email\Descadastro\DescadastroDeEmailInterface;
use App\Tests\Auth\Doubles\RateLimiterFactoryEspiao;
use App\Tests\Comercial\Support\CriaCampanhas;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

/**
 * O modo venda da Central de envios pela tela (F3h, spec comercial-e-emails §10; dc 05 VS 21.10.26 L627-645): o
 * "Cadastrar e-mail" (confirmação dupla da Base de contatos, com os limitadores da §3.3) e a recusa no servidor do
 * público, do modelo e do filtro que a tela da venda não mostra, na contagem e no envio. Só o Master; CSRF no header.
 */
#[CoversClass(ComercialDoMasterController::class)]
#[CoversClass(CampanhaDoMasterController::class)]
#[CoversClass(CadastrarContatoDoMasterUseCase::class)]
final class ModoVendaDoMasterControllerTest extends JusPrimeWebTestCase
{
    use CriaCampanhas;

    private const CONTATOS = '/master/comercial/contatos';
    private const CONTAGEM = '/master/comercial/publicos/contagem';
    private const CAMPANHAS = '/master/comercial/campanhas';

    #[TestDox('Quem não é Master não cadastra contatos: 403 e nenhum pedido de confirmação')]
    public function testSoMaster(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $tenant = (new Tenant())->setName('Escritório F3h ' . uniqid());
        $em->persist($tenant);
        $comum = $this->usuario('comum');
        $em->persist(new UserTenant($comum, $tenant));
        $em->flush();
        $this->logarComTenant($client, $comum, $tenant);
        $client->request('POST', self::CONTATOS, [], [], ['CONTENT_TYPE' => 'application/json'], '{"emails":"ana@exemplo.com"}');

        self::assertResponseStatusCodeSame(403);
        self::assertSame(0, $this->contar('comercial_consentimento'));
    }

    #[TestDox('Sem o token CSRF: 403 no cadastro de contatos e nada é pedido')]
    public function testCsrf(): void
    {
        $client = $this->clienteMaster();
        $client->request('POST', self::CONTATOS, [], [], ['CONTENT_TYPE' => 'application/json'], '{"emails":"ana@exemplo.com"}');

        self::assertResponseStatusCodeSame(403);
        self::assertSame(0, $this->contar('comercial_consentimento'));
    }

    #[TestDox('LGPD: quem se descadastrou da Base de contatos não recebe o pedido pelo cadastro do Master; a resposta conta à parte, sem o endereço')]
    public function testDescadastradoNaoRecebePedido(): void
    {
        $client = $this->clienteMaster();
        /** @var DescadastroDeEmailInterface $descadastro */
        $descadastro = static::getContainer()->get(DescadastroDeEmailInterface::class);
        $descadastro->descadastrar(null, 'saiu@exemplo.com', 'comercial_campanhas', 'link');
        // O último pedido foi há 40 dias: o limite de 30 dias da confirmação já não seguraria; a trava da F3h segura.
        /** @var FatoDeConsentimentoRepository $fatos */
        $fatos = static::getContainer()->get(FatoDeConsentimentoRepository::class);
        $fatos->registrar(null, 'pedido', 'comercial_campanhas', 'saiu@exemplo.com', ['origem' => 'pagina-publica-vagas', 'versao' => 'v1', 'texto' => 'Pedido antigo.'], null, null, new \DateTimeImmutable('-40 days'));

        $this->postar($client, self::CONTATOS, ['emails' => 'saiu@exemplo.com']);

        self::assertResponseIsSuccessful();
        $saida = $this->json($client);
        self::assertSame([0, 1], [$saida['pedidos'], $saida['descadastrados']]);
        self::assertStringNotContainsString('saiu@', $saida['mensagem']);
        self::assertSame(1, $this->contar('comercial_consentimento'), 'só o pedido antigo: nenhum pedido novo');
    }

    #[TestDox('Cota do IP estourada: 429 com o texto e nenhum pedido de confirmação')]
    public function testLimitePorIp(): void
    {
        $client = $this->clienteMaster();
        static::getContainer()->set('limiter.comercial_contatos_master', new RateLimiterFactoryEspiao(aceita: false));

        $this->postar($client, self::CONTATOS, ['emails' => 'ana@exemplo.com']);

        self::assertResponseStatusCodeSame(429);
        self::assertSame(['erro' => 'Muitos cadastros em pouco tempo. Tente de novo mais tarde.'], $this->json($client));
        self::assertSame(0, $this->contar('comercial_consentimento'));
    }

    #[TestDox('Cadastrar e-mail: cada válido recebe o pedido de confirmação da lista comercial_campanhas; o inválido é barrado; depois, o limite de 24 h barra e o confirmado "já existia"')]
    public function testCadastrarContatos(): void
    {
        $client = $this->clienteMaster();
        $ana = 'ana_' . uniqid() . '@exemplo.com';
        $bia = 'bia_' . uniqid() . '@exemplo.com';

        $this->postar($client, self::CONTATOS, ['emails' => strtoupper($ana) . ', ' . $bia . '; nao-e-email']);

        self::assertResponseIsSuccessful();
        $saida = $this->json($client);
        self::assertSame([2, 0, 1], [$saida['pedidos'], $saida['jaExistiam'], $saida['barrados']]);
        self::assertStringNotContainsString('—', (string) $saida['mensagem']);
        $fatos = $this->conexao()->fetchAllAssociative('SELECT tenant_id, fato, lista, origem, email FROM comercial_consentimento ORDER BY id');
        self::assertSame([
            ['tenant_id' => null, 'fato' => 'pedido', 'lista' => 'comercial_campanhas', 'origem' => CadastrarContatoDoMasterUseCase::ORIGEM, 'email' => $ana],
            ['tenant_id' => null, 'fato' => 'pedido', 'lista' => 'comercial_campanhas', 'origem' => CadastrarContatoDoMasterUseCase::ORIGEM, 'email' => $bia],
        ], $fatos);
        self::assertSame(2, (int) $this->conexao()->fetchOne("SELECT COUNT(*) FROM comercial_envio WHERE origem = 'consentimento:comercial_campanhas'"), 'o e-mail da confirmação vai pelo Carteiro');

        // A mesma lista de novo: o limite de 24 h da confirmação segura os dois pedidos.
        $this->postar($client, self::CONTATOS, ['emails' => $ana . ' ' . $bia]);
        self::assertSame([0, 0, 2], [$this->json($client)['pedidos'], $this->json($client)['jaExistiam'], $this->json($client)['barrados']]);

        // Ana confirmou pelo link: agora ela "já existia" e não recebe outro pedido.
        $this->conexao()->executeStatement('UPDATE comercial_confirmacao_endereco SET confirmado_em = NOW() WHERE email = ?', [$ana]);
        $this->postar($client, self::CONTATOS, ['emails' => $ana]);
        self::assertSame([0, 1, 0], [$this->json($client)['pedidos'], $this->json($client)['jaExistiam'], $this->json($client)['barrados']]);
        self::assertSame(2, $this->contar('comercial_consentimento'));
    }

    #[TestDox('Cadastrar e-mail sem nenhum endereço válido: 422 com a frase do dc')]
    public function testCadastrarSemValido(): void
    {
        $client = $this->clienteMaster();
        $this->postar($client, self::CONTATOS, ['emails' => ' , nada ']);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('Nenhum e-mail válido.', $this->json($client)['erro']);
    }

    #[TestDox('Modo venda na contagem: público, filtro e modo fora da venda são recusados (422); Individual com busca e Estado vale')]
    public function testContagemDaVenda(): void
    {
        $client = $this->clienteMaster();
        foreach ([
            [['publico' => 'prim'], 'Público inválido para a seção Indicações.'],
            [['publico' => 'todos'], 'Público inválido para a seção Indicações.'],
            [['publico' => 'leads', 'filtro' => ['gen' => 'F']], 'Na seção Indicações, o filtro aceita só a busca e o Estado.'],
            [['publico' => 'leads', 'filtro' => ['cargo' => 'adv']], 'Na seção Indicações, o filtro aceita só a busca e o Estado.'],
            [['publico' => 'leads', 'modo' => 'outro'], 'Pedido inválido.'],
        ] as [$troca, $erro]) {
            $this->postar($client, self::CONTAGEM, array_merge(['modo' => 'venda'], $troca));
            self::assertResponseStatusCodeSame(422, (string) json_encode($troca));
            self::assertSame($erro, $this->json($client)['erro']);
        }

        $ana = (string) $this->cadastradoAtivo('ana', 'Ana Venda')->getEmail();
        $this->postar($client, self::CONTAGEM, ['modo' => 'venda', 'publico' => 'indiv', 'individual' => $ana, 'filtro' => ['q' => 'ana', 'uf' => 'AL']]);
        self::assertResponseIsSuccessful();
        self::assertTrue($this->json($client)['filtro']['ativo']);
    }

    #[TestDox('Modo venda no envio: modelo, público e filtro fora da venda são recusados (422) e nada é gravado; o convite para o Individual sai')]
    public function testEnvioDaVenda(): void
    {
        $client = $this->clienteMaster();
        $ana = (string) $this->cadastradoAtivo('ana', 'Ana Venda')->getEmail();
        foreach ([
            [['modelo' => (array) (new ModelosDeCampanha())->fixo('obrigado')], 'Modelo inválido para a seção Indicações.'],
            [['modelo' => (array) (new ModelosDeCampanha())->fixo('validar')], 'Modelo inválido para a seção Indicações.'],
            [['publico' => 'sec'], 'Público inválido para a seção Indicações.'],
            [['filtro' => ['aniv' => 'mes']], 'Na seção Indicações, o filtro aceita só a busca e o Estado.'],
        ] as [$troca, $erro]) {
            $this->postar($client, self::CAMPANHAS, $this->pedido($ana, $troca));
            self::assertResponseStatusCodeSame(422, (string) json_encode(array_keys($troca)));
            self::assertSame($erro, $this->json($client)['erro']);
        }
        self::assertSame(0, $this->contar('comercial_campanha'));

        $this->postar($client, self::CAMPANHAS, $this->pedido($ana));
        self::assertResponseIsSuccessful();
        self::assertSame('convite', $this->conexao()->fetchOne('SELECT modelo_chave FROM comercial_campanha WHERE id = ?', [$this->json($client)['id']]));
    }

    /**
     * @param array<string, mixed> $troca
     *
     * @return array<string, mixed>
     */
    private function pedido(string $email, array $troca = []): array
    {
        return array_merge([
            'modo' => 'venda', 'chave' => bin2hex(random_bytes(16)), 'modelo' => (array) (new ModelosDeCampanha())->fixo('convite'),
            'publico' => 'indiv', 'individual' => $email, 'frequencia' => 'unica', 'agendar' => '',
        ], $troca);
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
        static::getContainer()->set(ChaveDasCampanhas::class, new ChaveDasCampanhas(true));
        $master = $this->usuario('master');
        $master->setRoles(['ROLE_SUPER_ADMIN']);
        static::getContainer()->get(EntityManagerInterface::class)->flush();
        $client->loginUser($master);
        $this->marcarTermosAceitos($client);

        return $client;
    }

    private function conexao(): Connection
    {
        return static::getContainer()->get(Connection::class);
    }
}
