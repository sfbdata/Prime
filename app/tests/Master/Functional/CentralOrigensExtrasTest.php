<?php

declare(strict_types=1);

namespace App\Tests\Master\Functional;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Master\Controller\CentralDeNotificacoesController;
use App\Master\Notificacoes\LeitorDeAlertasDeConsumo;
use App\Master\Notificacoes\LeitorDePedidosDeExclusao;
use App\Master\Repository\PainelMasterRepository;
use App\Tenant\Entity\PedidoDeExclusaoDeConta;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

/**
 * Fontes extras da Central de Notificações do Master (spec docs/specs/master-central-leitores-exclusao-e-consumo.md): o
 * pedido de exclusão de conta e o alerta de consumo, de qualquer escritório, mesmo com o Master na sessão do dele.
 */
#[CoversClass(LeitorDePedidosDeExclusao::class)]
#[CoversClass(LeitorDeAlertasDeConsumo::class)]
final class CentralOrigensExtrasTest extends JusPrimeWebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Tenant $escritorioDoMaster;
    private Tenant $cliente;
    private string $marca;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->instalarTokensFalsos();
        $this->marca = 'ex' . bin2hex(random_bytes(4));
        $this->escritorioDoMaster = $this->escritorio('Conta do Master');
        $this->cliente = $this->escritorio('Silva Advogados ' . $this->marca);
    }

    #[TestDox('pedido de exclusão: aparece com a situação, em vermelho, e a resposta vai por e-mail para quem pediu')]
    public function testPedidoDeExclusao(): void
    {
        $primario = $this->usuario(['ROLE_USER'], 'Paula Primária', 'paula_' . $this->marca . '@exemplo.com');
        $pedido = new PedidoDeExclusaoDeConta($this->cliente, $primario, 'Vamos encerrar o escritório.', new \DateTimeImmutable());
        $this->em->persist($pedido);
        $this->em->flush();
        $this->entrarComoMaster();

        $item = $this->item($this->listar($this->marca), 'exclusao_conta:' . $pedido->getId());

        self::assertNotNull($item, 'o pedido de outro escritório aparece');
        self::assertSame(['alerta', 'Pedido de exclusão', 'Paula Primária', 'Quer excluir a conta ' . $this->cliente->getName(), true], [$item['tipo'], $item['rotulo'], $item['de'], $item['assunto'], $item['podeResponder']]);
        self::assertStringContainsString('Motivo: Vamos encerrar o escritório.', $item['texto']);
        self::assertStringContainsString('aguardando a sua decisão', $item['texto']);

        $this->postar('/master/notificacoes/exclusao_conta/' . $pedido->getId() . '/responder', ['texto' => 'Recebemos o pedido e vamos analisar.']);
        self::assertResponseIsSuccessful();
        self::assertSame('email', $this->json()['canal']);
        $envio = $this->conexao()->fetchAssociative("SELECT destinatario, tenant_id FROM comercial_envio WHERE origem = 'central_master:resposta' ORDER BY id DESC LIMIT 1");
        self::assertSame(['paula_' . $this->marca . '@exemplo.com', $this->cliente->getId()], [$envio['destinatario'], $envio['tenant_id']]);
    }

    #[TestDox('alerta de consumo: vem da trilha, do Sistema BlueJus, com o limiar e o uso, e não aceita resposta')]
    public function testAlertaDeConsumo(): void
    {
        static::getContainer()->get(PainelMasterRepository::class)->registrarAlertaDeConsumo((int) $this->cliente->getId(), 90, 92, 'critico');
        $id = (int) $this->conexao()->fetchOne('SELECT MAX(id) FROM audit_log WHERE action = ? AND tenant_id = ?', [PainelMasterRepository::ACAO_ALERTA_DE_CONSUMO, $this->cliente->getId()]);
        $this->entrarComoMaster();

        $item = $this->item($this->listar($this->marca), 'alerta_consumo:' . $id);

        self::assertNotNull($item);
        self::assertSame(['alerta', 'Alerta', 'Sistema BlueJus', 'Alerta de consumo', false], [$item['tipo'], $item['rotulo'], $item['de'], $item['assunto'], $item['podeResponder']]);
        self::assertSame(sprintf('Alerta de consumo: %s (Conta Nº %s) passou de 90%% do armazenamento. Uso atual: 92%%.', $this->cliente->getName(), str_pad((string) $this->cliente->getId(), 6, '0', \STR_PAD_LEFT)), $item['texto']);

        $this->postar('/master/notificacoes/alerta_consumo/' . $id . '/responder', ['texto' => 'Oi']);
        self::assertResponseStatusCodeSame(422);
    }

    /** @return array<string, mixed> */
    private function listar(string $q): array
    {
        $this->client->request('GET', '/master/notificacoes?' . http_build_query(['aba' => 'rec', 'q' => $q]));
        self::assertResponseIsSuccessful();

        return $this->json();
    }

    /**
     * @param array<string, mixed> $caixa
     *
     * @return array<string, mixed>|null
     */
    private function item(array $caixa, string $chave): ?array
    {
        return array_column($caixa['itens'], null, 'chave')[$chave] ?? null;
    }

    private function entrarComoMaster(): void
    {
        $master = $this->usuario(['ROLE_SUPER_ADMIN', 'ROLE_MASTER_PRIMARIO'], 'Master Extras');
        $this->em->persist(new UserTenant($master, $this->escritorioDoMaster));
        $this->em->flush();
        $this->logarComTenant($this->client, $master, $this->escritorioDoMaster);
    }

    /** @param array<string, mixed> $corpo */
    private function postar(string $url, array $corpo): void
    {
        $this->client->request('POST', $url, [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => 'TOKEN_' . CentralDeNotificacoesController::TOKEN], (string) json_encode($corpo));
    }

    /** @return array<string, mixed> */
    private function json(): array
    {
        return (array) json_decode((string) $this->client->getResponse()->getContent(), true);
    }

    private function escritorio(string $nome): Tenant
    {
        $tenant = (new Tenant())->setName($nome . ' ' . uniqid());
        $this->em->persist($tenant);
        $this->em->flush();

        return $tenant;
    }

    /** @param list<string> $papeis */
    private function usuario(array $papeis, string $nome, ?string $email = null): User
    {
        $user = (new User())->setEmail($email ?? 'extras_' . uniqid() . '@test.com')->setFullName($nome)->setRoles($papeis)->setIsActive(true);
        $user->setPassword(static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($user, 'senha123'));
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function conexao(): Connection
    {
        return static::getContainer()->get(Connection::class);
    }

    private function instalarTokensFalsos(): void
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
