<?php

declare(strict_types=1);

namespace App\Tests\Auth\Functional;

use App\Auth\Controller\RecuperacaoSenhaController;
use App\Auth\DTO\ConfirmarCodigoSenhaInput;
use App\Auth\Entity\RedefinicaoSenha;
use App\Auth\Service\ForcaDaSenha;
use App\Auth\UseCase\ConfirmarCodigoSenhaUseCase;
use App\Auth\UseCase\RedefinirSenhaUseCase;
use App\Auth\UseCase\SolicitarCodigoSenhaUseCase;
use App\Entity\Auth\User;
use App\Tests\Auth\Doubles\RateLimiterFactoryEspiao;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Mime\Email;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

/**
 * Fluxo público de recuperação de senha: senha por código do desenho 10 (JSON do modal do
 * login) e o link antigo `/senha/redefinir/{token}`, que segue valendo para pedidos já enviados.
 *
 * Grupos de asserção que valem mais que os outros:
 * - **resposta neutra** (RN02): conta existente e inexistente saem idênticas;
 * - **força bruta**: código errado conta tentativa e a quinta queima o pedido;
 * - **RS03**: trocar a senha derruba sessão viva e cookie "lembrar-me". Isso é comportamento
 *   do Symfony, não código nosso; o teste existe porque dependemos dele.
 *
 * Os POSTs em JSON rodam com `disableReboot()` e um armazenamento de CSRF previsível
 * (`TOKEN_<id>`), no mesmo padrão dos outros endpoints JSON da suíte.
 */
#[CoversClass(RecuperacaoSenhaController::class)]
final class RecuperacaoSenhaControllerTest extends JusPrimeWebTestCase
{
    private const SENHA_ORIGINAL = 'senha-original-123';
    private const SENHA_NOVA     = 'SenhaNova456';
    private const CSRF_VALIDO    = 'TOKEN_senha_codigo';
    private const ERRO_CODIGO    = 'Código incorreto. Confira o e-mail ou reenvie.';
    /** REMOTE_ADDR padrão do KernelBrowser: o IP que o controller grava no pedido. */
    private const IP_DO_CLIENTE  = '127.0.0.1';

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    private function criarUsuario(string $senha = self::SENHA_ORIGINAL, bool $ativo = true, ?string $email = null): User
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->setEmail($email ?? 'recup_' . uniqid() . '@adv.com');
        $user->setFullName('Dra. Recuperação');
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive($ativo);
        $user->setPassword($hasher->hashPassword($user, $senha));

        $this->em()->persist($user);
        $this->em()->flush();

        return $user;
    }

    /** Pedido do fluxo antigo (link): o banco guarda o SHA-256 do token. */
    private function criarPedido(User $user, string $token, string $expiraEm = '+1 hour'): RedefinicaoSenha
    {
        return $this->persistirPedido($user, RedefinicaoSenha::hashDoToken($token), $expiraEm);
    }

    /** Pedido da senha por código: o banco guarda o HMAC de "userId:codigo" com o kernel.secret. */
    private function criarPedidoDeCodigo(User $user, string $codigo, string $expiraEm = '+15 minutes', string $ip = '1.2.3.4'): RedefinicaoSenha
    {
        $segredo = (string) static::getContainer()->getParameter('kernel.secret');
        $hash    = SolicitarCodigoSenhaUseCase::hashDoCodigo((int) $user->getId(), $codigo, $segredo);

        return $this->persistirPedido($user, $hash, $expiraEm, $ip);
    }

    private function persistirPedido(User $user, string $hash, string $expiraEm, string $ip = '1.2.3.4'): RedefinicaoSenha
    {
        // Re-busca pelo id: depois de uma requisição o objeto do teste já está destacado
        // do EntityManager, e o Doctrine trataria a relação como entidade nova.
        $user = $this->em()->find(User::class, $user->getId());

        $pedido = new RedefinicaoSenha(
            user: $user,
            tokenHash: $hash,
            expiraEm: new \DateTimeImmutable($expiraEm),
            ip: $ip,
        );
        $this->em()->persist($pedido);
        $this->em()->flush();

        return $pedido;
    }

    /** Pedido relido do banco, sem cache do EntityManager. */
    private function pedidoNoBanco(int $id): ?RedefinicaoSenha
    {
        $this->em()->clear();

        return $this->em()->find(RedefinicaoSenha::class, $id);
    }

    private function senhaValeNoBanco(User $user, string $senha): bool
    {
        $this->em()->clear();
        $atualizado = $this->em()->find(User::class, $user->getId());

        return static::getContainer()->get(UserPasswordHasherInterface::class)->isPasswordValid($atualizado, $senha);
    }

    /** Token no formato que a rota do link aceita (64 hex), para não esbarrar no requirement. */
    private function token(): string
    {
        return bin2hex(random_bytes(32));
    }

    private function clienteJson(): KernelBrowser
    {
        $client = static::createClient();
        $client->disableReboot();

        $storage = new class implements ClearableTokenStorageInterface {
            public function getToken(string $tokenId): string
            {
                return 'TOKEN_' . $tokenId;
            }

            public function setToken(string $tokenId, string $token): void {}

            public function removeToken(string $tokenId): ?string
            {
                return null;
            }

            public function hasToken(string $tokenId): bool
            {
                return true;
            }

            public function clear(): void {}
        };
        static::getContainer()->set('security.csrf.token_storage', $storage);

        return $client;
    }

    /**
     * @param array<string, string> $corpo
     *
     * @return array<string, mixed> resposta decodificada
     */
    private function postar(KernelBrowser $client, string $caminho, array $corpo, string $token = self::CSRF_VALIDO): array
    {
        $client->request(
            'POST',
            $caminho,
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
            json_encode($corpo + ['_token' => $token], JSON_THROW_ON_ERROR),
        );

        return json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }

    /** @return array<string, mixed> */
    private function pedirCodigo(KernelBrowser $client, string $email, string $token = self::CSRF_VALIDO): array
    {
        return $this->postar($client, '/senha/codigo', ['email' => $email], $token);
    }

    /** @return array<string, mixed> */
    private function confirmar(KernelBrowser $client, string $email, string $codigo, string $senha = self::SENHA_NOVA, ?string $confirmacao = null): array
    {
        return $this->postar($client, '/senha/codigo/confirmar', [
            'email'       => $email,
            'codigo'      => $codigo,
            'senha'       => $senha,
            'confirmacao' => $confirmacao ?? $senha,
        ]);
    }

    /**
     * Troca os dois rate limiters por espiões.
     *
     * @return array{ip: RateLimiterFactoryEspiao, email: RateLimiterFactoryEspiao}
     */
    private function espionarLimiters(bool $aceita = true): array
    {
        // Substituir sem ler antes: obter o serviço real já o inicializa, e o container
        // de teste recusa trocar serviço inicializado.
        $ip    = new RateLimiterFactoryEspiao($aceita);
        $email = new RateLimiterFactoryEspiao();

        static::getContainer()->set('limiter.senha_esqueci_ip', $ip);
        static::getContainer()->set('limiter.senha_esqueci_email', $email);

        return ['ip' => $ip, 'email' => $email];
    }

    // ------------------------------------------------------------------
    // GET /senha/esqueci
    // ------------------------------------------------------------------

    #[TestDox('GET /senha/esqueci leva à tela de login com o passo "Recuperar senha" aberto')]
    public function testEsqueciRedirecionaParaOModalDoLogin(): void
    {
        $client = static::createClient();
        $client->request('GET', '/senha/esqueci');

        self::assertResponseRedirects('/login?abrir=senha');
    }

    #[TestDox('GET /senha/esqueci com sessão aberta continua indo para a seleção de escritório')]
    public function testEsqueciLogadoVaiParaSelecao(): void
    {
        $client = static::createClient();
        $client->loginUser($this->criarUsuario());
        $this->marcarTermosAceitos($client);

        $client->request('GET', '/senha/esqueci');

        self::assertResponseRedirects('/escritorio/selecionar');
    }

    #[TestDox('O formulário antigo de pedido por POST não existe mais')]
    public function testPostNoEsqueciNaoExisteMais(): void
    {
        $client = static::createClient();
        $client->request('POST', '/senha/esqueci');

        self::assertResponseStatusCodeSame(405);
    }

    #[TestDox('A tela de login aponta para a recuperação de senha')]
    public function testLoginTemLinkParaRecuperacao(): void
    {
        $client  = static::createClient();
        $crawler = $client->request('GET', '/login');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('a[href="/senha/esqueci"]'));
    }

    // ------------------------------------------------------------------
    // POST /senha/codigo
    // ------------------------------------------------------------------

    #[TestDox('Caminho feliz: o código do e-mail troca a senha e o usuário entra com a nova')]
    public function testFluxoCompletoPorCodigo(): void
    {
        $client = $this->clienteJson();
        $user   = $this->criarUsuario();

        $client->enableProfiler();
        self::assertSame(['ok' => true], $this->pedirCodigo($client, (string) $user->getEmail()));
        self::assertResponseIsSuccessful();
        self::assertEmailCount(1);

        $mensagem = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $mensagem);
        $assunto = (string) $mensagem->getSubject();
        self::assertSame(1, preg_match('/^Seu código BlueJus: (\d{6})$/', $assunto, $m), 'O assunto é o do desenho: ' . $assunto);
        self::assertStringContainsString('Ele vale por 15 minutos.', (string) $mensagem->getHtmlBody());
        self::assertStringContainsString($m[1], (string) $mensagem->getHtmlBody());

        // O banco guarda o HMAC, nunca o código.
        $pedido = $this->em()->getRepository(RedefinicaoSenha::class)->findOneBy(['user' => $user->getId()]);
        self::assertNotNull($pedido);
        self::assertNotSame($m[1], $pedido->getTokenHash());

        self::assertSame(['ok' => true], $this->confirmar($client, (string) $user->getEmail(), $m[1]));
        self::assertTrue($this->senhaValeNoBanco($user, self::SENHA_NOVA));
        self::assertFalse($this->senhaValeNoBanco($user, self::SENHA_ORIGINAL), 'A senha antiga não pode continuar valendo.');
        self::assertTrue($this->pedidoNoBanco((int) $pedido->getId())?->isUsado(), 'O código é de uso único.');
    }

    #[TestDox('E-mail sem conta: a mesma resposta, nenhum e-mail e nenhum pedido gravado (RN02)')]
    public function testRespostaNeutraParaEmailInexistente(): void
    {
        $client = $this->clienteJson();
        $antes  = $this->em()->getRepository(RedefinicaoSenha::class)->count([]);

        $client->enableProfiler();
        $resposta = $this->pedirCodigo($client, 'ninguem_' . uniqid() . '@adv.com');

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['ok' => true], $resposta);
        self::assertEmailCount(0);
        self::assertSame($antes, $this->em()->getRepository(RedefinicaoSenha::class)->count([]));
    }

    #[TestDox('Conta desativada não recebe código, e a resposta continua a mesma (RS04)')]
    public function testUsuarioInativoNaoRecebeCodigo(): void
    {
        $client = $this->clienteJson();
        $user   = $this->criarUsuario(ativo: false);

        $client->enableProfiler();
        self::assertSame(['ok' => true], $this->pedirCodigo($client, (string) $user->getEmail()));
        self::assertEmailCount(0);
        self::assertNull($this->em()->getRepository(RedefinicaoSenha::class)->findOneBy(['user' => $user->getId()]));
    }

    #[TestDox('Pedir de novo derruba o pedido anterior (código ou link)')]
    public function testPedidoNovoInvalidaOAnterior(): void
    {
        $client = $this->clienteJson();
        $user   = $this->criarUsuario();
        $antigo = $this->criarPedidoDeCodigo($user, '111111');
        $link   = $this->criarPedido($user, $this->token());
        $ids    = [(int) $antigo->getId(), (int) $link->getId()];

        $this->pedirCodigo($client, (string) $user->getEmail());

        // Encerrados (usado_em), não apagados: a linha fica até a purga para o teto diário contar o pedido.
        self::assertTrue($this->pedidoNoBanco($ids[0])?->isUsado(), 'O código anterior tem que morrer quando um novo é pedido.');
        self::assertTrue($this->pedidoNoBanco($ids[1])?->isUsado(), 'O link pendente também.');
        self::assertSame(1, $this->em()->getRepository(RedefinicaoSenha::class)->count(['user' => $user->getId(), 'usadoEm' => null]));
        self::assertSame(self::ERRO_CODIGO, $this->confirmar($client, (string) $user->getEmail(), '111111')['erro'] ?? null);
    }

    /** Semeia `$quantos` pedidos de código já encerrados para a conta, vindos de `$ip`. */
    private function semearPedidos(User $user, int $quantos, string $ip, int $inicio = 0): void
    {
        for ($i = 0; $i < $quantos; ++$i) {
            $pedido = $this->criarPedidoDeCodigo($user, sprintf('%06d', $inicio + $i), ip: $ip);
            $pedido->marcarUsado(new \DateTimeImmutable());
        }
        $this->em()->flush();
    }

    #[TestDox('Teto diário: o 11º pedido do dia vindo do mesmo IP não cria nem envia, e a resposta continua neutra')]
    public function testTetoDiarioPorContaEIp(): void
    {
        $client = $this->clienteJson();
        $user   = $this->criarUsuario();
        $this->semearPedidos($user, SolicitarCodigoSenhaUseCase::TETO_DIARIO, self::IP_DO_CLIENTE);

        $client->enableProfiler();
        $resposta = $this->pedirCodigo($client, (string) $user->getEmail());

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['ok' => true], $resposta);
        self::assertEmailCount(0);
        self::assertSame(
            SolicitarCodigoSenhaUseCase::TETO_DIARIO,
            $this->em()->getRepository(RedefinicaoSenha::class)->count(['user' => $user->getId()]),
        );
    }

    #[TestDox('Teto diário: pedidos de outro IP para a mesma conta não trancam quem pede deste IP')]
    public function testTetoDiarioDeOutroIpNaoTranca(): void
    {
        $client = $this->clienteJson();
        $user   = $this->criarUsuario();
        $this->semearPedidos($user, SolicitarCodigoSenhaUseCase::TETO_DIARIO, '9.9.9.9');

        $client->enableProfiler();
        self::assertSame(['ok' => true], $this->pedirCodigo($client, (string) $user->getEmail()));
        self::assertEmailCount(1);
        self::assertSame(
            SolicitarCodigoSenhaUseCase::TETO_DIARIO + 1,
            $this->em()->getRepository(RedefinicaoSenha::class)->count(['user' => $user->getId()]),
        );
    }

    #[TestDox('Teto diário: pedido de mais de 24 h atrás não conta')]
    public function testTetoDiarioIgnoraPedidosAntigos(): void
    {
        $client = $this->clienteJson();
        $user   = $this->criarUsuario();
        $this->criarPedidoDeCodigo($user, '000001', ip: self::IP_DO_CLIENTE);
        // Pela data do PHP, não pelo NOW() do banco: a coluna é sem fuso e o fuso do banco não é o da aplicação.
        $antigo = (new \DateTimeImmutable('-25 hours'))->format('Y-m-d H:i:s');
        $this->em()->getConnection()->executeStatement(
            'UPDATE redefinicao_senha SET criado_em = :antigo, usado_em = :antigo WHERE user_id = :id',
            ['id' => $user->getId(), 'antigo' => $antigo],
        );
        $this->semearPedidos($user, SolicitarCodigoSenhaUseCase::TETO_DIARIO - 1, self::IP_DO_CLIENTE, 100);

        $client->enableProfiler();
        self::assertSame(['ok' => true], $this->pedirCodigo($client, (string) $user->getEmail()));
        self::assertEmailCount(1);
    }

    #[TestDox('Trocar a senha pelo código do e-mail confirma o e-mail da conta')]
    public function testCodigoConfirmaOEmail(): void
    {
        $client = $this->clienteJson();
        $user   = $this->criarUsuario();
        $user->marcarEmailAConfirmar(new \DateTimeImmutable('-1 hour'));
        $this->em()->flush();
        $this->criarPedidoDeCodigo($user, '123456');

        self::assertSame(['ok' => true], $this->confirmar($client, (string) $user->getEmail(), '123456'));

        $this->em()->clear();
        self::assertTrue($this->em()->find(User::class, $user->getId())?->isEmailConfirmado());
    }

    #[TestDox('E-mail com formato inválido devolve 422 com o texto do desenho e não gasta cota')]
    public function testEmailInvalido(): void
    {
        $client  = $this->clienteJson();
        $espioes = $this->espionarLimiters();

        $resposta = $this->pedirCodigo($client, 'nao-e-email');

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['erro' => 'Informe um e-mail válido.'], $resposta);
        self::assertSame([], $espioes['ip']->chavesConsumidas);
        self::assertSame([], $espioes['email']->chavesConsumidas);
    }

    #[TestDox('CSRF inválido no pedido: 403 "Sessão expirada" e nenhuma cota gasta')]
    public function testCsrfInvalidoNoPedido(): void
    {
        $client  = $this->clienteJson();
        $espioes = $this->espionarLimiters();

        $client->enableProfiler();
        $resposta = $this->pedirCodigo($client, (string) $this->criarUsuario()->getEmail(), 'csrf-invalido');

        self::assertResponseStatusCodeSame(403);
        self::assertSame(['erro' => 'Sessão expirada. Recarregue a página.'], $resposta);
        self::assertEmailCount(0);
        self::assertSame([], $espioes['ip']->chavesConsumidas, 'POST inválido não pode gastar a cota do IP.');
        self::assertSame([], $espioes['email']->chavesConsumidas);
    }

    #[TestDox('Corpo que não é JSON cai no CSRF (403), sem 500')]
    public function testCorpoQueNaoEJson(): void
    {
        $client = $this->clienteJson();
        $client->request('POST', '/senha/codigo', [], [], ['CONTENT_TYPE' => 'application/json'], 'isto não é json');

        self::assertResponseStatusCodeSame(403);
    }

    #[TestDox('Cota estourada devolve 429 com o texto e não cria pedido nenhum')]
    public function testCotaEstouradaDevolve429(): void
    {
        $client = $this->clienteJson();
        $this->espionarLimiters(aceita: false);
        $user  = $this->criarUsuario();
        $antes = $this->em()->getRepository(RedefinicaoSenha::class)->count([]);

        $resposta = $this->pedirCodigo($client, (string) $user->getEmail());

        self::assertResponseStatusCodeSame(429);
        self::assertSame(['erro' => 'Muitas tentativas. Tente de novo mais tarde.'], $resposta);
        self::assertSame($antes, $this->em()->getRepository(RedefinicaoSenha::class)->count([]), 'Barrado no limite: nada é gravado.');
    }

    #[TestDox('Pedido válido consome as duas cotas, e a do e-mail é normalizada')]
    public function testPedidoValidoConsomeAsDuasCotas(): void
    {
        $client  = $this->clienteJson();
        $espioes = $this->espionarLimiters();

        $this->pedirCodigo($client, '  ALVO@Adv.com ');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $espioes['ip']->chavesConsumidas);
        self::assertSame(
            ['alvo@adv.com'],
            $espioes['email']->chavesConsumidas,
            'A cota por e-mail precisa usar a chave normalizada, senão trocar a caixa zera o limite.',
        );
    }

    #[TestDox('Conta gravada com maiúscula no e-mail recebe o código (contra o LOWER() real)')]
    public function testContaComMaiusculaRecebeCodigo(): void
    {
        $client = $this->clienteJson();
        $sufixo = uniqid();
        $user   = $this->criarUsuario(email: 'Ana_' . $sufixo . '@Adv.com');

        $client->enableProfiler();
        $this->pedirCodigo($client, 'ana_' . $sufixo . '@adv.com');

        self::assertEmailCount(1, null, 'A conta existe; o código tem que sair.');
        self::assertNotNull($this->em()->getRepository(RedefinicaoSenha::class)->findOneBy(['user' => $user->getId()]));
    }

    // ------------------------------------------------------------------
    // POST /senha/codigo/confirmar
    // ------------------------------------------------------------------

    #[TestDox('Código errado: 422 com o texto neutro e uma tentativa contada')]
    public function testCodigoErrado(): void
    {
        $client = $this->clienteJson();
        $user   = $this->criarUsuario();
        $pedido = $this->criarPedidoDeCodigo($user, '123456');

        $resposta = $this->confirmar($client, (string) $user->getEmail(), '654321');

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['erro' => self::ERRO_CODIGO], $resposta);
        self::assertSame(1, $this->pedidoNoBanco((int) $pedido->getId())?->getTentativas());
        self::assertTrue($this->senhaValeNoBanco($user, self::SENHA_ORIGINAL));
    }

    #[TestDox('Conta inexistente no confirmar: o mesmo texto do código errado (RN02)')]
    public function testConfirmarContaInexistente(): void
    {
        $client = $this->clienteJson();

        $resposta = $this->confirmar($client, 'ninguem_' . uniqid() . '@adv.com', '123456');

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['erro' => self::ERRO_CODIGO], $resposta);
    }

    #[TestDox('Código vencido: o mesmo texto neutro')]
    public function testCodigoVencido(): void
    {
        $client = $this->clienteJson();
        $user   = $this->criarUsuario();
        $this->criarPedidoDeCodigo($user, '123456', '-1 minute');

        self::assertSame(['erro' => self::ERRO_CODIGO], $this->confirmar($client, (string) $user->getEmail(), '123456'));
        self::assertResponseStatusCodeSame(422);
    }

    #[TestDox('Cinco códigos errados queimam o pedido: nem o certo passa depois')]
    public function testCincoTentativasQueimamOPedido(): void
    {
        $client = $this->clienteJson();
        $user   = $this->criarUsuario();
        $pedido = $this->criarPedidoDeCodigo($user, '123456');
        $email  = (string) $user->getEmail();

        foreach (['000001', '000002', '000003', '000004', '000005'] as $errado) {
            self::assertSame(['erro' => self::ERRO_CODIGO], $this->confirmar($client, $email, $errado));
        }

        $noBanco = $this->pedidoNoBanco((int) $pedido->getId());
        self::assertSame(5, $noBanco?->getTentativas());
        self::assertTrue($noBanco?->isUsado(), 'Com 5 erros o pedido fica inválido.');

        self::assertSame(['erro' => self::ERRO_CODIGO], $this->confirmar($client, $email, '123456'));
        self::assertResponseStatusCodeSame(422);
        self::assertTrue($this->senhaValeNoBanco($user, self::SENHA_ORIGINAL), 'O código certo não pode mais trocar a senha.');
    }

    #[TestDox('Código fora do formato não gasta tentativa')]
    public function testFormatoErradoNaoGastaTentativa(): void
    {
        $client = $this->clienteJson();
        $user   = $this->criarUsuario();
        $pedido = $this->criarPedidoDeCodigo($user, '123456');

        self::assertSame(['erro' => self::ERRO_CODIGO], $this->confirmar($client, (string) $user->getEmail(), '12a4'));
        self::assertSame(0, $this->pedidoNoBanco((int) $pedido->getId())?->getTentativas());
    }

    #[TestDox('Senha fora da régua: 422 com o texto da ForcaDaSenha, e o código continua valendo')]
    public function testSenhaFraca(): void
    {
        $client = $this->clienteJson();
        $user   = $this->criarUsuario();
        $pedido = $this->criarPedidoDeCodigo($user, '123456');

        $resposta = $this->confirmar($client, (string) $user->getEmail(), '123456', 'abcdefgh');

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['erro' => ForcaDaSenha::ERRO], $resposta);
        self::assertFalse($this->pedidoNoBanco((int) $pedido->getId())?->isUsado());
        self::assertTrue($this->senhaValeNoBanco($user, self::SENHA_ORIGINAL));
    }

    #[TestDox('Confirmação diferente: 422 "As senhas não conferem."')]
    public function testConfirmacaoDiferente(): void
    {
        $client = $this->clienteJson();
        $user   = $this->criarUsuario();
        $this->criarPedidoDeCodigo($user, '123456');

        $resposta = $this->confirmar($client, (string) $user->getEmail(), '123456', self::SENHA_NOVA, 'OutraSenha789');

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['erro' => 'As senhas não conferem.'], $resposta);
        self::assertTrue($this->senhaValeNoBanco($user, self::SENHA_ORIGINAL));
    }

    #[TestDox('CSRF inválido no confirmar: 403 e a senha não muda')]
    public function testCsrfInvalidoNoConfirmar(): void
    {
        $client = $this->clienteJson();
        $user   = $this->criarUsuario();
        $this->criarPedidoDeCodigo($user, '123456');

        $resposta = $this->postar($client, '/senha/codigo/confirmar', [
            'email'       => (string) $user->getEmail(),
            'codigo'      => '123456',
            'senha'       => self::SENHA_NOVA,
            'confirmacao' => self::SENHA_NOVA,
        ], 'csrf-invalido');

        self::assertResponseStatusCodeSame(403);
        self::assertSame(['erro' => 'Sessão expirada. Recarregue a página.'], $resposta);
        self::assertTrue($this->senhaValeNoBanco($user, self::SENHA_ORIGINAL));
    }

    #[TestDox('RS03: a troca por código derruba a sessão que já estava aberta')]
    public function testCodigoDerrubaSessaoViva(): void
    {
        $client = static::createClient();
        $user   = $this->criarUsuario();

        $client->loginUser($user);
        $this->marcarTermosAceitos($client);

        $client->request('GET', '/termos/aceitar');
        self::assertResponseIsSuccessful('A sessão precisa estar valendo antes da troca.');

        // Outro dispositivo troca a senha pelo código enquanto esta sessão está aberta.
        $this->criarPedidoDeCodigo($user, '123456');
        static::getContainer()->get(ConfirmarCodigoSenhaUseCase::class)->executar(
            new ConfirmarCodigoSenhaInput((string) $user->getEmail(), '123456', self::SENHA_NOVA, self::SENHA_NOVA),
        );

        $client->request('GET', '/termos/aceitar');
        self::assertResponseRedirects('http://localhost/login');
    }

    // ------------------------------------------------------------------
    // Link antigo /senha/redefinir/{token} (pedidos já enviados)
    // ------------------------------------------------------------------

    #[TestDox('O link do e-mail redefine a senha e o usuário entra com a senha nova')]
    public function testFluxoCompletoPermiteLogarComSenhaNova(): void
    {
        $client = static::createClient();
        $user   = $this->criarUsuario();
        $token  = $this->token();
        $this->criarPedido($user, $token);

        $crawler = $client->request('GET', '/senha/redefinir/' . $token);
        self::assertResponseIsSuccessful();

        $form = $crawler->selectButton('Salvar nova senha')->form();
        $form['redefinir_senha[senha][first]']  = self::SENHA_NOVA;
        $form['redefinir_senha[senha][second]'] = self::SENHA_NOVA;
        $client->submit($form);

        self::assertResponseRedirects('/login');
        self::assertTrue($this->senhaValeNoBanco($user, self::SENHA_NOVA));
        self::assertFalse($this->senhaValeNoBanco($user, self::SENHA_ORIGINAL), 'A senha antiga não pode continuar valendo.');
    }

    #[TestDox('Token já usado não abre o formulário (uso único)')]
    public function testTokenUsadoRecusado(): void
    {
        $client = static::createClient();
        $user   = $this->criarUsuario();
        $token  = $this->token();
        $pedido = $this->criarPedido($user, $token);
        $pedido->marcarUsado();
        $this->em()->flush();

        $client->request('GET', '/senha/redefinir/' . $token);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Não foi possível usar este link');
    }

    #[TestDox('Token expirado não abre o formulário')]
    public function testTokenExpiradoRecusado(): void
    {
        $client = static::createClient();
        $token  = $this->token();
        $this->criarPedido($this->criarUsuario(), $token, expiraEm: '-1 minute');

        $client->request('GET', '/senha/redefinir/' . $token);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Não foi possível usar este link');
    }

    #[TestDox('Token inexistente não abre o formulário, e a tela leva à recuperação por código')]
    public function testTokenInexistenteRecusado(): void
    {
        $client  = static::createClient();
        $crawler = $client->request('GET', '/senha/redefinir/' . $this->token());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Não foi possível usar este link');
        self::assertCount(1, $crawler->filter('a[href="/senha/esqueci"]'));
    }

    #[TestDox('Token fora do formato de 64 hex nem chega ao controller (404)')]
    public function testRequirementDaRota(): void
    {
        $client = static::createClient();
        $client->request('GET', '/senha/redefinir/token-curto');

        self::assertResponseStatusCodeSame(404);
    }

    #[TestDox('Uso único ponta a ponta: redefinir de verdade e tentar o MESMO link de novo')]
    public function testUsoUnicoPontaAPonta(): void
    {
        $client = static::createClient();
        $user   = $this->criarUsuario();
        $token  = $this->token();
        $this->criarPedido($user, $token);

        $crawler = $client->request('GET', '/senha/redefinir/' . $token);
        $form    = $crawler->selectButton('Salvar nova senha')->form();
        $form['redefinir_senha[senha][first]']  = self::SENHA_NOVA;
        $form['redefinir_senha[senha][second]'] = self::SENHA_NOVA;
        $client->submit($form);
        self::assertResponseRedirects('/login');

        $client->request('GET', '/senha/redefinir/' . $token);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Não foi possível usar este link');
        self::assertTrue($this->senhaValeNoBanco($user, self::SENHA_NOVA));
    }

    #[TestDox('RS03: redefinir pelo link derruba a sessão que já estava aberta')]
    public function testRedefinicaoDerrubaSessaoViva(): void
    {
        $client = static::createClient();
        $user   = $this->criarUsuario();

        $client->loginUser($user);
        $this->marcarTermosAceitos($client);

        $client->request('GET', '/termos/aceitar');
        self::assertResponseIsSuccessful('A sessão precisa estar valendo antes da troca.');

        static::getContainer()->get(RedefinirSenhaUseCase::class)
            ->executar($this->tokenDePedidoNovo($user), self::SENHA_NOVA);

        $client->request('GET', '/termos/aceitar');
        self::assertResponseRedirects('http://localhost/login');
    }

    #[TestDox('Guarda do teste de remember-me: sem o cookie, esquecerSessao() derruba mesmo a autenticação')]
    public function testEsquecerSessaoDerrubaAutenticacaoSemCookie(): void
    {
        // Sem esta prova, o teste seguinte poderia passar por engano: se esquecerSessao()
        // não apagasse a sessão de verdade, o 200 do meio viria dela, e não do cookie.
        $client = static::createClient();
        $user   = $this->criarUsuario();

        $client->loginUser($user);
        $this->marcarTermosAceitos($client);
        $client->request('GET', '/termos/aceitar');
        self::assertResponseIsSuccessful();

        $this->esquecerSessao($client);
        $client->request('GET', '/termos/aceitar');

        self::assertResponseRedirects('http://localhost/login', null, 'Sem sessão e sem remember-me não pode haver autenticação.');
    }

    #[TestDox('RS03: o cookie "lembrar-me" antigo para de autenticar depois da troca')]
    public function testRedefinicaoDerrubaRememberMe(): void
    {
        $client = static::createClient();
        $user   = $this->criarUsuario();

        // Login real pelo formulário: só assim o cookie remember-me é emitido.
        $crawler = $client->request('GET', '/login');
        $form    = $crawler->selectButton('Entrar')->form();
        $form['email']    = $user->getEmail();
        $form['password'] = self::SENHA_ORIGINAL;
        $form['_remember_me']->tick();
        $client->submit($form);

        $cookieRemember = $client->getCookieJar()->get('REMEMBERME');
        self::assertNotNull($cookieRemember, 'O login com "manter conectado" precisa emitir o cookie.');

        // Derruba só a sessão: o que autenticar daqui em diante vem do cookie.
        $this->esquecerSessao($client);
        $client->request('GET', '/termos/aceitar');
        self::assertResponseIsSuccessful('O cookie sozinho tem que autenticar antes da troca.');

        static::getContainer()->get(RedefinirSenhaUseCase::class)
            ->executar($this->tokenDePedidoNovo($user), self::SENHA_NOVA);

        $this->esquecerSessao($client);
        $client->getCookieJar()->set($cookieRemember);
        $client->request('GET', '/termos/aceitar');

        self::assertResponseRedirects('http://localhost/login', null, 'O cookie antigo não pode mais autenticar: a assinatura inclui o hash da senha.');
    }

    /** Cria um pedido de link válido e devolve o token em claro, para acionar o UseCase direto. */
    private function tokenDePedidoNovo(User $user): string
    {
        $token = $this->token();
        $this->criarPedido($user, $token);

        return $token;
    }

    /** Expira o cookie de sessão sem tocar no remember-me. */
    private function esquecerSessao(KernelBrowser $client): void
    {
        foreach ($client->getCookieJar()->all() as $cookie) {
            if ($cookie->getName() !== 'REMEMBERME') {
                $client->getCookieJar()->expire($cookie->getName());
            }
        }
    }
}
