<?php

declare(strict_types=1);

namespace App\Tests\Master\Functional;

use App\Controller\Tenant\TenantSelecaoController;
use App\Entity\Audit\AuditLog;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Master\Controller\ContasDoSistemaController;
use App\Master\Controller\PainelMasterDadosController;
use App\Master\Service\RegraDosMasters;
use App\Repository\TenantRepository;
use App\Tests\Factory\Auth\UserFactory;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;
use Zenstruck\Foundry\Test\Factories;

/**
 * Suspender e reativar conta pelo painel do Master (spec docs/specs/perfis-1.2-contas.md §4, parte 1:
 * C1, C2, C3, C5 na suspensão, C6, C7 no JSON e C9).
 *
 * Armazém de CSRF fixo ('TOKEN_<id>' sempre válido, padrão dos testes do painel): a recusa vem da
 * regra, não do token. O CSRF inválido e o token de outra conta têm teste próprio.
 */
#[CoversClass(ContasDoSistemaController::class)]
#[CoversClass(PainelMasterDadosController::class)]
#[CoversClass(TenantSelecaoController::class)]
final class ContasDoSistemaControllerTest extends JusPrimeWebTestCase
{
    use Factories;

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private User $master;
    private Tenant $contaDoMaster;
    private Tenant $alvo;
    private User $usuarioDoAlvo;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->instalarCsrfFixo();

        $this->master = $this->usuario('Farlei Master', ['ROLE_SUPER_ADMIN', 'ROLE_MASTER_PRIMARIO']);
        $this->contaDoMaster = $this->conta('Escritório do Master', $this->master);
        $this->vincular($this->master, $this->contaDoMaster, sistema: true);

        $titular = $this->usuario('Titular Comum', ['ROLE_USER']);
        $this->alvo = $this->conta('Conta Alvo', $titular);
        $this->vincular($titular, $this->alvo, sistema: true);
        $this->usuarioDoAlvo = $this->usuario('Secundária do Alvo', ['ROLE_USER']);
        $this->vincular($this->usuarioDoAlvo, $this->alvo, sistema: false);
    }

    #[TestDox('o Master suspende: JSON com a situação nova, isActive=false, excluidoEm nulo')]
    public function testSuspende(): void
    {
        $this->entrar($this->master, $this->contaDoMaster);

        $resposta = $this->postar('suspender', $this->alvo);

        self::assertResponseIsSuccessful();
        self::assertSame(['mensagem' => sprintf('Conta "%s" suspensa.', $this->alvo->getName()), 'conta' => ['id' => $this->alvo->getId(), 'situacao' => 'Suspensa', 'purgaEm' => null]], $resposta);
        $conta = $this->recarregar($this->alvo);
        self::assertFalse($conta->isActive());
        self::assertNull($conta->getExcluidoEm(), 'suspender não é excluir');
    }

    #[TestDox('suspender barra o usuário no request seguinte (seleção com a mensagem da C9) e reativar devolve o acesso')]
    public function testSuspenderBarraEReativarDevolve(): void
    {
        $this->entrar($this->master, $this->contaDoMaster);
        $this->postar('suspender', $this->alvo);
        self::assertResponseIsSuccessful();

        // Sessão "velha" do usuário, ainda apontando para a conta agora suspensa.
        $this->entrar($this->usuarioDoAlvo, $this->alvo);
        $mensagemC9 = sprintf('A conta do escritório %s está suspensa. Fale com o responsável pela conta.', $this->alvo->getName());
        $this->client->request('GET', '/expediente');
        self::assertResponseRedirects('/escritorio/selecionar');
        self::assertSame([$mensagemC9], $this->client->getRequest()->getSession()->getFlashBag()->peek('warning'), 'o aviso da barreira da conta (C9, C13 a)');
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-conta-suspensa]', $mensagemC9);
        self::assertSame(1, substr_count((string) $this->client->getResponse()->getContent(), $mensagemC9), 'a página mostra o alerta e o flash igual sai: o texto aparece uma vez');
        self::assertStringNotContainsString('Você ainda não faz parte de nenhum escritório', (string) $this->client->getResponse()->getContent());

        $this->entrar($this->master, $this->contaDoMaster);
        $resposta = $this->postar('reativar', $this->alvo);
        self::assertResponseIsSuccessful();
        self::assertSame(['mensagem' => sprintf('Conta "%s" reativada.', $this->alvo->getName()), 'conta' => ['id' => $this->alvo->getId(), 'situacao' => 'Ativa', 'purgaEm' => null]], $resposta);
        self::assertTrue($this->recarregar($this->alvo)->isActive());

        $this->client->loginUser($this->usuarioDoAlvo);
        $this->marcarTermosAceitos($this->client);
        $this->client->request('GET', '/escritorio/selecionar');
        self::assertResponseRedirects('/expediente', message: 'com o único vínculo ativo de novo, a seleção entra direto');
    }

    #[TestDox('a trilha grava com o tenant da CONTA ALVO e mostra "Conta suspensa" / "Conta reativada" com "Ação confirmada pelo Master" (C6)')]
    public function testTrilhaDeAuditoria(): void
    {
        $this->entrar($this->master, $this->contaDoMaster);
        $this->postar('suspender', $this->alvo);
        $this->postar('reativar', $this->alvo);

        $linhas = array_values(array_filter(
            $this->em->getRepository(AuditLog::class)->findBy(['entityClass' => Tenant::class, 'entityId' => (string) $this->alvo->getId(), 'action' => 'update']),
            static fn (AuditLog $a): bool => str_contains((string) json_encode($a->getChanges()), 'isActive'),
        ));
        self::assertCount(2, $linhas);
        foreach ($linhas as $linha) {
            self::assertSame($this->alvo->getId(), $linha->getTenantId(), 'grava com a conta alvo, não com o escritório da sessão do Master');
        }

        $this->client->request('GET', '/master/dados/auditoria');
        self::assertResponseIsSuccessful();
        $registros = json_decode((string) $this->client->getResponse()->getContent(), true)['registros'];
        $daConta = array_values(array_filter($registros, fn (array $r): bool => $r['contaId'] === $this->alvo->getId() && str_starts_with($r['acao'], 'Conta ')));
        self::assertSame(['Conta reativada', 'Conta suspensa'], array_column($daConta, 'acao'), 'mais recente primeiro');
        foreach ($daConta as $r) {
            self::assertSame($this->alvo->getName(), $r['conta']);
            self::assertSame('Farlei Master', $r['quem']);
            self::assertSame('Ação confirmada pelo Master', $r['detalhe']);
        }
    }

    #[TestDox('conta criada por um Master: suspensão recusada no servidor (Q2), nada muda')]
    public function testRecusaSuspenderContaDoMaster(): void
    {
        $this->entrar($this->master, $this->contaDoMaster);

        $resposta = $this->postar('suspender', $this->contaDoMaster);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(['erro' => RegraDosMasters::RECUSA_SUSPENDER_CONTA_DO_MASTER], $resposta);
        self::assertTrue($this->recarregar($this->contaDoMaster)->isActive());
    }

    #[TestDox('conta SEM criador registrado e com Master de vínculo ativo é conta do Master: JSON e servidor concordam (C5)')]
    public function testContaSemCriadorComMasterVinculado(): void
    {
        $semCriador = (new Tenant())->setName('Sem criador ' . uniqid());
        $this->em->persist($semCriador);
        $this->em->flush();
        $this->vincular($this->master, $semCriador, sistema: false);
        $semMaster = (new Tenant())->setName('Sem criador nem Master ' . uniqid());
        $this->em->persist($semMaster);
        $this->em->flush();
        $this->entrar($this->master, $this->contaDoMaster);

        $this->client->request('GET', '/master/dados/contas');
        $contas = array_column(json_decode((string) $this->client->getResponse()->getContent(), true)['contas'], null, 'id');
        self::assertTrue($contas[$semCriador->getId()]['ehDoMaster']);
        self::assertFalse($contas[$semMaster->getId()]['ehDoMaster']);

        self::assertSame(['erro' => RegraDosMasters::RECUSA_SUSPENDER_CONTA_DO_MASTER], $this->postar('suspender', $semCriador));
        self::assertResponseStatusCodeSame(422);
        self::assertTrue($this->recarregar($semCriador)->isActive());

        $this->postar('suspender', $semMaster);
        self::assertResponseIsSuccessful();
    }

    #[TestDox('recusas de situação (422): suspender conta suspensa ou excluída, reativar conta ativa, excluir conta já excluída; conta inexistente: 404')]
    public function testRecusasDeSituacao(): void
    {
        $this->entrar($this->master, $this->contaDoMaster);

        self::assertSame(['erro' => 'Esta conta já está ativa.'], $this->postar('reativar', $this->alvo));
        self::assertResponseStatusCodeSame(422);

        $this->postar('suspender', $this->alvo);
        self::assertSame(['erro' => 'Esta conta já está suspensa.'], $this->postar('suspender', $this->alvo));
        self::assertResponseStatusCodeSame(422);

        $excluida = $this->recarregar($this->alvo)->setExcluidoEm(new \DateTimeImmutable());
        $this->em->flush();
        self::assertSame(['erro' => 'Esta conta foi excluída e não pode ser suspensa.'], $this->postar('suspender', $excluida));
        self::assertResponseStatusCodeSame(422);
        self::assertSame(['erro' => 'Esta conta já foi excluída.'], $this->postar('excluir', $excluida, 'EXCLUIR'));
        self::assertResponseStatusCodeSame(422);
        self::assertFalse($this->recarregar($this->alvo)->isActive());

        $this->client->request('POST', '/master/contas/99999999/suspender', ['_token' => 'TOKEN_master_conta_99999999']);
        self::assertResponseStatusCodeSame(404);
        self::assertSame(['erro' => 'Conta não encontrada.'], json_decode((string) $this->client->getResponse()->getContent(), true));
    }

    #[TestDox('primário e secundário de escritório recebem 403 nas duas rotas; nada muda')]
    public function testNaoMasterRecebe403(): void
    {
        $primario = $this->usuario('Primário Comum', ['ROLE_USER']);
        $this->vincular($primario, $this->alvo, sistema: true);

        foreach ([$primario, $this->usuarioDoAlvo] as $quem) {
            $this->entrar($quem, $this->alvo);
            foreach (['suspender', 'reativar'] as $acao) {
                $this->client->request('POST', '/master/contas/' . $this->alvo->getId() . '/' . $acao, ['_token' => 'TOKEN_master_conta_' . $this->alvo->getId()]);
                self::assertResponseStatusCodeSame(403);
            }
        }

        self::assertTrue($this->recarregar($this->alvo)->isActive());
    }

    #[TestDox('CSRF inválido ou de OUTRA conta: 403 e nada muda')]
    public function testCsrf(): void
    {
        $this->entrar($this->master, $this->contaDoMaster);

        $this->client->request('POST', '/master/contas/' . $this->alvo->getId() . '/suspender', ['_token' => 'errado']);
        self::assertResponseStatusCodeSame(403);

        $this->client->request('POST', '/master/contas/' . $this->alvo->getId() . '/suspender', ['_token' => 'TOKEN_master_conta_' . $this->contaDoMaster->getId()]);
        self::assertResponseStatusCodeSame(403);
        self::assertSame(['erro' => 'Sessão expirada. Recarregue a página.'], json_decode((string) $this->client->getResponse()->getContent(), true));

        self::assertTrue($this->recarregar($this->alvo)->isActive());
    }

    #[TestDox('GET está fora: as ações só existem em POST')]
    public function testSoPost(): void
    {
        $this->entrar($this->master, $this->contaDoMaster);

        $this->client->request('GET', '/master/contas/' . $this->alvo->getId() . '/suspender');

        self::assertResponseStatusCodeSame(405);
    }

    #[TestDox('/master/dados/contas leva ehDoMaster (C7), o token CSRF de cada conta e a carência da purga')]
    public function testListaDeContas(): void
    {
        $this->entrar($this->master, $this->contaDoMaster);

        $this->client->request('GET', '/master/dados/contas');

        self::assertResponseIsSuccessful();
        $contas = array_column(json_decode((string) $this->client->getResponse()->getContent(), true)['contas'], null, 'id');
        self::assertTrue($contas[$this->contaDoMaster->getId()]['ehDoMaster']);
        self::assertFalse($contas[$this->alvo->getId()]['ehDoMaster']);
        self::assertSame('Ativa', $contas[$this->alvo->getId()]['situacao']);
        self::assertIsString($contas[$this->alvo->getId()]['token']);
        self::assertNotSame('', $contas[$this->alvo->getId()]['token']);
        self::assertSame(
            (int) static::getContainer()->getParameter('tenant_carencia_purga_dias'),
            json_decode((string) $this->client->getResponse()->getContent(), true)['carenciaPurgaDias'],
            'a carência configurada vai para o texto do modal de excluir',
        );
    }

    #[TestDox('excluir (C4): JSON "Em quarentena" com a purga em 365 dias, a equipe é barrada, a conta vai para "Em quarentena" e reativar devolve o acesso (C4b)')]
    public function testExcluirEReativarDaQuarentena(): void
    {
        $this->entrar($this->master, $this->contaDoMaster);

        $resposta = $this->postar('excluir', $this->alvo, 'EXCLUIR');

        self::assertResponseIsSuccessful();
        self::assertSame('Em quarentena', $resposta['conta']['situacao']);
        self::assertSame((new \DateTimeImmutable('+365 days'))->format('Y-m-d'), $resposta['conta']['purgaEm']);
        $conta = $this->recarregar($this->alvo);
        self::assertFalse($conta->isActive());
        self::assertNotNull($conta->getExcluidoEm());

        $this->client->request('GET', '/master/dados/contas');
        $contas = array_column(json_decode((string) $this->client->getResponse()->getContent(), true)['contas'], null, 'id');
        self::assertSame('Em quarentena', $contas[$this->alvo->getId()]['situacao'], 'a lista traz a conta só como "Em quarentena"');
        self::assertNotNull($contas[$this->alvo->getId()]['purgaEm']);

        // A equipe é barrada com a mensagem de conta indisponível (não a de conta suspensa).
        $this->entrar($this->usuarioDoAlvo, $this->alvo);
        $this->client->request('GET', '/expediente');
        self::assertResponseRedirects('/escritorio/selecionar');
        self::assertSame(['Seu acesso a este escritório não está mais disponível. Selecione outro escritório.'], $this->client->getRequest()->getSession()->getFlashBag()->peek('warning'));
        $this->client->followRedirect();
        self::assertSelectorNotExists('[data-conta-suspensa]');

        $this->entrar($this->master, $this->contaDoMaster);
        $reativada = $this->postar('reativar', $this->alvo);
        self::assertResponseIsSuccessful();
        self::assertSame(['id' => $this->alvo->getId(), 'situacao' => 'Ativa', 'purgaEm' => null], $reativada['conta']);
        $conta = $this->recarregar($this->alvo);
        self::assertTrue($conta->isActive());
        self::assertNull($conta->getExcluidoEm());

        $this->client->loginUser($this->usuarioDoAlvo);
        $this->marcarTermosAceitos($this->client);
        $this->client->request('GET', '/escritorio/selecionar');
        self::assertResponseRedirects('/expediente', message: 'recuperada da quarentena, a equipe volta a entrar');
    }

    #[TestDox('excluir uma conta SUSPENSA também grava excluidoEm')]
    public function testExcluirContaSuspensa(): void
    {
        $this->entrar($this->master, $this->contaDoMaster);
        $this->postar('suspender', $this->alvo);

        $this->postar('excluir', $this->alvo, 'EXCLUIR');

        self::assertResponseIsSuccessful();
        self::assertNotNull($this->recarregar($this->alvo)->getExcluidoEm());
    }

    #[TestDox('trilha da exclusão e da recuperação: tenant da conta alvo, "Conta excluída" e "Exclusão de conta cancelada", com "Ação confirmada pelo Master"')]
    public function testTrilhaDaExclusao(): void
    {
        $this->entrar($this->master, $this->contaDoMaster);
        $this->postar('excluir', $this->alvo, 'EXCLUIR');
        $this->postar('reativar', $this->alvo);

        $linhas = array_values(array_filter(
            $this->em->getRepository(AuditLog::class)->findBy(['entityClass' => Tenant::class, 'entityId' => (string) $this->alvo->getId(), 'action' => 'update']),
            static fn (AuditLog $a): bool => str_contains((string) json_encode($a->getChanges()), 'excluidoEm'),
        ));
        self::assertCount(2, $linhas);
        foreach ($linhas as $linha) {
            self::assertSame($this->alvo->getId(), $linha->getTenantId());
        }

        $this->client->request('GET', '/master/dados/auditoria');
        $registros = json_decode((string) $this->client->getResponse()->getContent(), true)['registros'];
        $daConta = array_values(array_filter($registros, fn (array $r): bool => $r['contaId'] === $this->alvo->getId() && in_array($r['acao'], ['Conta excluída', 'Exclusão de conta cancelada'], true)));
        self::assertSame(['Exclusão de conta cancelada', 'Conta excluída'], array_column($daConta, 'acao'));
        self::assertSame(['Ação confirmada pelo Master', 'Ação confirmada pelo Master'], array_column($daConta, 'detalhe'));
    }

    #[TestDox('excluir: sem EXCLUIR 422, conta do Master 422, CSRF errado 403, não Master 403, conta inexistente 404; nada muda')]
    public function testRecusasDaExclusao(): void
    {
        $this->entrar($this->master, $this->contaDoMaster);

        self::assertSame(['erro' => 'Digite EXCLUIR para confirmar a exclusão.'], $this->postar('excluir', $this->alvo, 'excluir'));
        self::assertResponseStatusCodeSame(422);
        self::assertSame(['erro' => 'Digite EXCLUIR para confirmar a exclusão.'], $this->postar('excluir', $this->alvo));
        self::assertResponseStatusCodeSame(422);
        self::assertSame(['erro' => RegraDosMasters::RECUSA_EXCLUIR_CONTA_DO_MASTER], $this->postar('excluir', $this->contaDoMaster, 'EXCLUIR'));
        self::assertResponseStatusCodeSame(422);

        $this->client->request('POST', '/master/contas/' . $this->alvo->getId() . '/excluir', ['_token' => 'TOKEN_master_conta_' . $this->contaDoMaster->getId(), 'confirmacao' => 'EXCLUIR']);
        self::assertResponseStatusCodeSame(403);
        $this->client->request('POST', '/master/contas/99999999/excluir', ['_token' => 'TOKEN_master_conta_99999999', 'confirmacao' => 'EXCLUIR']);
        self::assertResponseStatusCodeSame(404);

        $this->entrar($this->usuarioDoAlvo, $this->alvo);
        $this->postar('excluir', $this->alvo, 'EXCLUIR');
        self::assertResponseStatusCodeSame(403);

        foreach ([$this->alvo, $this->contaDoMaster] as $conta) {
            $recarregada = $this->recarregar($conta);
            self::assertTrue($recarregada->isActive());
            self::assertNull($recarregada->getExcluidoEm());
        }
    }

    #[TestDox('a purga (TenantRepository::encontrarPurgaveis) pega a conta excluída pelo Master só depois da carência')]
    public function testPurgaPegaAContaDepoisDaCarencia(): void
    {
        $this->entrar($this->master, $this->contaDoMaster);
        $this->postar('excluir', $this->alvo, 'EXCLUIR');
        self::assertResponseIsSuccessful();

        $repositorio = static::getContainer()->get(TenantRepository::class);
        $carencia = (int) static::getContainer()->getParameter('tenant_carencia_purga_dias');
        $limite = (new \DateTimeImmutable())->modify(sprintf('-%d days', $carencia));
        $ids = fn (): array => array_map(static fn (Tenant $t): ?int => $t->getId(), $repositorio->encontrarPurgaveis($limite));
        self::assertNotContains($this->alvo->getId(), $ids(), 'dentro da carência: fica na quarentena');

        $this->recarregar($this->alvo)->setExcluidoEm((new \DateTimeImmutable())->modify(sprintf('-%d days', $carencia + 1)));
        $this->em->flush();
        self::assertContains($this->alvo->getId(), $ids(), 'carência vencida: a purga pega a conta');
    }

    /** @return array<string, mixed> */
    private function postar(string $acao, Tenant $conta, ?string $confirmacao = null): array
    {
        $corpo = ['_token' => 'TOKEN_master_conta_' . $conta->getId()] + ($confirmacao !== null ? ['confirmacao' => $confirmacao] : []);
        $this->client->request('POST', '/master/contas/' . $conta->getId() . '/' . $acao, $corpo);

        return (array) json_decode((string) $this->client->getResponse()->getContent(), true);
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

    private function recarregar(Tenant $conta): Tenant
    {
        $this->em->clear();
        $recarregada = $this->em->find(Tenant::class, $conta->getId());
        self::assertNotNull($recarregada);

        return $recarregada;
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
