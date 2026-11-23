<?php

declare(strict_types=1);

namespace App\Tests\Produto\Functional;

use App\Auth\Controller\CadastroController;
use App\Auth\Controller\CadastroPeloFacebookController;
use App\Auth\Controller\CadastroPeloGoogleController;
use App\Auth\Infra\FacebookLoginApi;
use App\Auth\Infra\GoogleLoginApi;
use App\Auth\Service\CadastroPeloFacebook;
use App\Auth\Service\ProdutoDoCadastro;
use App\Auth\UseCase\CriarContaEEscritorioUseCase;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Produto\Service\ContratacaoDoCadastro;
use App\Tests\Auth\FacebookLoginFalso;
use App\Tests\Auth\GoogleLoginFalso;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Cadastro pelo Voto (spec docs/specs/multiproduto-contratacao-e-voto.md, §5 e D9): o `?produto=voto` fica na sessão,
 * por lista fechada; os POST de criação leem o produto SÓ dela; o escritório nasce com a contratação do Voto, a
 * liberação do Voto e sem os marcadores; sem produto, nasce como hoje.
 */
#[CoversClass(CadastroController::class)]
#[CoversClass(CadastroPeloGoogleController::class)]
#[CoversClass(CadastroPeloFacebookController::class)]
#[CoversClass(ProdutoDoCadastro::class)]
#[CoversClass(CriarContaEEscritorioUseCase::class)]
#[CoversClass(ContratacaoDoCadastro::class)]
final class CadastroPeloVotoTest extends JusPrimeWebTestCase
{
    private GoogleLoginFalso $google;

    #[TestDox('GET /cadastro?produto=voto guarda o Voto na sessão e segue para o "Criar conta" como hoje')]
    public function testGuardaNaSessao(): void
    {
        $client = $this->cliente();

        $client->request('GET', '/cadastro?produto=voto');

        self::assertResponseRedirects('/login?abrir=cadastro');
        self::assertSame('voto', $this->naSessao($client));
    }

    #[TestDox('Valor fora da lista, vazio, em lista ou ausente é ignorado e não apaga o Voto já guardado')]
    public function testValorForaDaListaNaoApaga(): void
    {
        $client = $this->cliente();
        $client->request('GET', '/cadastro?produto=ia');
        self::assertNull($this->naSessao($client), 'a I.A. não entra pela URL');

        $client->request('GET', '/cadastro?produto=voto');
        foreach (['/cadastro?produto=expediente', '/cadastro?produto[]=voto', '/cadastro?produto=', '/cadastro', '/cadastro?produto=VOTO'] as $url) {
            $client->request('GET', $url);
            self::assertResponseRedirects('/login?abrir=cadastro', message: $url);
            self::assertSame('voto', $this->naSessao($client), $url);
        }
    }

    #[TestDox('POST /cadastro/criar com o Voto na sessão: contratação do Voto (cadastro), Voto liberado, sem marcadores e sem I.A.; a chave sai da sessão')]
    public function testCriaPeloVoto(): void
    {
        $client = $this->cliente();
        $client->request('GET', '/cadastro?produto=voto');
        $email = 'voto_' . uniqid() . '@adv.com';

        $client->jsonRequest('POST', '/cadastro/criar', $this->campos($client) + ['email' => $email]);

        self::assertResponseIsSuccessful();
        [$user, $tenant] = $this->contaCriada($email);
        self::assertSame([['produto' => 'voto', 'situacao' => 'ativa', 'origem' => 'cadastro', 'alterada_por_id' => $user->getId()]], $this->contratacoes($tenant));
        self::assertTrue($this->votoLiberado($tenant));
        self::assertSame(0, $this->marcadores($tenant));
        self::assertNull($this->naSessao($client), 'o produto vale para um cadastro só');
    }

    #[TestDox('Sem o Voto na sessão, o "produto" do JSON é ignorado (D9): o escritório nasce completo, com os marcadores, sem contratação nem liberação')]
    public function testProdutoDoJsonEIgnorado(): void
    {
        $client = $this->cliente();
        $email = 'completo_' . uniqid() . '@adv.com';

        $client->jsonRequest('POST', '/cadastro/criar', $this->campos($client) + ['email' => $email, 'produto' => 'voto']);

        self::assertResponseIsSuccessful();
        [, $tenant] = $this->contaCriada($email);
        self::assertSame([], $this->contratacoes($tenant));
        self::assertFalse($this->votoLiberado($tenant));
        self::assertSame(13, $this->marcadores($tenant), 'os 13 marcadores padrão, como hoje');
    }

    #[TestDox('E-mail que já tem conta, com o Voto na sessão: a mesma resposta neutra do cadastro novo, nada gravado na conta existente, e a chave sai da sessão')]
    public function testRespostaNeutraComVoto(): void
    {
        $client = $this->cliente();
        $email = 'existente_' . uniqid() . '@adv.com';
        $client->jsonRequest('POST', '/cadastro/criar', $this->campos($client) + ['email' => $email]);
        self::assertResponseIsSuccessful();
        $respostaNova = (string) $client->getResponse()->getContent();
        [, $tenant] = $this->contaCriada($email);

        $client->request('GET', '/cadastro?produto=voto');
        $client->jsonRequest('POST', '/cadastro/criar', $this->campos($client) + ['email' => $email]);

        self::assertResponseIsSuccessful();
        self::assertSame($respostaNova, (string) $client->getResponse()->getContent(), 'a resposta não revela que o e-mail já tem conta');
        self::assertSame([], $this->contratacoes($tenant), 'a conta existente não ganha o Voto');
        self::assertFalse($this->votoLiberado($tenant));
        self::assertNull($this->naSessao($client));
    }

    #[TestDox('Recusa de validação mantém o Voto na sessão, para a pessoa corrigir e tentar de novo')]
    public function testErroMantemOProduto(): void
    {
        $client = $this->cliente();
        $client->request('GET', '/cadastro?produto=voto');

        $client->jsonRequest('POST', '/cadastro/criar', $this->campos($client) + ['email' => 'x', 'nome' => 'Ana']);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('voto', $this->naSessao($client));
    }

    #[TestDox('Pelo Google com o Voto na sessão: contratação e liberação do Voto, e o login leva ao Voto (voto_index)')]
    public function testGoogleComVoto(): void
    {
        $client = $this->cliente();
        $client->request('GET', '/cadastro?produto=voto');
        $this->conectarGoogle($client);

        $client->jsonRequest('POST', '/cadastro/google/criar', $this->campos($client));

        self::assertResponseIsSuccessful();
        self::assertSame('/login/entrando?para=/voto', $this->destino($client));
        [$user, $tenant] = $this->contaCriada($this->google->email);
        self::assertSame([['produto' => 'voto', 'situacao' => 'ativa', 'origem' => 'cadastro', 'alterada_por_id' => $user->getId()]], $this->contratacoes($tenant));
        self::assertTrue($this->votoLiberado($tenant));
        self::assertSame(0, $this->marcadores($tenant));
        self::assertNull($this->naSessao($client));

        // O véu aceita o Voto como destino (DestinoPosLogin::PAGINAS).
        $crawler = $client->request('GET', $this->destino($client));
        self::assertSame('"\/voto"', $crawler->filter('[data-destino]')->attr('data-destino'));
    }

    #[TestDox('Pelo Google sem produto: escritório completo e o login segue para o Expediente, como hoje')]
    public function testGoogleSemProduto(): void
    {
        $client = $this->cliente();
        $this->conectarGoogle($client);

        $client->jsonRequest('POST', '/cadastro/google/criar', $this->campos($client) + ['produto' => 'voto']);

        self::assertResponseIsSuccessful();
        self::assertSame('/login/entrando?para=/expediente', $this->destino($client));
        [, $tenant] = $this->contaCriada($this->google->email);
        self::assertSame([], $this->contratacoes($tenant));
        self::assertSame(13, $this->marcadores($tenant));
    }

    #[TestDox('Pelo Facebook com o Voto na sessão: contratação e liberação do Voto, sem marcadores; a chave sai da sessão')]
    public function testFacebookComVoto(): void
    {
        $client = $this->cliente();
        $client->request('GET', '/cadastro?produto=voto');
        $sessao = $client->getRequest()->getSession();
        $sessao->set(CadastroPeloFacebook::CHAVE_SESSAO, ['id' => 'fb-voto-' . uniqid(), 'nome' => 'Ana Voto', 'email' => null, 'em' => time()]);
        $sessao->save();
        $email = 'fb_voto_' . uniqid() . '@adv.com';

        $client->jsonRequest('POST', '/cadastro/facebook/criar', $this->campos($client) + ['email' => $email]);

        self::assertResponseIsSuccessful();
        [$user, $tenant] = $this->contaCriada($email);
        self::assertSame([['produto' => 'voto', 'situacao' => 'ativa', 'origem' => 'cadastro', 'alterada_por_id' => $user->getId()]], $this->contratacoes($tenant));
        self::assertTrue($this->votoLiberado($tenant));
        self::assertSame(0, $this->marcadores($tenant));
        self::assertNull($this->naSessao($client));
    }

    #[TestDox('Pelo Facebook sem o Voto na sessão, o "produto" do JSON é ignorado (D9)')]
    public function testFacebookProdutoDoJsonEIgnorado(): void
    {
        $client = $this->cliente();
        $client->request('GET', '/login');
        $sessao = $client->getRequest()->getSession();
        $sessao->set(CadastroPeloFacebook::CHAVE_SESSAO, ['id' => 'fb-json-' . uniqid(), 'nome' => 'Ana Json', 'email' => null, 'em' => time()]);
        $sessao->save();
        $email = 'fb_json_' . uniqid() . '@adv.com';

        $client->jsonRequest('POST', '/cadastro/facebook/criar', $this->campos($client) + ['email' => $email, 'produto' => 'voto']);

        self::assertResponseIsSuccessful();
        [, $tenant] = $this->contaCriada($email);
        self::assertSame([], $this->contratacoes($tenant));
        self::assertFalse($this->votoLiberado($tenant));
        self::assertSame(13, $this->marcadores($tenant));
    }

    private function naSessao(KernelBrowser $client): mixed
    {
        return $client->getRequest()->getSession()->get(ProdutoDoCadastro::CHAVE_SESSAO);
    }

    /** @return array<string, mixed> */
    private function campos(KernelBrowser $client): array
    {
        return ['nome' => 'Ana Voto Souza', 'escritorio' => 'Condomínio ' . uniqid(), 'genero' => 'feminino', 'aceite' => true, '_token' => $this->token($client)];
    }

    private function token(KernelBrowser $client): string
    {
        return (string) $client->request('GET', '/login')->filter('#lgRaiz')->attr('data-token-cadastro');
    }

    private function destino(KernelBrowser $client): string
    {
        $json = json_decode((string) $client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);

        return (string) $json['destino'];
    }

    /** @return array{User, Tenant} */
    private function contaCriada(string $email): array
    {
        $user = $this->em()->getRepository(User::class)->findOneBy(['email' => $email]);
        self::assertInstanceOf(User::class, $user);
        $vinculo = $this->em()->getRepository(UserTenant::class)->findOneBy(['user' => $user]);
        self::assertInstanceOf(UserTenant::class, $vinculo);

        return [$user, $vinculo->getTenant()];
    }

    /** @return list<array<string, mixed>> */
    private function contratacoes(Tenant $tenant): array
    {
        return $this->conexao()->fetchAllAssociative('SELECT produto, situacao, origem, alterada_por_id FROM contratacao WHERE tenant_id = ? ORDER BY id', [$tenant->getId()]);
    }

    private function votoLiberado(Tenant $tenant): bool
    {
        return (bool) $this->conexao()->fetchOne('SELECT liberado FROM voto.liberacao WHERE tenant_id = ?', [$tenant->getId()]);
    }

    private function marcadores(Tenant $tenant): int
    {
        return (int) $this->conexao()->fetchOne('SELECT COUNT(*) FROM marcador WHERE tenant_id = ?', [$tenant->getId()]);
    }

    /** Volta do Google com um e-mail sem conta: a identidade fica na sessão (molde: CadastroPeloGoogleTest). */
    private function conectarGoogle(KernelBrowser $client): void
    {
        $client->request('GET', '/login/google');
        parse_str((string) parse_url((string) $client->getResponse()->headers->get('Location'), \PHP_URL_QUERY), $q);
        $this->google->nonce = $q['nonce'];
        $client->request('GET', '/login/google/callback?code=abc&state=' . $q['state']);
        $this->google->nonce = null;
        self::assertResponseRedirects('/login?abrir=cadastro');
    }

    private function cliente(): KernelBrowser
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->google = new GoogleLoginFalso();
        $this->google->email = 'voto_' . uniqid() . '@gmail.com';
        $this->google->sub = 'sub-voto-' . uniqid();
        static::getContainer()->set(GoogleLoginApi::class, $this->google->api());
        static::getContainer()->set(FacebookLoginApi::class, (new FacebookLoginFalso())->api());

        return $client;
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    private function conexao(): Connection
    {
        return static::getContainer()->get(Connection::class);
    }
}
