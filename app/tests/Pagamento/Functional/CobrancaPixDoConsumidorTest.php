<?php

declare(strict_types=1);

namespace App\Tests\Pagamento\Functional;

use App\Contribua\Banco\CofreDeCertificado;
use App\Contribua\Banco\Cora\ClienteCora;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Pagamento\DTO\EmitirCobrancaPixInput;
use App\Pagamento\Enum\OrigemDoPagamento;
use App\Pagamento\Enum\StatusDoPagamento;
use App\Pagamento\UseCase\CancelarCobrancaDaOrigemUseCase;
use App\Pagamento\UseCase\ConsultarCobrancaDaOrigemUseCase;
use App\Pagamento\UseCase\EmitirCobrancaPixUseCase;
use App\Tests\Contribua\Banco\CoraFalsa;
use App\Tests\Contribua\Banco\ParDeCertificado;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

/**
 * Gateway M1, lado do registro de pagamentos: emitir o PIX dinâmico de um consumidor contra a Cora falsa, consultar a
 * cobrança mais recente e cancelá-la. Spec: docs/specs/gateway-m1-trabalho-externo.md §4.1.
 */
#[CoversClass(EmitirCobrancaPixUseCase::class)]
#[CoversClass(ConsultarCobrancaDaOrigemUseCase::class)]
#[CoversClass(CancelarCobrancaDaOrigemUseCase::class)]
final class CobrancaPixDoConsumidorTest extends JusPrimeWebTestCase
{
    private const TOKEN = 'TOKEN_contribua';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private CoraFalsa $cora;
    private Tenant $escritorio;
    private User $master;
    private int $sequencia = 0;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->cora = new CoraFalsa();
        $cofre = new CofreDeCertificado(base64_encode(random_bytes(32)));
        static::getContainer()->set(CofreDeCertificado::class, $cofre);
        static::getContainer()->set(ClienteCora::class, new ClienteCora($this->cora->cliente(), new ArrayAdapter(), $cofre));
        $this->instalarCsrf();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->escritorio = $this->tenant();
        $this->master = $this->pessoa('master', ['ROLE_SUPER_ADMIN']);
    }

    #[TestDox('Sem a chave "te" ligada não há cobrança no banco nem pagamento criado')]
    public function testSemAChaveNaoEmite(): void
    {
        $this->conectar('0');

        self::assertNull($this->emitir('TE-0001-1'));
        self::assertNull(static::getContainer()->get(ConsultarCobrancaDaOrigemUseCase::class)->executar((int) $this->escritorio->getId(), OrigemDoPagamento::TrabalhoExterno, 501));
    }

    #[TestDox('Com a chave ligada cria o pagamento antes do banco, guarda o copia e cola e repetir não chama o banco de novo')]
    public function testEmiteUmaVez(): void
    {
        $this->conectar();
        $primeira = $this->emitir('TE-0001-1');
        $pedidos = count($this->cora->pedidos);
        $segunda = $this->emitir('TE-0001-1');

        self::assertNotNull($primeira?->brCode);
        self::assertSame($primeira->pagamentoId, $segunda?->pagamentoId);
        self::assertCount($pedidos, $this->cora->pedidos, 'a segunda chamada não vai ao banco');
        self::assertSame(StatusDoPagamento::Aguardando, $primeira->status);
        $lido = static::getContainer()->get(ConsultarCobrancaDaOrigemUseCase::class)->executar((int) $this->escritorio->getId(), OrigemDoPagamento::TrabalhoExterno, 501);
        self::assertSame($primeira->brCode, $lido?->brCode);
        self::assertSame(1, $lido->tentativas);
        self::assertTrue($lido->viva(new \DateTimeImmutable()));
    }

    #[TestDox('Banco fora do ar deixa o pagamento aguardando sem QR e a próxima chamada recupera com a mesma referência; recusa certa cancela')]
    public function testFalhasDoBanco(): void
    {
        $this->conectar();
        $this->cora->statusDaCriacao = 500;
        self::assertNull($this->emitir('TE-0001-1'));
        $lido = static::getContainer()->get(ConsultarCobrancaDaOrigemUseCase::class)->executar((int) $this->escritorio->getId(), OrigemDoPagamento::TrabalhoExterno, 501);
        self::assertSame(StatusDoPagamento::Aguardando, $lido?->status);
        self::assertNull($lido->brCode);

        $this->cora->statusDaCriacao = null;
        $depois = $this->emitir('TE-0001-1');
        self::assertSame($lido->pagamentoId, $depois?->pagamentoId);
        self::assertNotNull($depois->brCode);

        $this->cora->statusDaCriacao = 400;
        self::assertNull($this->emitir('TE-0001-2'));
        $recusada = static::getContainer()->get(ConsultarCobrancaDaOrigemUseCase::class)->executar((int) $this->escritorio->getId(), OrigemDoPagamento::TrabalhoExterno, 501);
        self::assertSame(StatusDoPagamento::Cancelada, $recusada?->status);
        self::assertSame(2, $recusada->tentativas);
    }

    #[TestDox('Cancelar a cobrança da origem: cancela a aguardando com o motivo na trilha e a segunda vez não faz nada')]
    public function testCancelar(): void
    {
        $this->conectar();
        $this->emitir('TE-0001-1');
        $cancelar = static::getContainer()->get(CancelarCobrancaDaOrigemUseCase::class);

        self::assertTrue($cancelar->executar((int) $this->escritorio->getId(), OrigemDoPagamento::TrabalhoExterno, 501, 'Valor ajustado de R$ 20,00 para R$ 30,00'));
        self::assertFalse($cancelar->executar((int) $this->escritorio->getId(), OrigemDoPagamento::TrabalhoExterno, 501, 'de novo'));
        self::assertSame('Valor ajustado de R$ 20,00 para R$ 30,00', $this->banco()->fetchOne("SELECT resumo FROM pagamento_evento WHERE tipo = 'cancelado'"));
    }

    private function emitir(string $referencia): ?\App\Pagamento\DTO\CobrancaPixOutput
    {
        $this->em->clear();

        return static::getContainer()->get(EmitirCobrancaPixUseCase::class)->executar(new EmitirCobrancaPixInput(
            (int) $this->escritorio->getId(),
            OrigemDoPagamento::TrabalhoExterno,
            501,
            $referencia,
            2000,
            new \DateTimeImmutable('+24 hours'),
            'te',
            'Escritório de teste',
            '12345678000199',
        ));
    }

    private function banco(): Connection
    {
        return static::getContainer()->get(Connection::class);
    }

    private function conectar(string $te = '1', string $ambiente = 'producao'): void
    {
        $this->logarComTenant($this->client, $this->master, $this->escritorio);
        $par = ParDeCertificado::novo();
        $dir = sys_get_temp_dir() . '/bj-cert-' . uniqid();
        mkdir($dir);
        file_put_contents($dir . '/cert.pem', $par['certificado']);
        file_put_contents($dir . '/chave.key', $par['chave']);
        $this->client->request('POST', '/contribua/integracao-bancaria/certificado', ['_token' => self::TOKEN], ['arquivos' => [
            new UploadedFile($dir . '/cert.pem', 'cert.pem', null, null, true),
            new UploadedFile($dir . '/chave.key', 'chave.key', null, null, true),
        ]]);
        self::assertTrue(json_decode((string) $this->client->getResponse()->getContent(), true)['ok'], (string) $this->client->getResponse()->getContent());
        self::assertTrue($this->post('/contribua/integracao-bancaria/salvar', ['ambiente' => $ambiente, 'clientId' => 'cliente-1', 'recursos' => ['cob' => '1', 'conc' => '1', 'bol' => '0', 'rel' => '1', 'te' => $te]])['ok']);
        self::assertTrue($this->post('/contribua/integracao-bancaria/testar')['conectado']);
    }

    /**
     * @param array<string, string|array<string, string>> $campos
     *
     * @return array<string, mixed>
     */
    private function post(string $url, array $campos = []): array
    {
        $this->client->request('POST', $url, ['_token' => self::TOKEN] + $campos, [], ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);

        return (array) json_decode((string) $this->client->getResponse()->getContent(), true);
    }

    private function tenant(): Tenant
    {
        $tenant = (new Tenant())->setName('Escritório PAGAMENTO ' . uniqid());
        $tenant->setCnpj('12345678000199');
        $this->em->persist($tenant);
        $this->em->flush();

        return $tenant;
    }

    /** @param list<string> $roles */
    private function pessoa(string $prefixo, array $roles): User
    {
        $user = new User();
        $user->setEmail($prefixo . '_' . uniqid() . '@test.com');
        $user->setFullName('Pessoa ' . ucfirst($prefixo));
        $user->setRoles($roles);
        $user->setIsActive(true);
        $user->setPassword('x');
        $this->em->persist($user);
        $papel = (new TenantRole())->setTenant($this->escritorio)->setName('Primário ' . uniqid())->setIsSystem(true);
        $this->em->persist($papel);
        $vinculo = new UserTenant($user, $this->escritorio);
        $vinculo->setTenantRole($papel);
        $this->em->persist($vinculo);
        $this->em->flush();

        return $user;
    }

    private function instalarCsrf(): void
    {
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
    }
}
