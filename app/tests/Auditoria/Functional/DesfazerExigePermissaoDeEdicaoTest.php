<?php

declare(strict_types=1);

namespace App\Tests\Auditoria\Functional;

use App\Auditoria\UseCase\DesfazerAlteracaoAuditLogUseCase;
use App\Controller\AuditLogController;
use App\Entity\Audit\AuditLog;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Permission\Permission;
use App\Agenda\Entity\Evento;
use App\Entity\Agenda\LegendaCor;
use App\Entity\ServiceDesk\Chamado;
use App\Entity\ServiceDesk\ChamadoInteracao;
use App\Entity\Tenant\Cargo;
use App\Entity\Tenant\Lotacao;
use App\Entity\Tenant\Sede;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Entity\Tenant\TenantRolePermission;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PrioridadePasta;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

/**
 * Achado de auditoria interna F19 (spec `docs/specs/seguranca-b-ponto.md` §1.3): desfazer uma alteração de Sede, Cargo,
 * Lotação, Chamado ou interação do chamado exige, além de `admin.audit.view`, a permissão da tela que edita a entidade.
 * Evento segue a tela `agenda_editar`: `admin.users.manage` ou ser o criador. Sem isso, 403 e nada muda. A LegendaCor
 * não se desfaz: nenhuma tela a edita. Meta e pasta seguem as guardas por item da tela (`DesfazerExigeAGuardaDaTelaTest`).
 */
#[CoversClass(AuditLogController::class)]
#[CoversClass(DesfazerAlteracaoAuditLogUseCase::class)]
final class DesfazerExigePermissaoDeEdicaoTest extends JusPrimeWebTestCase
{
    private const SO_AUDITORIA = ['admin.audit.view'];

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Tenant $tenant;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        $this->tenant = new Tenant();
        $this->tenant->setName('Desfazer F19 ' . uniqid());
        $this->em->persist($this->tenant);
        $this->em->flush();

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

    /** @param list<string> $permissoes */
    private function entrarCom(array $permissoes): User
    {
        $user = new User();
        $user->setEmail('f19_' . uniqid() . '@test.com');
        $user->setFullName('Auditora F19');
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setPassword('x');
        $this->em->persist($user);

        $role = new TenantRole();
        $role->setTenant($this->tenant);
        $role->setName('Auditoria ' . uniqid());
        $role->setIsSystem(false);
        $this->em->persist($role);

        foreach ($permissoes as $codigo) {
            $perm = $this->em->getRepository(Permission::class)->findOneBy(['code' => $codigo]);
            if ($perm === null) {
                $perm = new Permission();
                $perm->setCode($codigo);
                $perm->setDescription($codigo);
                $perm->setGroup(explode('.', $codigo)[0]);
                $this->em->persist($perm);
            }
            $ligacao = new TenantRolePermission();
            $ligacao->setTenantRole($role);
            $ligacao->setPermission($perm);
            $this->em->persist($ligacao);
            $role->getTenantRolePermissions()->add($ligacao);
        }

        $vinculo = new UserTenant($user, $this->tenant);
        $vinculo->setTenantRole($role);
        $this->em->persist($vinculo);
        $this->em->flush();

        $this->logarComTenant($this->client, $user, $this->tenant);

        return $user;
    }

    /**
     * Cria a entidade já com o valor "novo" e a linha de auditoria que o leva de volta ao anterior.
     *
     * @return array{0: int, 1: int} [id da entidade, id da linha de auditoria]
     */
    private function cenario(string $classe): array
    {
        if ($classe === Sede::class) {
            $entidade = new Sede();
            $entidade->setNome('Sede F19');
            $entidade->setLatitude('-10.00000000');
            $entidade->setLongitude('-48.06479935');
            $entidade->setRaioPermitido(100);
            $entidade->setTimezone('America/Sao_Paulo');
            $entidade->setTenant($this->tenant);
            $diff = ['latitude' => ['from' => '-15.81100086', 'to' => '-10.00000000']];
        } elseif ($classe === Chamado::class) {
            $entidade = $this->chamado('Titulo novo');
            $diff = ['titulo' => ['from' => 'Titulo anterior', 'to' => 'Titulo novo']];
        } elseif ($classe === ChamadoInteracao::class) {
            $entidade = new ChamadoInteracao();
            $entidade->setChamado($this->chamado('Chamado da interação'));
            $entidade->setAutor($this->solicitante());
            $entidade->setMensagem('Mensagem nova');
            $diff = ['mensagem' => ['from' => 'Mensagem anterior', 'to' => 'Mensagem nova']];
        } else {
            $entidade = $classe === Cargo::class ? new Cargo() : new Lotacao();
            $entidade->setNome('Nome novo');
            $entidade->setTenant($this->tenant);
            $diff = ['nome' => ['from' => 'Nome anterior', 'to' => 'Nome novo']];
        }
        $this->em->persist($entidade);
        $this->em->flush();

        return [(int) $entidade->getId(), $this->criarLog($classe, (int) $entidade->getId(), $diff)];
    }

    private function chamado(string $titulo): Chamado
    {
        $chamado = new Chamado();
        $chamado->setTitulo($titulo);
        $chamado->setDescricao('Descrição do chamado');
        $chamado->setSolicitante($this->solicitante());
        $chamado->setTenant($this->tenant);
        $this->em->persist($chamado);

        return $chamado;
    }

    /** @return array{0: int, 1: int} [id do evento, id da linha de auditoria] */
    private function cenarioEvento(User $criador): array
    {
        $evento = new Evento();
        $evento->setTitulo('Titulo novo');
        $evento->setTenant($this->tenant);
        $evento->setCriador($criador);
        $evento->setDataInicio(new \DateTimeImmutable('2026-11-10 10:00'));
        $evento->setDataFim(new \DateTimeImmutable('2026-11-10 11:00'));
        $evento->setVisibilidade(Evento::VISIBILIDADE_TODOS);
        $evento->setStatus(Evento::STATUS_AGENDADO);
        $this->em->persist($evento);
        $this->em->flush();
        $id = (int) $evento->getId();

        return [$id, $this->criarLog(Evento::class, $id, ['titulo' => ['from' => 'Titulo anterior', 'to' => 'Titulo novo']])];
    }

    private function solicitante(): User
    {
        $user = new User();
        $user->setEmail('f19_solicitante_' . uniqid() . '@test.com');
        $user->setFullName('Solicitante F19');
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setPassword('x');
        $this->em->persist($user);
        $this->em->persist(new UserTenant($user, $this->tenant));

        return $user;
    }

    /** @param array<string, array{from: mixed, to: mixed}> $diff */
    private function criarLog(string $classe, int $entityId, array $diff): int
    {
        $log = (new AuditLog())
            ->setAction('update')
            ->setEntityClass($classe)
            ->setEntityId((string) $entityId)
            ->setChanges(['diff' => ['changes' => $diff], 'context' => []])
            ->setTenantId($this->tenant->getId());
        $this->em->persist($log);
        $this->em->flush();

        return (int) $log->getId();
    }

    private function desfazer(int $logId): void
    {
        $this->em->clear();
        $this->client->request('POST', "/auditoria/{$logId}/desfazer", ['_token' => 'TOKEN_audit_desfazer_' . $logId]);
    }

    private function valorAtual(string $classe, int $id): string
    {
        [$tabela, $coluna] = match ($classe) {
            Sede::class  => ['sede', 'latitude'],
            Chamado::class => ['chamado', 'titulo'],
            ChamadoInteracao::class => ['chamado_interacao', 'mensagem'],
            Evento::class => ['evento', 'titulo'],
            Cargo::class => ['cargo', 'nome'],
            default      => ['lotacao', 'nome'],
        };

        return (string) $this->em->getConnection()->fetchOne("SELECT {$coluna} FROM {$tabela} WHERE id = ?", [$id]);
    }

    /** @return iterable<string, array{string, string, string, string}> */
    public static function entidades(): iterable
    {
        yield 'Sede' => [Sede::class, 'admin.ponto.manage', '-10.00000000', '-15.81100086'];
        yield 'Cargo' => [Cargo::class, 'admin.tenant.settings.manage', 'Nome novo', 'Nome anterior'];
        yield 'Lotação' => [Lotacao::class, 'admin.tenant.settings.manage', 'Nome novo', 'Nome anterior'];
        yield 'Chamado' => [Chamado::class, 'admin.servicedesk.manage', 'Titulo novo', 'Titulo anterior'];
        yield 'Interação do chamado' => [ChamadoInteracao::class, 'admin.servicedesk.manage', 'Mensagem nova', 'Mensagem anterior'];
    }

    #[DataProvider('entidades')]
    #[TestDox('$classe: só com admin.audit.view o desfazer dá 403 e nada muda')]
    public function testSoAuditoriaRecebe403(string $classe, string $permissao, string $atual, string $anterior): void
    {
        [$id, $logId] = $this->cenario($classe);
        $this->entrarCom(self::SO_AUDITORIA);

        $this->desfazer($logId);

        self::assertResponseStatusCodeSame(403);
        self::assertSame($atual, $this->valorAtual($classe, $id), 'nada pode mudar sem a permissão de edição');
    }

    #[DataProvider('entidades')]
    #[TestDox('$classe: com a permissão da tela de edição, o desfazer funciona')]
    public function testComPermissaoDeEdicaoDesfaz(string $classe, string $permissao, string $atual, string $anterior): void
    {
        [$id, $logId] = $this->cenario($classe);
        $this->entrarCom([...self::SO_AUDITORIA, $permissao]);

        $this->desfazer($logId);

        self::assertResponseRedirects();
        self::assertSame($anterior, $this->valorAtual($classe, $id));
    }

    #[TestDox('A permissão de outra tela não serve: Cargo com admin.ponto.manage dá 403')]
    public function testPermissaoDeOutraTelaNaoServe(): void
    {
        [$id, $logId] = $this->cenario(Cargo::class);
        $this->entrarCom([...self::SO_AUDITORIA, 'admin.ponto.manage']);

        $this->desfazer($logId);

        self::assertResponseStatusCodeSame(403);
        self::assertSame('Nome novo', $this->valorAtual(Cargo::class, $id));
    }

    #[TestDox('Pasta, fora do mapa do F19: com admin.audit.view e a edição da pasta (a guarda da tela), desfaz')]
    public function testPastaForaDoMapaDesfazComAEdicaoDaPasta(): void
    {
        $pasta = new Pasta();
        $pasta->setNup('TEST-F19-' . uniqid());
        $pasta->setTenant($this->tenant);
        $pasta->setPrioridade(PrioridadePasta::Normal);
        $pasta->setNomeAcao('acao atual');
        $this->em->persist($pasta);
        $this->em->flush();
        $id = (int) $pasta->getId();
        $logId = $this->criarLog(Pasta::class, $id, ['nomeAcao' => ['from' => 'acao anterior', 'to' => 'ACAO ATUAL']]);
        $this->entrarCom([...self::SO_AUDITORIA, 'resources.pasta.edit']);

        $this->desfazer($logId);

        self::assertResponseRedirects();
        // O setter da pasta grava o nome da ação em maiúsculas.
        self::assertSame('ACAO ANTERIOR', $this->em->getConnection()->fetchOne('SELECT nome_acao FROM pasta WHERE id = ?', [$id]));
    }

    #[TestDox('O botão desfazer só aparece na linha que a pessoa pode desfazer')]
    public function testBotaoSoParaQuemPode(): void
    {
        [, $logSede] = $this->cenario(Sede::class);
        [, $logCargo] = $this->cenario(Cargo::class);
        $this->entrarCom([...self::SO_AUDITORIA, 'admin.tenant.settings.manage']);

        $this->client->request('GET', '/auditoria');

        self::assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString("/auditoria/{$logCargo}/desfazer", $html, 'controle: a linha do cargo tem o botão');
        self::assertStringNotContainsString("/auditoria/{$logSede}/desfazer", $html, 'sem admin.ponto.manage, a linha da sede não tem o botão');
    }

    #[TestDox('Evento sem admin.users.manage: o criador desfaz, como na tela agenda_editar')]
    public function testCriadorDoEventoDesfaz(): void
    {
        $criador = $this->entrarCom(self::SO_AUDITORIA);
        [$id, $logId] = $this->cenarioEvento($criador);

        $this->desfazer($logId);

        self::assertResponseRedirects();
        self::assertSame('Titulo anterior', $this->valorAtual(Evento::class, $id));
    }

    #[TestDox('Evento sem admin.users.manage: quem não é o criador recebe 403 e o evento fica intacto')]
    public function testOutraPessoaNaoDesfazEvento(): void
    {
        [$id, $logId] = $this->cenarioEvento($this->solicitante());
        $this->entrarCom(self::SO_AUDITORIA);

        $this->desfazer($logId);

        self::assertResponseStatusCodeSame(403);
        self::assertSame('Titulo novo', $this->valorAtual(Evento::class, $id));
    }

    #[TestDox('Evento com admin.users.manage: quem não é o criador desfaz')]
    public function testComPermissaoDesfazEventoDeOutro(): void
    {
        [$id, $logId] = $this->cenarioEvento($this->solicitante());
        $this->entrarCom([...self::SO_AUDITORIA, 'admin.users.manage']);

        $this->desfazer($logId);

        self::assertResponseRedirects();
        self::assertSame('Titulo anterior', $this->valorAtual(Evento::class, $id));
    }

    #[TestDox('LegendaCor não se desfaz: a recusa é a de entidade não reversível e nada muda')]
    public function testLegendaCorNaoSeDesfaz(): void
    {
        $legenda = (new LegendaCor())->setNome('Nome novo')->setCor('#123456')->setTenant($this->tenant);
        $this->em->persist($legenda);
        $this->em->flush();
        $id = (int) $legenda->getId();
        $logId = $this->criarLog(LegendaCor::class, $id, ['nome' => ['from' => 'Nome anterior', 'to' => 'Nome novo']]);
        $this->entrarCom([...self::SO_AUDITORIA, 'admin.users.manage', 'admin.tenant.settings.manage']);

        $this->desfazer($logId);

        self::assertResponseRedirects();
        self::assertSame(
            ['Esta alteração não pode ser desfeita automaticamente.'],
            $this->client->getRequest()->getSession()->getFlashBag()->peek('error'),
        );
        self::assertSame('Nome novo', $this->em->getConnection()->fetchOne('SELECT nome FROM legenda_cor WHERE id = ?', [$id]));
    }
}
