<?php

declare(strict_types=1);

namespace App\Tests\Master\Functional;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Master\Controller\PainelMasterDadosController;
use App\Master\UseCase\ConsultarPedidosDeExclusaoUseCase;
use App\Tenant\Entity\PedidoDeExclusaoDeConta;
use App\Tests\Factory\Auth\UserFactory;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;
use Zenstruck\Foundry\Test\Factories;

/**
 * Bloco "Pedidos de exclusão de conta" do painel do Master (desenho 05 - Perfis 1.2, linha 148): os pendentes
 * de todas as contas chegam em /master/dados/contas (`pedidosExclusao`), só para o Master. Reativar e excluir
 * pelas rotas do menu da conta decidem o pedido, e ele some da lista.
 *
 * Armazém de CSRF fixo ('TOKEN_<id>' sempre válido, padrão dos testes do painel).
 */
#[CoversClass(PainelMasterDadosController::class)]
#[CoversClass(ConsultarPedidosDeExclusaoUseCase::class)]
final class PedidosDeExclusaoNoPainelTest extends JusPrimeWebTestCase
{
    use Factories;

    private const MOTIVO = 'Vou encerrar o escritório · fechamos em dezembro';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private User $master;
    private Tenant $contaDoMaster;
    private Tenant $alvo;
    private User $primarioDoAlvo;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->instalarCsrfFixo();

        $this->master = $this->usuario('Farlei Master', ['ROLE_SUPER_ADMIN', 'ROLE_MASTER_PRIMARIO']);
        $this->contaDoMaster = $this->conta('Escritório do Master', $this->master);
        $this->vincular($this->master, $this->contaDoMaster, sistema: true);

        $this->primarioDoAlvo = $this->usuario('Ana Primária', ['ROLE_USER']);
        $this->alvo = $this->conta('Silva Advogados', $this->primarioDoAlvo);
        $this->vincular($this->primarioDoAlvo, $this->alvo, sistema: true);
    }

    #[TestDox('o Master recebe o pedido pendente da conta do cliente (com o escritório dele na sessão): escritório, quem pediu, data, motivo e o token da conta')]
    public function testMasterVeOPedido(): void
    {
        $criadoEm = $this->pedirExclusao($this->alvo, $this->primarioDoAlvo);
        $this->entrar($this->master, $this->contaDoMaster);

        $pedido = $this->pedidoDaLista($this->alvo);

        self::assertNotNull($pedido, 'o pedido do cliente aparece mesmo com o escritório do Master na sessão');
        self::assertSame($this->alvo->getName(), $pedido['escritorio']);
        self::assertSame('Ana Primária', $pedido['pedidoPorNome']);
        self::assertSame($this->primarioDoAlvo->getEmail(), $pedido['pedidoPorEmail']);
        self::assertSame(self::MOTIVO, $pedido['motivo']);
        self::assertSame($criadoEm->getTimestamp(), (new \DateTimeImmutable($pedido['criadoEm']))->getTimestamp());
        self::assertIsString($pedido['token']);
        self::assertNotSame('', $pedido['token'], 'o token da conta, o mesmo que Reativar e Excluir conferem');
    }

    #[TestDox('sem pedido pendente, a conta não entra no bloco (o painel esconde o bloco com a lista vazia)')]
    public function testSemPedido(): void
    {
        $this->entrar($this->master, $this->contaDoMaster);

        self::assertNull($this->pedidoDaLista($this->alvo));
        self::assertIsArray($this->dadosDasContas()['pedidosExclusao'], 'a chave existe mesmo sem pedidos');
    }

    #[TestDox('primário SEM pedido pendente (nenhuma barreira de conta no caminho) recebe exatamente 403: é o IsGranted')]
    public function testPrimarioSemPedidoRecebe403(): void
    {
        $this->entrar($this->primarioDoAlvo, $this->alvo);

        $this->client->request('GET', '/master/dados/contas');

        self::assertResponseStatusCodeSame(403);
    }

    #[TestDox('a conta com pedido pendente vem com pedidoPendente (a tabela mostra "Suspensa a pedido"); decidido, a marca sai')]
    public function testMarcaDaContaComPedido(): void
    {
        $this->pedirExclusao($this->alvo, $this->primarioDoAlvo);
        $this->entrar($this->master, $this->contaDoMaster);

        $conta = $this->contaDaLista($this->alvo);
        self::assertTrue($conta['pedidoPendente']);
        self::assertSame('Ativa', $conta['situacao'], 'a situação gravada segue "Ativa"; o rótulo "Suspensa a pedido" é do painel');
        self::assertFalse($this->contaDaLista($this->contaDoMaster)['pedidoPendente']);

        $this->client->request('POST', '/master/contas/' . $this->alvo->getId() . '/reativar', ['_token' => 'TOKEN_master_conta_' . $this->alvo->getId()]);
        self::assertResponseIsSuccessful();

        self::assertFalse($this->contaDaLista($this->alvo)['pedidoPendente']);
    }

    #[TestDox('POST recusado não decide o pedido: CSRF errado no reativar (403), confirmação diferente de EXCLUIR (422) e reativar por quem não é Master (403); o pedido continua na lista')]
    public function testRecusaMantemNaLista(): void
    {
        $this->pedirExclusao($this->alvo, $this->primarioDoAlvo);
        $outroPrimario = $this->usuario('Bruno Primário', ['ROLE_USER']);
        $outraConta = $this->conta('Outra Conta', $outroPrimario);
        $this->vincular($outroPrimario, $outraConta, sistema: true);

        $this->entrar($this->master, $this->contaDoMaster);
        $this->client->request('POST', '/master/contas/' . $this->alvo->getId() . '/reativar', ['_token' => 'errado']);
        self::assertResponseStatusCodeSame(403);
        self::assertNotNull($this->pedidoDaLista($this->alvo), 'CSRF errado no reativar');

        $this->client->request('POST', '/master/contas/' . $this->alvo->getId() . '/excluir', ['_token' => 'TOKEN_master_conta_' . $this->alvo->getId(), 'confirmacao' => 'excluir']);
        self::assertResponseStatusCodeSame(422);
        self::assertNotNull($this->pedidoDaLista($this->alvo), 'confirmação diferente de EXCLUIR');
        self::assertTrue($this->contaDaLista($this->alvo)['pedidoPendente']);

        $this->entrar($outroPrimario, $outraConta);
        $this->client->request('POST', '/master/contas/' . $this->alvo->getId() . '/reativar', ['_token' => 'TOKEN_master_conta_' . $this->alvo->getId()]);
        self::assertResponseStatusCodeSame(403);

        $this->entrar($this->master, $this->contaDoMaster);
        self::assertNotNull($this->pedidoDaLista($this->alvo), 'reativar recusado');
        self::assertTrue($this->contaDaLista($this->alvo)['pedidoPendente']);
    }

    #[TestDox('quem não é Master não recebe a lista: primário de outra conta 403, primário da conta que pediu 403 ou redirecionado, sem o motivo no corpo')]
    public function testNaoMasterNaoRecebe(): void
    {
        $this->pedirExclusao($this->alvo, $this->primarioDoAlvo);
        $outroPrimario = $this->usuario('Bruno Primário', ['ROLE_USER']);
        $outraConta = $this->conta('Outra Conta', $outroPrimario);
        $this->vincular($outroPrimario, $outraConta, sistema: true);

        $this->entrar($outroPrimario, $outraConta);
        $this->client->request('GET', '/master/dados/contas');
        self::assertResponseStatusCodeSame(403);
        self::assertStringNotContainsString(self::MOTIVO, (string) $this->client->getResponse()->getContent());

        $this->entrar($this->primarioDoAlvo, $this->alvo);
        $this->client->request('GET', '/master/dados/contas');
        $status = $this->client->getResponse()->getStatusCode();
        self::assertTrue($status === 403 || $this->client->getResponse()->isRedirect(), 'recusado (403) ou barrado pela conta suspensa a pedido (redirecionamento), nunca 200; veio ' . $status);
        self::assertStringNotContainsString(self::MOTIVO, (string) $this->client->getResponse()->getContent());
    }

    #[TestDox('reativar pela rota do menu da conta, com o token que veio no pedido, decide o pedido: ele não volta na lista')]
    public function testReativarTiraDaLista(): void
    {
        $this->pedirExclusao($this->alvo, $this->primarioDoAlvo);
        $this->entrar($this->master, $this->contaDoMaster);
        $pedido = $this->pedidoDaLista($this->alvo);
        self::assertNotNull($pedido);

        $this->client->request('POST', '/master/contas/' . $this->alvo->getId() . '/reativar', ['_token' => $pedido['token']]);
        self::assertResponseIsSuccessful();

        self::assertNull($this->pedidoDaLista($this->alvo));
    }

    #[TestDox('excluir (quarentena) pela rota do menu da conta decide o pedido: ele não volta na lista')]
    public function testExcluirTiraDaLista(): void
    {
        $this->pedirExclusao($this->alvo, $this->primarioDoAlvo);
        $this->entrar($this->master, $this->contaDoMaster);

        $this->client->request('POST', '/master/contas/' . $this->alvo->getId() . '/excluir', ['_token' => 'TOKEN_master_conta_' . $this->alvo->getId(), 'confirmacao' => 'EXCLUIR']);
        self::assertResponseIsSuccessful();

        self::assertNull($this->pedidoDaLista($this->alvo));
    }

    #[TestDox('suspender pelo menu não decide o pedido: ele continua na lista')]
    public function testSuspenderMantemNaLista(): void
    {
        $this->pedirExclusao($this->alvo, $this->primarioDoAlvo);
        $this->entrar($this->master, $this->contaDoMaster);

        $this->client->request('POST', '/master/contas/' . $this->alvo->getId() . '/suspender', ['_token' => 'TOKEN_master_conta_' . $this->alvo->getId()]);
        self::assertResponseIsSuccessful();

        self::assertNotNull($this->pedidoDaLista($this->alvo));
    }

    /** @return array<string, mixed> */
    private function dadosDasContas(): array
    {
        $this->client->request('GET', '/master/dados/contas');
        self::assertResponseIsSuccessful();

        return (array) json_decode((string) $this->client->getResponse()->getContent(), true);
    }

    /** @return array<string, mixed> */
    private function contaDaLista(Tenant $conta): array
    {
        $contas = array_column($this->dadosDasContas()['contas'], null, 'id');
        self::assertArrayHasKey($conta->getId(), $contas);

        return $contas[$conta->getId()];
    }

    /** @return array<string, mixed>|null */
    private function pedidoDaLista(Tenant $conta): ?array
    {
        $doAlvo = array_values(array_filter($this->dadosDasContas()['pedidosExclusao'], static fn (array $p): bool => $p['contaId'] === $conta->getId()));
        self::assertLessThanOrEqual(1, \count($doAlvo), 'um pendente por conta');

        return $doAlvo[0] ?? null;
    }

    private function pedirExclusao(Tenant $conta, User $primario): \DateTimeImmutable
    {
        $criadoEm = new \DateTimeImmutable('-2 hours');
        $this->em->persist(new PedidoDeExclusaoDeConta($conta, $primario, self::MOTIVO, $criadoEm));
        $this->em->flush();

        return $criadoEm;
    }

    private function entrar(User $user, Tenant $tenant): void
    {
        $this->logarComTenant($this->client, $user, $tenant);
    }

    /** @param list<string> $papeis */
    private function usuario(string $nome, array $papeis): User
    {
        return UserFactory::createOne(['fullName' => $nome, 'roles' => $papeis, 'isActive' => true])->_real();
    }

    private function conta(string $nome, User $criador): Tenant
    {
        $conta = (new Tenant())->setName($nome . ' ' . uniqid())->setCriadoPor($criador);
        $this->em->persist($conta);
        $this->em->flush();

        return $conta;
    }

    private function vincular(User $user, Tenant $tenant, bool $sistema): void
    {
        $perfil = (new TenantRole())->setTenant($tenant)->setName($sistema ? 'Administrador do Escritório' : 'Advogado (a)')->setIsSystem($sistema);
        $this->em->persist($perfil);
        $this->em->persist((new UserTenant($user, $tenant))->setTenantRole($perfil));
        $this->em->flush();
    }

    private function instalarCsrfFixo(): void
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
