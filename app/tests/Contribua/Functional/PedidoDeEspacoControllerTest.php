<?php

declare(strict_types=1);

namespace App\Tests\Contribua\Functional;

use App\Contribua\Controller\ContribuaController;
use App\Contribua\DTO\PedidoDeEspacoDoEscritorioView;
use App\Contribua\DTO\Solicitante;
use App\Contribua\Entity\PedidoDeEspaco;
use App\Contribua\Enum\OrigemDaLiberacao;
use App\Contribua\Enum\SituacaoDoPedidoDeEspaco;
use App\Contribua\Repository\PedidoDeEspacoRepository;
use App\Contribua\Service\LiberacaoDasOpcoesDeEspaco;
use App\Contribua\Service\SituacaoDoEspaco;
use App\Contribua\Service\VisaoDoMaster;
use App\Contribua\UseCase\DecidirPedidoDeEspacoUseCase;
use App\Contribua\UseCase\ListarPedidosDeEspacoParaOMasterUseCase;
use App\Contribua\UseCase\PedirEspacoUseCase;
use App\Contribua\UseCase\SolicitarMaisEspacoUseCase;
use App\Contribua\UseCase\VerPedidoDeEspacoDoEscritorioUseCase;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

/**
 * Pedidos de espaço e liberação das opções de compra (desenho Contribua 14 e 15/11): rotas, isolamento por escritório,
 * decisão do Master e trava D2.
 */
#[CoversClass(ContribuaController::class)]
#[CoversClass(SolicitarMaisEspacoUseCase::class)]
#[CoversClass(DecidirPedidoDeEspacoUseCase::class)]
#[CoversClass(ListarPedidosDeEspacoParaOMasterUseCase::class)]
#[CoversClass(VerPedidoDeEspacoDoEscritorioUseCase::class)]
#[CoversClass(PedidoDeEspacoRepository::class)]
#[CoversClass(LiberacaoDasOpcoesDeEspaco::class)]
#[CoversClass(PedirEspacoUseCase::class)]
final class PedidoDeEspacoControllerTest extends JusPrimeWebTestCase
{
    private const TOKEN = 'TOKEN_contribua';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Tenant $escritorio;
    private Tenant $outro;
    private User $primario;
    private User $secundario;
    private User $master;
    private User $primarioDoOutro;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->instalarCsrfStorage();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->escritorio = $this->tenant('A');
        $this->outro = $this->tenant('B');
        $this->primario = $this->pessoa('primario', $this->escritorio, true);
        $this->secundario = $this->pessoa('secundario', $this->escritorio, false);
        $this->master = $this->pessoa('master', $this->escritorio, false, ['ROLE_SUPER_ADMIN']);
        $this->primarioDoOutro = $this->pessoa('primariob', $this->outro, true);
    }

    #[TestDox('O primário solicita: grava o pedido só no escritório dele e avisa o Master na Central')]
    public function testPrimarioSolicita(): void
    {
        $this->logarComTenant($this->client, $this->primario, $this->escritorio);

        $resposta = $this->post('/contribua/espaco/solicitar', ['gb' => 50, 'motivo' => 'Vamos digitalizar os processos']);

        self::assertResponseIsSuccessful();
        self::assertSame(['ok' => true, 'mensagem' => 'Pedido enviado'], $resposta);
        self::assertSame(1, $this->contar('SELECT COUNT(*) FROM contribua_pedido_de_espaco WHERE tenant_id = :t', $this->escritorio));
        self::assertSame(0, $this->contar('SELECT COUNT(*) FROM contribua_pedido_de_espaco WHERE tenant_id = :t', $this->outro));
        $linha = static::getContainer()->get(Connection::class)->fetchAssociative('SELECT gb_desejado, motivo, situacao FROM contribua_pedido_de_espaco WHERE tenant_id = ?', [$this->escritorio->getId()]);
        self::assertSame(['gb_desejado' => 50, 'motivo' => 'Vamos digitalizar os processos', 'situacao' => 'pendente'], $linha);
        self::assertSame(1, $this->contar("SELECT COUNT(*) FROM notificacao WHERE tipo = 'contribua.pedido_espaco' AND tenant_id = :t", $this->escritorio));
    }

    #[TestDox('Só um pedido pendente por escritório; faixa e motivo inválidos são recusados')]
    public function testUmPendenteEValidacoes(): void
    {
        $this->logarComTenant($this->client, $this->primario, $this->escritorio);

        self::assertTrue($this->post('/contribua/espaco/solicitar', ['gb' => 20])['ok']);
        $segundo = $this->post('/contribua/espaco/solicitar', ['gb' => 50]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('Já existe um pedido de espaço em análise para o seu escritório.', $segundo['erro']);

        $this->logarComTenant($this->client, $this->primarioDoOutro, $this->outro);
        $this->post('/contribua/espaco/solicitar', ['gb' => 30]);
        self::assertResponseStatusCodeSame(422);
        $this->post('/contribua/espaco/solicitar', ['gb' => 20, 'motivo' => str_repeat('x', 301)]);
        self::assertResponseStatusCodeSame(422);
        self::assertTrue($this->post('/contribua/espaco/solicitar', ['gb' => 100])['ok'], 'o pendente de A não trava o escritório B');
    }

    #[TestDox('Quem não é primário nem Master não solicita (recusa do sistema), e sem token CSRF a ação é 403')]
    public function testSecundarioECsrf(): void
    {
        $this->logarComTenant($this->client, $this->secundario, $this->escritorio);
        $resposta = $this->post('/contribua/espaco/solicitar', ['gb' => 20]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('Quem pede mais espaço é o usuário primário do escritório.', $resposta['erro']);

        $this->logarComTenant($this->client, $this->primario, $this->escritorio);
        $this->client->request('POST', '/contribua/espaco/solicitar', ['_token' => 'errado', 'gb' => 20]);
        self::assertResponseStatusCodeSame(403);
        self::assertSame(0, $this->contar('SELECT COUNT(*) FROM contribua_pedido_de_espaco WHERE tenant_id = :t', $this->escritorio));
    }

    #[TestDox('Liberar e recusar: 403 para o primário e o secundário; sem CSRF também 403 para o Master')]
    public function testSoOMasterDecide(): void
    {
        $id = $this->criarPedido($this->outro);

        foreach ([$this->primario, $this->secundario, $this->primarioDoOutro] as $quem) {
            $this->logarComTenant($this->client, $quem, $quem === $this->primarioDoOutro ? $this->outro : $this->escritorio);
            foreach (['liberar', 'recusar'] as $acao) {
                $this->client->request('POST', "/contribua/espaco/pedidos/{$id}/{$acao}", ['_token' => self::TOKEN]);
                self::assertResponseStatusCodeSame(403);
            }
        }
        $this->logarComTenant($this->client, $this->master, $this->escritorio);
        $this->client->request('POST', "/contribua/espaco/pedidos/{$id}/liberar", ['_token' => 'errado']);
        self::assertResponseStatusCodeSame(403);
        self::assertSame('pendente', $this->situacaoDoPedido($id));
    }

    #[TestDox('O Master libera o pedido de OUTRO escritório: grava a liberação permanente nele e só nele')]
    public function testMasterLibera(): void
    {
        $id = $this->criarPedido($this->outro, 'Conta B');
        $this->logarComTenant($this->client, $this->master, $this->escritorio);

        $resposta = $this->post("/contribua/espaco/pedidos/{$id}/liberar", []);

        self::assertResponseIsSuccessful();
        self::assertSame('Opções de compra liberadas para ' . $this->outro->getName() . '. O escritório será avisado por e-mail.', $resposta['mensagem']);
        self::assertSame('liberado', $this->situacaoDoPedido($id));
        $banco = static::getContainer()->get(Connection::class);
        self::assertSame('master', $banco->fetchOne('SELECT opcoes_liberadas_por FROM contribua_espaco_do_escritorio WHERE tenant_id = ?', [$this->outro->getId()]));
        self::assertFalse($banco->fetchOne('SELECT opcoes_liberadas_por FROM contribua_espaco_do_escritorio WHERE tenant_id = ?', [$this->escritorio->getId()]));

        $this->post("/contribua/espaco/pedidos/{$id}/liberar", []);
        self::assertResponseStatusCodeSame(422);
        $this->post("/contribua/espaco/pedidos/{$id}/recusar", []);
        self::assertResponseStatusCodeSame(422);
    }

    #[TestDox('O Master recusa: o pedido muda e nenhuma liberação é gravada')]
    public function testMasterRecusa(): void
    {
        $id = $this->criarPedido($this->outro);
        $this->logarComTenant($this->client, $this->master, $this->escritorio);

        $resposta = $this->post("/contribua/espaco/pedidos/{$id}/recusar", []);

        self::assertSame('Pedido recusado. O escritório será avisado por e-mail.', $resposta['mensagem']);
        self::assertSame('recusado', $this->situacaoDoPedido($id));
        self::assertSame(0, $this->contar('SELECT COUNT(*) FROM contribua_espaco_do_escritorio WHERE tenant_id = :t AND opcoes_liberadas_por IS NOT NULL', $this->outro));
    }

    #[TestDox('Pedido que não existe: recusa simples, sem erro de servidor')]
    public function testPedidoInexistente(): void
    {
        $this->logarComTenant($this->client, $this->master, $this->escritorio);

        $resposta = $this->post('/contribua/espaco/pedidos/99999999/liberar', []);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('Pedido de espaço não encontrado.', $resposta['erro']);
    }

    #[TestDox('Painel do Master vê os pedidos de todos os escritórios; o escritório vê só a situação do próprio')]
    public function testIsolamentoDosPedidos(): void
    {
        $this->criarPedido($this->escritorio);
        $this->criarPedido($this->outro, 'Do B');
        $c = static::getContainer();
        $listar = new ListarPedidosDeEspacoParaOMasterUseCase($c->get(PedidoDeEspacoRepository::class), $c->get(VisaoDoMaster::class));
        $ver = new VerPedidoDeEspacoDoEscritorioUseCase($c->get(PedidoDeEspacoRepository::class), $c->get(SituacaoDoEspaco::class));
        $this->logarComTenant($this->client, $this->master, $this->escritorio);

        $painel = $listar->executar(new Solicitante($this->master, $this->escritorio, true, false));

        self::assertEqualsCanonicalizing([(int) $this->escritorio->getId(), (int) $this->outro->getId()], array_values(array_intersect(array_column($painel->linhas, 'tenantId'), [(int) $this->escritorio->getId(), (int) $this->outro->getId()])));
        self::assertGreaterThanOrEqual(2, $painel->pendentes);
        self::assertStringContainsString('pendente(s) · ', $painel->resumo());
        $linhaB = array_values(array_filter($painel->linhas, fn ($l): bool => $l->tenantId === (int) $this->outro->getId()))[0];
        self::assertSame('mais de 50 GB', $linhaB->estimativa === 'mais de 50 GB' ? 'mais de 50 GB' : $linhaB->estimativa);
        self::assertSame(SituacaoDoPedidoDeEspaco::Pendente, $linhaB->situacao);
        self::assertStringStartsWith('uso ', $linhaB->usoTexto);

        $doA = $ver->executar(new Solicitante($this->primario, $this->escritorio, false, true));
        self::assertSame(PedidoDeEspacoDoEscritorioView::PENDENTE, $doA->estado);
        self::assertSame(
            PedidoDeEspacoDoEscritorioView::NENHUM,
            $ver->executar(new Solicitante($this->secundario, $this->pessoaSemPedidoTenant(), false, false))->estado,
            'o pedido de A e o de B não aparecem num terceiro escritório',
        );

        $this->expectException(\DomainException::class);
        $listar->executar(new Solicitante($this->primario, $this->escritorio, false, true));
    }

    #[TestDox('Trava D2: o escritório Free sem liberação não pede plano; liberado pelo Master, pede')]
    public function testTravaDoPedidoDePlano(): void
    {
        $this->logarComTenant($this->client, $this->primario, $this->escritorio);

        $resposta = $this->post('/contribua/espaco/pedido', ['plano' => 'essencial']);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(LiberacaoDasOpcoesDeEspaco::NAO_LIBERADAS, $resposta['erro']);

        $id = $this->criarPedido($this->escritorio);
        $this->logarComTenant($this->client, $this->master, $this->escritorio);
        $this->post("/contribua/espaco/pedidos/{$id}/liberar", []);
        self::assertResponseIsSuccessful();

        $this->logarComTenant($this->client, $this->primario, $this->escritorio);
        self::assertTrue($this->post('/contribua/espaco/pedido', ['plano' => 'essencial'])['ok']);
    }

    private function criarPedido(Tenant $escritorio, ?string $motivo = null): int
    {
        $pedido = new PedidoDeEspaco($escritorio, null, 100, $motivo, 3 * 1024 ** 3, 10 * 1024 ** 3);
        $this->em->persist($pedido);
        $this->em->flush();

        return (int) $pedido->getId();
    }

    private function situacaoDoPedido(int $id): string
    {
        return (string) static::getContainer()->get(Connection::class)->fetchOne('SELECT situacao FROM contribua_pedido_de_espaco WHERE id = ?', [$id]);
    }

    private function pessoaSemPedidoTenant(): Tenant
    {
        return $this->tenant('C');
    }

    /** @return array<string, mixed> */
    private function post(string $url, array $campos): array
    {
        $this->client->request('POST', $url, ['_token' => self::TOKEN] + $campos, [], ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);

        return (array) json_decode((string) $this->client->getResponse()->getContent(), true);
    }

    private function contar(string $sql, Tenant $tenant): int
    {
        return (int) static::getContainer()->get(Connection::class)->fetchOne($sql, ['t' => $tenant->getId()]);
    }

    private function tenant(string $nome): Tenant
    {
        $tenant = new Tenant();
        $tenant->setName('Escritório PEDIDO ESPACO ' . $nome . ' ' . uniqid());
        $this->em->persist($tenant);
        $this->em->flush();

        return $tenant;
    }

    /** @param list<string> $roles */
    private function pessoa(string $prefixo, Tenant $tenant, bool $primario, array $roles = ['ROLE_USER']): User
    {
        $user = new User();
        $user->setEmail($prefixo . '_' . uniqid() . '@test.com');
        $user->setFullName('Pessoa ' . ucfirst($prefixo));
        $user->setRoles($roles);
        $user->setIsActive(true);
        $user->setPassword('x');
        $this->em->persist($user);

        $papel = new TenantRole();
        $papel->setTenant($tenant);
        $papel->setName(($primario ? 'Primário ' : 'Equipe ') . uniqid());
        $papel->setIsSystem($primario);
        $this->em->persist($papel);

        $vinculo = new UserTenant($user, $tenant);
        $vinculo->setTenantRole($papel);
        $this->em->persist($vinculo);
        $this->em->flush();

        return $user;
    }

    private function instalarCsrfStorage(): void
    {
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
    }
}
