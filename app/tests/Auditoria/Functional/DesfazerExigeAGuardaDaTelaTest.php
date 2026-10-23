<?php

declare(strict_types=1);

namespace App\Tests\Auditoria\Functional;

use App\Auditoria\Service\GuardaDaTelaNoDesfazer;
use App\Controller\AuditLogController;
use App\Entity\Audit\AuditLog;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Notificacao;
use App\Entity\Permission\Permission;
use App\Entity\Tarefa\Tarefa;
use App\Entity\Tarefa\TarefaMensagem;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Entity\Tenant\TenantRolePermission;
use App\Pasta\Entity\CadeadoDaPasta;
use App\Pasta\Entity\ModoDoCadeado;
use App\Pasta\Entity\NivelDeAcesso;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaChecklistItem;
use App\Pasta\Entity\PastaMensagem;
use App\Pasta\Entity\PastaObservacaoDetalhes;
use App\Pasta\Entity\PrioridadePasta;
use App\Tarefa\Enum\EtapaDaPasta;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

/**
 * Spec `docs/specs/desfazer-guarda-da-tela.md`: desfazer uma alteração de meta ou de pasta exige a mesma guarda da tela
 * que faria a alteração. Meta: o módulo `tarefas` e o acesso à meta; o prazo, só quem criou a meta; a conclusão e a
 * reabertura e a etapa, só quem cuida dela. Pasta e filhas: editar a pasta-mãe (com o teto do cadeado); a observação
 * dos Detalhes, também o autor dentro da janela. Mensagem da pasta: ver a pasta, o cadeado deixar escrever e o autor,
 * dentro da janela de edição. Sem a guarda, 403, nada muda e o botão não aparece. A resposta da meta e a
 * notificação não se desfazem.
 */
#[CoversClass(AuditLogController::class)]
#[CoversClass(GuardaDaTelaNoDesfazer::class)]
final class DesfazerExigeAGuardaDaTelaTest extends JusPrimeWebTestCase
{
    private const SO_AUDITORIA = ['admin.audit.view'];
    private const MODULO_METAS = 'modules.tarefas.view';
    private const EDITAR_PASTA = 'resources.pasta.edit';
    private const VER_PASTA = 'resources.pasta.view';
    private const NAO_REVERSIVEL = 'Esta alteração não pode ser desfeita automaticamente.';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Tenant $tenant;
    private User $colega;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        // A página da auditoria e o desfazer na mesma prova: sem reboot, o armazenamento do CSRF abaixo vale nos dois.
        $this->client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        $this->tenant = new Tenant();
        $this->tenant->setName('Desfazer guarda da tela ' . uniqid());
        $this->em->persist($this->tenant);
        $this->colega = $this->pessoa('Colega');
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

    // ── Meta ────────────────────────────────────────────────────────────────

    #[TestDox('Meta: sem o módulo tarefas, 403, nada muda e o botão não aparece')]
    public function testMetaSemModuloRecebe403(): void
    {
        $meta = $this->meta($this->pasta(), $this->colega);
        $logId = $this->criarLog(Tarefa::class, (int) $meta->getId(), ['titulo' => ['from' => 'Titulo anterior', 'to' => 'Titulo novo']]);
        $this->entrarCom(self::SO_AUDITORIA);

        $this->assertSemBotao($logId);
        $this->desfazer($logId);

        self::assertResponseStatusCodeSame(403);
        self::assertSame('Titulo novo', $this->colunaDaMeta($meta, 'titulo'));
    }

    #[TestDox('Meta: com o módulo e o acesso à meta, o título se desfaz')]
    public function testMetaComModuloEAcessoDesfaz(): void
    {
        $meta = $this->meta($this->pasta(), $this->colega);
        $logId = $this->criarLog(Tarefa::class, (int) $meta->getId(), ['titulo' => ['from' => 'Titulo anterior', 'to' => 'Titulo novo']]);
        $this->entrarCom([...self::SO_AUDITORIA, self::MODULO_METAS]);

        $this->assertComBotao($logId);
        $this->desfazer($logId);

        self::assertResponseRedirects();
        self::assertSame('Titulo anterior', $this->colunaDaMeta($meta, 'titulo'));
    }

    #[TestDox('Meta sem acesso (AcessoAMeta: ninguém da pasta tem vínculo): 403 mesmo com o módulo')]
    public function testMetaSemAcessoRecebe403(): void
    {
        $meta = $this->meta($this->pasta(semCriador: true), $this->colega);
        $logId = $this->criarLog(Tarefa::class, (int) $meta->getId(), ['titulo' => ['from' => 'Titulo anterior', 'to' => 'Titulo novo']]);
        $this->entrarCom([...self::SO_AUDITORIA, self::MODULO_METAS]);

        $this->assertSemBotao($logId);
        $this->desfazer($logId);

        self::assertResponseStatusCodeSame(403);
        self::assertSame('Titulo novo', $this->colunaDaMeta($meta, 'titulo'));
    }

    #[TestDox('Meta: o prazo, por quem não criou a meta, dá 403 e o prazo fica')]
    public function testPrazoPorQuemNaoCriouRecebe403(): void
    {
        $meta = $this->meta($this->pasta(), $this->colega);
        $logId = $this->criarLog(Tarefa::class, (int) $meta->getId(), $this->diffDoPrazo());
        $this->entrarCom([...self::SO_AUDITORIA, self::MODULO_METAS]);

        $this->assertSemBotao($logId);
        $this->desfazer($logId);

        self::assertResponseStatusCodeSame(403);
        self::assertStringStartsWith('2026-12-01', $this->colunaDaMeta($meta, 'prazo'));
    }

    #[TestDox('Meta: o prazo, por quem criou a meta, se desfaz')]
    public function testPrazoPeloCriadorDesfaz(): void
    {
        $ator = $this->entrarCom([...self::SO_AUDITORIA, self::MODULO_METAS]);
        $meta = $this->meta($this->pasta(), $ator);
        $logId = $this->criarLog(Tarefa::class, (int) $meta->getId(), $this->diffDoPrazo());

        $this->desfazer($logId);

        self::assertResponseRedirects();
        self::assertStringStartsWith('2026-11-01', $this->colunaDaMeta($meta, 'prazo'));
    }

    #[TestDox('Meta: desfazer a reabertura (concluir de novo) por quem não cuida da meta dá 403')]
    public function testConclusaoPorQuemNaoCuidaRecebe403(): void
    {
        $meta = $this->meta($this->pasta(), $this->colega);
        $logId = $this->criarLog(Tarefa::class, (int) $meta->getId(), $this->diffDaReabertura());
        $this->entrarCom([...self::SO_AUDITORIA, self::MODULO_METAS]);

        $this->assertSemBotao($logId);
        $this->desfazer($logId);

        self::assertResponseStatusCodeSame(403);
        self::assertSame(Tarefa::STATUS_PENDENTE, $this->colunaDaMeta($meta, 'status'));
    }

    #[TestDox('Meta: desfazer a reabertura por um responsável da meta conclui de novo')]
    public function testConclusaoPeloResponsavelDesfaz(): void
    {
        $ator = $this->entrarCom([...self::SO_AUDITORIA, self::MODULO_METAS]);
        $meta = $this->meta($this->pasta(), $this->colega, $ator);
        $logId = $this->criarLog(Tarefa::class, (int) $meta->getId(), $this->diffDaReabertura());

        $this->desfazer($logId);

        self::assertResponseRedirects();
        self::assertSame(Tarefa::STATUS_CONCLUIDA, $this->colunaDaMeta($meta, 'status'));
    }

    #[TestDox('Meta: a etapa, por quem não cuida da meta, dá 403 e a etapa fica')]
    public function testEtapaPorQuemNaoCuidaRecebe403(): void
    {
        $meta = $this->meta($this->pasta(), $this->colega);
        $logId = $this->criarLog(Tarefa::class, (int) $meta->getId(), $this->diffDaEtapa());
        $this->entrarCom([...self::SO_AUDITORIA, self::MODULO_METAS]);

        $this->assertSemBotao($logId);
        $this->desfazer($logId);

        self::assertResponseStatusCodeSame(403);
        self::assertSame(EtapaDaPasta::Controladoria->value, $this->colunaDaMeta($meta, 'etapa'));
    }

    #[TestDox('Meta: a etapa, por um responsável da meta, se desfaz')]
    public function testEtapaPeloResponsavelDesfaz(): void
    {
        $ator = $this->entrarCom([...self::SO_AUDITORIA, self::MODULO_METAS]);
        $meta = $this->meta($this->pasta(), $this->colega, $ator);
        $logId = $this->criarLog(Tarefa::class, (int) $meta->getId(), $this->diffDaEtapa());

        $this->desfazer($logId);

        self::assertResponseRedirects();
        self::assertSame(EtapaDaPasta::Abertura->value, $this->colunaDaMeta($meta, 'etapa'));
    }

    // ── Pasta e filhas ──────────────────────────────────────────────────────

    #[TestDox('Pasta: sem poder editar a pasta, 403, nada muda e o botão não aparece')]
    public function testPastaSemEditarRecebe403(): void
    {
        $pasta = $this->pasta();
        $logId = $this->criarLog(Pasta::class, (int) $pasta->getId(), ['nomeAcao' => ['from' => 'acao anterior', 'to' => 'ACAO ATUAL']]);
        $this->entrarCom(self::SO_AUDITORIA);

        $this->assertSemBotao($logId);
        $this->desfazer($logId);

        self::assertResponseStatusCodeSame(403);
        self::assertSame('ACAO ATUAL', $this->nomeAcao($pasta));
    }

    #[TestDox('Pasta: com a permissão de editar a pasta, desfaz')]
    public function testPastaComEditarDesfaz(): void
    {
        $pasta = $this->pasta();
        $logId = $this->criarLog(Pasta::class, (int) $pasta->getId(), ['nomeAcao' => ['from' => 'acao anterior', 'to' => 'ACAO ATUAL']]);
        $this->entrarCom([...self::SO_AUDITORIA, self::EDITAR_PASTA]);

        $this->assertComBotao($logId);
        $this->desfazer($logId);

        self::assertResponseRedirects();
        self::assertSame('ACAO ANTERIOR', $this->nomeAcao($pasta));
    }

    #[TestDox('Pasta: o cadeado que só deixa ver tira o desfazer de quem teria a permissão de editar')]
    public function testPastaSoParaVerPeloCadeadoRecebe403(): void
    {
        $pasta = $this->pasta();
        $logId = $this->criarLog(Pasta::class, (int) $pasta->getId(), ['nomeAcao' => ['from' => 'acao anterior', 'to' => 'ACAO ATUAL']]);
        $ator = $this->entrarCom([...self::SO_AUDITORIA, self::EDITAR_PASTA]);
        $cadeado = new CadeadoDaPasta($this->tenant, $pasta, ModoDoCadeado::PorPessoa, null, new \DateTimeImmutable());
        $cadeado->definirPorPessoa([[$ator, NivelDeAcesso::Visualizar]], null, new \DateTimeImmutable());
        $this->em->persist($cadeado);
        $this->em->flush();

        $this->assertSemBotao($logId);
        $this->desfazer($logId);

        self::assertResponseStatusCodeSame(403);
        self::assertSame('ACAO ATUAL', $this->nomeAcao($pasta));
    }

    #[TestDox('Filha da pasta (item do checklist): sem editar a pasta-mãe, 403 e o botão não aparece')]
    public function testFilhaDaPastaSemEditarAPastaMaeRecebe403(): void
    {
        [$id, $logId] = $this->itemDoChecklist();
        $this->entrarCom(self::SO_AUDITORIA);

        $this->assertSemBotao($logId);
        $this->desfazer($logId);

        self::assertResponseStatusCodeSame(403);
        self::assertSame('ITEM NOVO', $this->tituloDoItem($id));
    }

    #[TestDox('Filha da pasta (item do checklist): com a permissão de editar a pasta-mãe, desfaz')]
    public function testFilhaDaPastaComEditarAPastaMaeDesfaz(): void
    {
        [$id, $logId] = $this->itemDoChecklist();
        $this->entrarCom([...self::SO_AUDITORIA, self::EDITAR_PASTA]);

        $this->desfazer($logId);

        self::assertResponseRedirects();
        // O setter do item grava o título em maiúsculas.
        self::assertSame('ITEM ANTERIOR', $this->tituloDoItem($id));
    }

    #[TestDox('Mensagem da pasta de outro autor: 403 mesmo com a permissão de editar a pasta')]
    public function testMensagemDeOutroAutorRecebe403(): void
    {
        $mensagem = $this->mensagem($this->pasta(), $this->colega);
        $logId = $this->criarLog(PastaMensagem::class, (int) $mensagem->getId(), ['conteudo' => ['from' => 'Texto anterior', 'to' => 'Texto novo']]);
        $this->entrarCom([...self::SO_AUDITORIA, self::EDITAR_PASTA]);

        $this->assertSemBotao($logId);
        $this->desfazer($logId);

        self::assertResponseStatusCodeSame(403);
        self::assertSame('Texto novo', $this->conteudoDaMensagem($mensagem));
    }

    #[TestDox('Mensagem da pasta: o autor, dentro da janela de edição e vendo a pasta, desfaz')]
    public function testMensagemDoAutorDesfaz(): void
    {
        $ator = $this->entrarCom([...self::SO_AUDITORIA, self::VER_PASTA]);
        $mensagem = $this->mensagem($this->pasta(), $ator);
        $logId = $this->criarLog(PastaMensagem::class, (int) $mensagem->getId(), ['conteudo' => ['from' => 'Texto anterior', 'to' => 'Texto novo']]);

        $this->desfazer($logId);

        self::assertResponseRedirects();
        self::assertSame('Texto anterior', $this->conteudoDaMensagem($mensagem));
    }

    #[TestDox('Mensagem da pasta: o autor que não vê a pasta recebe 403, como na rota de edição')]
    public function testMensagemDoAutorSemVerAPastaRecebe403(): void
    {
        $ator = $this->entrarCom(self::SO_AUDITORIA);
        $mensagem = $this->mensagem($this->pasta(), $ator);
        $logId = $this->criarLog(PastaMensagem::class, (int) $mensagem->getId(), ['conteudo' => ['from' => 'Texto anterior', 'to' => 'Texto novo']]);

        $this->assertSemBotao($logId);
        $this->desfazer($logId);

        self::assertResponseStatusCodeSame(403);
        self::assertSame('Texto novo', $this->conteudoDaMensagem($mensagem));
    }

    #[TestDox('Mensagem da pasta: o autor que o cadeado deixa só ver recebe 403, como a barreira da rota de edição')]
    public function testMensagemDoAutorSoParaVerPeloCadeadoRecebe403(): void
    {
        $ator = $this->entrarCom([...self::SO_AUDITORIA, self::VER_PASTA]);
        $pasta = $this->pasta();
        $mensagem = $this->mensagem($pasta, $ator);
        $logId = $this->criarLog(PastaMensagem::class, (int) $mensagem->getId(), ['conteudo' => ['from' => 'Texto anterior', 'to' => 'Texto novo']]);
        $cadeado = new CadeadoDaPasta($this->tenant, $pasta, ModoDoCadeado::PorPessoa, null, new \DateTimeImmutable());
        $cadeado->definirPorPessoa([[$ator, NivelDeAcesso::Visualizar]], null, new \DateTimeImmutable());
        $this->em->persist($cadeado);
        $this->em->flush();

        $this->assertSemBotao($logId);
        $this->desfazer($logId);

        self::assertResponseStatusCodeSame(403);
        self::assertSame('Texto novo', $this->conteudoDaMensagem($mensagem));
    }

    #[TestDox('Observação dos Detalhes de outro autor: 403 mesmo com a permissão de editar a pasta')]
    public function testObservacaoDeOutroAutorRecebe403(): void
    {
        $observacao = $this->observacao($this->colega);
        $logId = $this->criarLog(PastaObservacaoDetalhes::class, (int) $observacao->getId(), ['conteudo' => ['from' => 'Texto anterior', 'to' => 'Texto novo']]);
        $this->entrarCom([...self::SO_AUDITORIA, self::EDITAR_PASTA]);

        $this->assertSemBotao($logId);
        $this->desfazer($logId);

        self::assertResponseStatusCodeSame(403);
        self::assertSame('Texto novo', $this->conteudoDaObservacao($observacao));
    }

    #[TestDox('Observação dos Detalhes: o autor, dentro da janela e editando a pasta, desfaz')]
    public function testObservacaoDoAutorDesfaz(): void
    {
        $ator = $this->entrarCom([...self::SO_AUDITORIA, self::EDITAR_PASTA]);
        $observacao = $this->observacao($ator);
        $logId = $this->criarLog(PastaObservacaoDetalhes::class, (int) $observacao->getId(), ['conteudo' => ['from' => 'Texto anterior', 'to' => 'Texto novo']]);

        $this->desfazer($logId);

        self::assertResponseRedirects();
        self::assertSame('Texto anterior', $this->conteudoDaObservacao($observacao));
    }

    // ── Fora da lista de reversíveis ────────────────────────────────────────

    #[TestDox('A resposta da meta (TarefaMensagem) não se desfaz: recusa, nada muda e o botão não aparece')]
    public function testTarefaMensagemNaoSeDesfaz(): void
    {
        $resposta = (new TarefaMensagem())
            ->setTarefa($this->meta($this->pasta(), $this->colega))
            ->setTenant($this->tenant)
            ->setUsuario($this->colega)
            ->setMensagem('Resposta nova');
        $this->em->persist($resposta);
        $this->em->flush();
        $id = (int) $resposta->getId();
        $logId = $this->criarLog(TarefaMensagem::class, $id, ['mensagem' => ['from' => 'Resposta anterior', 'to' => 'Resposta nova']]);
        $this->entrarCom([...self::SO_AUDITORIA, self::MODULO_METAS, self::EDITAR_PASTA]);

        $this->assertSemBotao($logId);
        $this->desfazer($logId);

        self::assertResponseRedirects();
        self::assertSame([self::NAO_REVERSIVEL], $this->client->getRequest()->getSession()->getFlashBag()->peek('error'));
        self::assertSame('Resposta nova', $this->em->getConnection()->fetchOne('SELECT mensagem FROM tarefa_mensagem WHERE id = ?', [$id]));
    }

    #[TestDox('A notificação não se desfaz: recusa, nada muda e o botão não aparece')]
    public function testNotificacaoNaoSeDesfaz(): void
    {
        $notificacao = (new Notificacao())
            ->setUsuario($this->colega)
            ->setTenant($this->tenant)
            ->setTipo('aviso')
            ->setTitulo('Titulo novo');
        $this->em->persist($notificacao);
        $this->em->flush();
        $id = (int) $notificacao->getId();
        $logId = $this->criarLog(Notificacao::class, $id, ['titulo' => ['from' => 'Titulo anterior', 'to' => 'Titulo novo']]);
        $this->entrarCom([...self::SO_AUDITORIA, 'admin.users.manage']);

        $this->assertSemBotao($logId);
        $this->desfazer($logId);

        self::assertResponseRedirects();
        self::assertSame([self::NAO_REVERSIVEL], $this->client->getRequest()->getSession()->getFlashBag()->peek('error'));
        self::assertSame('Titulo novo', $this->em->getConnection()->fetchOne('SELECT titulo FROM notificacao WHERE id = ?', [$id]));
    }

    // ── Apoio ───────────────────────────────────────────────────────────────

    /** @param list<string> $permissoes */
    private function entrarCom(array $permissoes): User
    {
        $user = $this->pessoa('Auditora');

        $role = new TenantRole();
        $role->setTenant($this->tenant);
        $role->setName('Auditoria ' . uniqid());
        $role->setIsSystem(false);
        $this->em->persist($role);

        foreach ($permissoes as $codigo) {
            $ligacao = new TenantRolePermission();
            $ligacao->setTenantRole($role);
            $ligacao->setPermission($this->permissao($codigo));
            $this->em->persist($ligacao);
            $role->getTenantRolePermissions()->add($ligacao);
        }

        $vinculo = $this->em->getRepository(UserTenant::class)->findOneBy(['user' => $user, 'tenant' => $this->tenant]);
        self::assertInstanceOf(UserTenant::class, $vinculo);
        $vinculo->setTenantRole($role);
        $this->em->flush();

        $this->logarComTenant($this->client, $user, $this->tenant);

        return $user;
    }

    private function permissao(string $codigo): Permission
    {
        $perm = $this->em->getRepository(Permission::class)->findOneBy(['code' => $codigo]);
        if ($perm === null) {
            $perm = new Permission();
            $perm->setCode($codigo);
            $perm->setDescription($codigo);
            $perm->setGroup(explode('.', $codigo)[0]);
            $this->em->persist($perm);
        }

        return $perm;
    }

    private function pessoa(string $nome): User
    {
        $user = new User();
        $user->setEmail('guarda_tela_' . uniqid() . '@test.com');
        $user->setFullName($nome);
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setPassword('x');
        $this->em->persist($user);
        $this->em->persist(new UserTenant($user, $this->tenant));
        $this->em->flush();

        return $user;
    }

    /** O colega cria a pasta: é o vínculo que o `AcessoAMeta` confere. Sem criador, ninguém da pasta tem vínculo. */
    private function pasta(bool $semCriador = false): Pasta
    {
        $pasta = new Pasta();
        $pasta->setNup('TEST-GUARDA-' . uniqid());
        $pasta->setTenant($this->tenant);
        $pasta->setPrioridade(PrioridadePasta::Normal);
        $pasta->setNomeAcao('acao atual');
        $pasta->setCriadoPor($semCriador ? null : $this->colega);
        $this->em->persist($pasta);
        $this->em->flush();

        return $pasta;
    }

    private function meta(Pasta $pasta, User $criador, ?User $responsavel = null): Tarefa
    {
        $meta = new Tarefa();
        $meta->setTitulo('Titulo novo');
        $meta->setDescricao('Descrição da meta');
        $meta->setTenant($this->tenant);
        $meta->setPasta($pasta);
        $meta->setCriadoPor($criador);
        $meta->setPrazo(new \DateTimeImmutable('2026-12-01 00:00:00'));
        $meta->setStatus(Tarefa::STATUS_PENDENTE);
        $meta->setEtapa(EtapaDaPasta::Controladoria);
        if ($responsavel !== null) {
            $meta->addResponsavel($responsavel);
        }
        $this->em->persist($meta);
        $this->em->flush();

        return $meta;
    }

    private function mensagem(Pasta $pasta, User $autor): PastaMensagem
    {
        $mensagem = (new PastaMensagem())->setPasta($pasta)->setTenant($this->tenant)->setAutor($autor)->setConteudo('Texto novo');
        $this->em->persist($mensagem);
        $this->em->flush();

        return $mensagem;
    }

    private function observacao(User $autor): PastaObservacaoDetalhes
    {
        $observacao = (new PastaObservacaoDetalhes())->setPasta($this->pasta())->setTenant($this->tenant)->setAutor($autor)->setConteudo('Texto novo');
        $this->em->persist($observacao);
        $this->em->flush();

        return $observacao;
    }

    private function conteudoDaObservacao(PastaObservacaoDetalhes $observacao): string
    {
        return (string) $this->em->getConnection()->fetchOne('SELECT conteudo FROM pasta_observacao_detalhes WHERE id = ?', [$observacao->getId()]);
    }

    /** @return array<string, array{from: mixed, to: mixed}> */
    private function diffDaEtapa(): array
    {
        return ['etapa' => ['from' => EtapaDaPasta::Abertura->value, 'to' => EtapaDaPasta::Controladoria->value]];
    }

    /** @return array<string, array{from: mixed, to: mixed}> */
    private function diffDoPrazo(): array
    {
        return ['prazo' => ['from' => '2026-11-01T00:00:00+00:00', 'to' => '2026-12-01T00:00:00+00:00']];
    }

    /**
     * O que o `ReabrirMetaUseCase` grava: o status volta a pendente e a data da conclusão some.
     *
     * @return array<string, array{from: mixed, to: mixed}>
     */
    private function diffDaReabertura(): array
    {
        return [
            'status' => ['from' => Tarefa::STATUS_CONCLUIDA, 'to' => Tarefa::STATUS_PENDENTE],
            'dataConclusao' => ['from' => '2026-10-20T10:00:00+00:00', 'to' => null],
        ];
    }

    /** @return array{0: int, 1: int} [id do item, id da linha de auditoria] */
    private function itemDoChecklist(): array
    {
        $item = (new PastaChecklistItem())->setPasta($this->pasta())->setTenant($this->tenant)->setTitulo('Item novo')->setOrdem(1);
        $this->em->persist($item);
        $this->em->flush();
        $id = (int) $item->getId();

        return [$id, $this->criarLog(PastaChecklistItem::class, $id, ['titulo' => ['from' => 'Item anterior', 'to' => 'Item novo']])];
    }

    private function tituloDoItem(int $id): string
    {
        return (string) $this->em->getConnection()->fetchOne('SELECT titulo FROM pasta_checklist_item WHERE id = ?', [$id]);
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

    private function assertSemBotao(int $logId): void
    {
        self::assertStringNotContainsString("/auditoria/{$logId}/desfazer", $this->paginaDaAuditoria(), 'o botão não aparece para quem não pode desfazer');
    }

    private function assertComBotao(int $logId): void
    {
        self::assertStringContainsString("/auditoria/{$logId}/desfazer", $this->paginaDaAuditoria(), 'controle: quem pode desfazer vê o botão');
    }

    private function paginaDaAuditoria(): string
    {
        $this->client->request('GET', '/auditoria');
        self::assertResponseIsSuccessful();

        return (string) $this->client->getResponse()->getContent();
    }

    private function colunaDaMeta(Tarefa $meta, string $coluna): string
    {
        return (string) $this->em->getConnection()->fetchOne("SELECT {$coluna} FROM tarefa WHERE id = ?", [$meta->getId()]);
    }

    private function nomeAcao(Pasta $pasta): string
    {
        return (string) $this->em->getConnection()->fetchOne('SELECT nome_acao FROM pasta WHERE id = ?', [$pasta->getId()]);
    }

    private function conteudoDaMensagem(PastaMensagem $mensagem): string
    {
        return (string) $this->em->getConnection()->fetchOne('SELECT conteudo FROM pasta_mensagem WHERE id = ?', [$mensagem->getId()]);
    }
}
