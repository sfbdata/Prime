<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Permission\Permission;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Entity\Tenant\TenantRolePermission;
use App\Pasta\Controller\PastaAndamentoController;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaAndamento;
use App\Pasta\Service\AndamentoDaPasta;
use App\Processo\Entity\Processo;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

/**
 * Andamento da pasta em fases (E12b, desenho 1.2.3): estimativa, avançar, pular, voltar, finalizar, reativar,
 * contrato, via administrativa e o filtro "Protocolar" da Lista do Expediente.
 */
#[CoversClass(PastaAndamentoController::class)]
#[CoversClass(AndamentoDaPasta::class)]
final class PastaAndamentoControllerTest extends JusPrimeWebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->instalarCsrfStorage();
    }

    #[TestDox('sem andamento gravado, a fase é estimada: Abertura sem nada; Em andamento com processo vinculado')]
    public function testFaseEstimada(): void
    {
        [$user, $tenant] = $this->criarAdmin();
        $nova = $this->criarPasta($tenant);
        $comProc = $this->criarPasta($tenant);
        $this->vincularProcesso($comProc, $tenant, $user);
        $this->logarComTenant($this->client, $user, $tenant);

        self::assertSame('Abertura', $this->faseAtual($nova));
        self::assertSame('Em andamento', $this->faseAtual($comProc));
        $c = $this->client->request('GET', '/pasta/' . $comProc->getId());
        self::assertCount(10, $c->filter('.ps-and > .ps-and-rol > .ps-and-fase'));
        self::assertStringContainsString('fase estimada pelos dados da pasta', (string) $c->filter('.ps-and-fase[data-fase-at="1"] .js-and-fase')->attr('title'));
    }

    #[TestDox('avançar saltando deixa as fases do meio puladas e registra "Andamento: Fase da pasta alterada…"')]
    public function testAvancarPulando(): void
    {
        [$user, $tenant] = $this->criarAdmin();
        $pasta = $this->criarPasta($tenant);
        $this->logarComTenant($this->client, $user, $tenant);

        $this->mudar($pasta, 4);

        self::assertResponseIsSuccessful();
        $a = $this->andamento($pasta);
        self::assertSame(4, $a->getAtual());
        self::assertSame([1, 2, 3], $a->getMarcos()['pul']);
        self::assertTrue($a->passouProtocolo());
        self::assertSame('PENDENTE', $this->contrato($pasta), 'Contrato pulada não assina o contrato');
        self::assertStringContainsString('Fase da pasta alterada de &quot;Abertura&quot; para &quot;Protocolo&quot;.', $this->ultimoCartao($pasta, 'fase'));
        $c = $this->client->request('GET', '/pasta/' . $pasta->getId());
        self::assertSame('pulada', trim($c->filter('.ps-and-fase')->eq(2)->filter('.ps-and-dia')->text()));
    }

    #[TestDox('avançar sem pular (faixa de sugestão) marca as fases do meio e, passando por Contrato, assina o contrato')]
    public function testAvancarSemPularAssinaContrato(): void
    {
        [$user, $tenant] = $this->criarAdmin();
        $pasta = $this->criarPasta($tenant);
        $this->logarComTenant($this->client, $user, $tenant);

        $this->mudar($pasta, 2, true, 'Contrato assinado no Financeiro');

        $a = $this->andamento($pasta);
        self::assertSame([], $a->getMarcos()['pul']);
        self::assertSame([0, 1, 2], array_keys($a->getMarcos()['em']));
        self::assertSame('REGULAR', $this->contrato($pasta));
        self::assertStringContainsString('Motivo: Contrato assinado no Financeiro.', $this->ultimoCartao($pasta, 'fase'));
        self::assertStringContainsString('marcado como assinado', $this->ultimoCartao($pasta, 'contrato'));

        // Voltar para antes de Contrato reabre o contrato.
        $this->mudar($pasta, 1);
        self::assertSame('PENDENTE', $this->contrato($pasta));
        self::assertSame([0, 1], array_keys($this->andamento($pasta)->getMarcos()['em']));
    }

    #[TestDox('contrato já regular de antes não é reaberto ao AVANÇAR de Abertura para Documentação, e saltar Contrato não a deixa pulada')]
    public function testContratoRegularAntigoNaoReabreAoAvancar(): void
    {
        [$user, $tenant] = $this->criarAdmin();
        $pasta = $this->criarPasta($tenant);
        $pasta->setSituacaoContrato('REGULAR');
        $this->em()->flush();
        $this->logarComTenant($this->client, $user, $tenant);

        $this->mudar($pasta, 1);
        self::assertSame('REGULAR', $this->contrato($pasta));
        self::assertSame('', $this->ultimoCartao($pasta, 'contrato'), 'nenhum cartão falso de "voltou para antes de Contrato"');

        $this->mudar($pasta, 4);
        $m = $this->andamento($pasta)->getMarcos();
        self::assertSame([3], $m['pul'], 'Contrato ganha a data; só Distribuído fica pulada');
        self::assertArrayHasKey(2, $m['em']);
    }

    #[TestDox('voltar à via judicial sem andamento judicial anterior não diz que "restaurou"')]
    public function testViaJudicialSemAnterior(): void
    {
        [$user, $tenant] = $this->criarAdmin();
        $pasta = $this->criarPasta($tenant);
        $pasta->setAdministrativa(true);
        $this->em()->flush();
        $this->logarComTenant($this->client, $user, $tenant);

        $this->client->request('POST', '/pasta/' . $pasta->getId() . '/administrativa', ['_token' => 'TOKEN_pasta_administrativa_' . $pasta->getId(), 'administrativa' => '0']);

        $cartao = $this->ultimoCartao($pasta, 'fase');
        self::assertStringContainsString('pasta voltou para a via judicial.', $cartao);
        self::assertStringNotContainsString('restaurado', $cartao);
    }

    #[TestDox('finalizar e clicar de novo em Finalizada reativa: entram Reativada, Em andamento e um novo Finalizada')]
    public function testFinalizarEReativar(): void
    {
        [$user, $tenant] = $this->criarAdmin();
        $pasta = $this->criarPasta($tenant);
        $this->logarComTenant($this->client, $user, $tenant);

        $this->mudar($pasta, 9, true);
        self::assertStringContainsString('Pasta finalizada (de &quot;Abertura&quot; para &quot;Finalizada&quot;).', $this->ultimoCartao($pasta, 'fase'));
        $this->mudar($pasta, 9);

        $a = $this->andamento($pasta);
        self::assertSame(11, $a->getAtual());
        self::assertSame(['Reativada', 'Em andamento', 'Finalizada'], array_column($a->getMarcos()['extra'], 0));
        self::assertStringContainsString('Pasta reativada: de &quot;Finalizada&quot; para &quot;Em andamento&quot;.', $this->ultimoCartao($pasta, 'fase'));
        $c = $this->client->request('GET', '/pasta/' . $pasta->getId());
        self::assertCount(13, $c->filter('.ps-and-fase'));
        self::assertSame('reativada', $c->filter('.ps-and-fase')->eq(10)->filter('.js-and-fase')->attr('data-estado'));
    }

    #[TestDox('clicar na fase atual não muda nada')]
    public function testFaseAtualNaoMuda(): void
    {
        [$user, $tenant] = $this->criarAdmin();
        $pasta = $this->criarPasta($tenant);
        $this->logarComTenant($this->client, $user, $tenant);

        $this->mudar($pasta, 0);

        self::assertFalse($this->resposta()['mudou']);
        self::assertNull($this->andamento($pasta));
    }

    #[TestDox('contrato marcado regular no Financeiro leva o andamento até Contrato')]
    public function testContratoNoFinanceiroMoveOAndamento(): void
    {
        [$user, $tenant] = $this->criarAdmin();
        $pasta = $this->criarPasta($tenant);
        $this->logarComTenant($this->client, $user, $tenant);

        $this->client->xmlHttpRequest('POST', '/pasta/' . $pasta->getId() . '/situacao-contrato', ['_token' => 'TOKEN_pasta_situacao_contrato_' . $pasta->getId(), 'situacao' => 'REGULAR']);

        self::assertResponseIsSuccessful();
        self::assertSame(2, $this->andamento($pasta)->getAtual());
        self::assertStringContainsString('Motivo: contrato assinado no Financeiro.', $this->ultimoCartao($pasta, 'fase'));
    }

    #[TestDox('via administrativa: o cartão do Registro conta a troca e a trilha passa a ser a administrativa')]
    public function testViaAdministrativa(): void
    {
        [$user, $tenant] = $this->criarAdmin();
        $pasta = $this->criarPasta($tenant);
        $this->logarComTenant($this->client, $user, $tenant);

        $this->client->request('POST', '/pasta/' . $pasta->getId() . '/administrativa', ['_token' => 'TOKEN_pasta_administrativa_' . $pasta->getId(), 'administrativa' => '1']);

        self::assertStringContainsString('pasta marcada como administrativa', $this->ultimoCartao($pasta, 'fase'));
        $c = $this->client->request('GET', '/pasta/' . $pasta->getId());
        self::assertSame(['Abertura', 'Documentação', 'Contrato', 'Requerimento', 'Protocolo', 'Em análise', 'Exigência', 'Parecer', 'Cumprimento', 'Finalizada'],
            $c->filter('.ps-and-fase .ps-and-rot')->each(static fn ($n): string => trim($n->text())));
    }

    #[TestDox('Lista "Protocolar": entra a pasta ativa sem Protocolo; saem a que passou por Protocolo, a com processo (estimada), a arquivada e a administrativa')]
    public function testFiltroProtocolar(): void
    {
        [$user, $tenant] = $this->criarAdmin();
        $pendente = $this->criarPasta($tenant, 'PEND');
        $protocolada = $this->criarPasta($tenant, 'PROT');
        $comProc = $this->criarPasta($tenant, 'PROC');
        $this->vincularProcesso($comProc, $tenant, $user);
        $arquivada = $this->criarPasta($tenant, 'ARQ');
        $arquivada->setSituacao(Pasta::SITUACAO_ARQUIVADA);
        $adm = $this->criarPasta($tenant, 'ADM');
        $adm->setAdministrativa(true);
        $this->em()->flush();
        $this->logarComTenant($this->client, $user, $tenant);
        $this->mudar($protocolada, 4);

        $html = $this->painel('?expediente=protocolar');

        self::assertStringContainsString($pendente->getNup(), $html);
        foreach ([$protocolada, $comProc, $arquivada, $adm] as $fora) {
            self::assertStringNotContainsString($fora->getNup(), $html, $fora->getNup());
        }
        self::assertSame('Protocolar', trim((new \Symfony\Component\DomCrawler\Crawler($html))->filter('.exp-barra-titulo')->text()));
    }

    #[TestDox('sem token, de outro escritório ou sem permissão de editar: recusado')]
    public function testRecusas(): void
    {
        [$user, $tenant] = $this->criarAdmin();
        [, $outro] = $this->criarAdmin();
        $minha = $this->criarPasta($tenant);
        $dele = $this->criarPasta($outro);
        $this->logarComTenant($this->client, $user, $tenant);

        $this->client->xmlHttpRequest('POST', '/pasta/' . $minha->getId() . '/andamento', ['_token' => 'errado', 'fase' => '3']);
        self::assertResponseStatusCodeSame(403);
        $this->mudar($dele, 3);
        self::assertResponseStatusCodeSame(404);
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM pasta_andamento WHERE pasta_id = ?', [$dele->getId()]));

        $tenant = $this->em()->find(Tenant::class, $tenant->getId());   // a requisição soltou a entidade
        $leitor = $this->criarLeitor($tenant);
        $this->logarComTenant($this->client, $leitor, $tenant);
        $this->mudar($minha, 3);
        self::assertResponseStatusCodeSame(403);
        $c = $this->client->request('GET', '/pasta/' . $minha->getId());
        self::assertSame('0', $c->filter('.ps-and')->attr('data-pode'));
        self::assertNotNull($c->filter('.js-and-fase')->first()->attr('disabled'));
    }

    #[TestDox('etapa entre fases: nomear, renomear e remover; a tela mostra o rótulo e o registro conta a mudança')]
    public function testNomearEtapaEntreFases(): void
    {
        [$user, $tenant] = $this->criarAdmin();
        $pasta = $this->criarPasta($tenant);
        $this->logarComTenant($this->client, $user, $tenant);

        $c = $this->client->request('GET', '/pasta/' . $pasta->getId());
        self::assertCount(9, $c->filter('.ps-and-rol > .ps-and-fase > .ps-and-gap-mais'), 'uma etapa vazia entre cada par de fases');
        self::assertCount(0, $c->filter('.ps-and-gap-txt'));

        $this->nomearEtapa($pasta, 0, 'Triagem');
        self::assertResponseIsSuccessful();
        self::assertTrue($this->resposta()['mudou']);
        $c = $this->client->request('GET', '/pasta/' . $pasta->getId());
        self::assertSame('Triagem', trim($c->filter('.ps-and-rol > .ps-and-fase > .ps-and-gap-txt')->text()));
        self::assertSame('Triagem', $c->filter('.ps-and-rol > .ps-and-fase > .ps-and-gap > .js-and-gap')->attr('data-nome'));
        self::assertStringContainsString('Triagem', $this->ultimoCartao($pasta, 'fase'));

        $this->nomearEtapa($pasta, 0, 'Triagem');
        self::assertFalse($this->resposta()['mudou'], 'mesmo texto não grava de novo');

        $this->nomearEtapa($pasta, 0, 'Entrevista');
        $c = $this->client->request('GET', '/pasta/' . $pasta->getId());
        self::assertSame('Entrevista', trim($c->filter('.ps-and-gap-txt')->text()));

        $this->nomearEtapa($pasta, 0, '');
        $c = $this->client->request('GET', '/pasta/' . $pasta->getId());
        self::assertCount(0, $c->filter('.ps-and-gap-txt'));
        self::assertCount(9, $c->filter('.ps-and-gap-mais'));
    }

    #[TestDox('etapa: texto longo, origem inválida, outro escritório, leitor e sem token são recusados')]
    public function testNomearEtapaRecusas(): void
    {
        [$user, $tenant] = $this->criarAdmin();
        [, $outro] = $this->criarAdmin();
        $pasta = $this->criarPasta($tenant);
        $dele = $this->criarPasta($outro);
        $this->logarComTenant($this->client, $user, $tenant);

        $this->nomearEtapa($pasta, 0, 'Treze letras!');
        self::assertResponseStatusCodeSame(422);
        $this->nomearEtapa($pasta, 0, 'a<b');
        self::assertResponseStatusCodeSame(422);
        $this->nomearEtapa($pasta, 9, 'Ok');
        self::assertResponseStatusCodeSame(422);
        $this->nomearEtapa($dele, 0, 'Ok');
        self::assertResponseStatusCodeSame(404);
        $this->client->xmlHttpRequest('POST', '/pasta/' . $pasta->getId() . '/andamento/etapa', ['_token' => 'errado', 'fase' => '0', 'texto' => 'Ok']);
        self::assertResponseStatusCodeSame(403);
        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM pasta_rotulo_etapa'));

        $tenant = $this->em()->find(Tenant::class, $tenant->getId());   // a requisição soltou a entidade
        $pasta = $this->em()->find(Pasta::class, $pasta->getId());
        $leitor = $this->criarLeitor($tenant);
        $this->logarComTenant($this->client, $leitor, $tenant);
        $this->nomearEtapa($pasta, 0, 'Ok');
        self::assertResponseStatusCodeSame(403);
    }

    #[TestDox('etapa nomeada é da via da pasta: judicial e administrativa têm rótulos separados')]
    public function testEtapaPorVia(): void
    {
        [$user, $tenant] = $this->criarAdmin();
        $pasta = $this->criarPasta($tenant);
        $this->logarComTenant($this->client, $user, $tenant);
        $this->nomearEtapa($pasta, 0, 'Judicial');

        $pasta->setAdministrativa(true);
        $this->em()->flush();
        $c = $this->client->request('GET', '/pasta/' . $pasta->getId());
        self::assertCount(0, $c->filter('.ps-and-gap-txt'));
        $this->nomearEtapa($pasta, 0, 'Admin');
        $c = $this->client->request('GET', '/pasta/' . $pasta->getId());
        self::assertSame('Admin', trim($c->filter('.ps-and-gap-txt')->text()));
    }

    #[TestDox('faixa histórico: sem ocorrência não há faixa e a trilha não tem travessão')]
    public function testFaixaHistoricoVazia(): void
    {
        [$user, $tenant] = $this->criarAdmin();
        $pasta = $this->criarPasta($tenant);
        $this->logarComTenant($this->client, $user, $tenant);

        $c = $this->client->request('GET', '/pasta/' . $pasta->getId());
        self::assertCount(0, $c->filter('.ps-and > .ps-and-hist'));
        self::assertDoesNotMatchRegularExpression('/[—–]/u', (string) $c->filter('.ps-and')->html());
    }

    #[TestDox('faixa histórico: a ficha de meta aponta para a aba tarefas, que existe na pasta (tarefas-tab)')]
    public function testFichaDeMetaAbreAbaTarefas(): void
    {
        [$user, $tenant] = $this->criarAdmin();
        $pasta = $this->criarPasta($tenant);
        $meta = new \App\Entity\Tarefa\Tarefa();
        $meta->setTitulo('Juntar procuração');
        $meta->setDescricao('...');
        $meta->setPasta($pasta);
        $meta->setTenant($tenant);
        $meta->setCriadoPor($user);
        $meta->addResponsavel($user);
        $this->em()->persist($meta);
        $pasta->getTarefas()->add($meta);
        $this->em()->flush();
        $this->logarComTenant($this->client, $user, $tenant);

        $c = $this->client->request('GET', '/pasta/' . $pasta->getId());

        $aba = (string) $c->filter('.ps-and > .ps-and-hist > .js-and-hist')->first()->attr('data-aba');
        self::assertSame('tarefas', $aba);
        self::assertCount(1, $c->filter('#' . $aba . '-tab'), 'o gatilho da aba existe na pasta');
    }

    private function nomearEtapa(Pasta $pasta, int $origem, string $texto): void
    {
        $this->client->xmlHttpRequest('POST', '/pasta/' . $pasta->getId() . '/andamento/etapa', [
            '_token' => 'TOKEN_' . PastaAndamentoController::idDoToken((int) $pasta->getId()),
            'fase' => (string) $origem, 'texto' => $texto,
        ]);
    }

    // ── helpers ─────────────────────────────────────────────────────────

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    private function mudar(Pasta $pasta, int $fase, bool $semPular = false, string $motivo = ''): void
    {
        $this->client->xmlHttpRequest('POST', '/pasta/' . $pasta->getId() . '/andamento', [
            '_token' => 'TOKEN_' . PastaAndamentoController::idDoToken((int) $pasta->getId()),
            'fase' => (string) $fase, 'sem_pular' => $semPular ? '1' : '0', 'motivo' => $motivo,
        ]);
    }

    /** @return array<string, mixed> */
    private function resposta(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }

    private function faseAtual(Pasta $pasta): string
    {
        $c = $this->client->request('GET', '/pasta/' . $pasta->getId());

        return trim($c->filter('.ps-and-fase[data-fase-at="1"] .ps-and-rot')->text());
    }

    private function andamento(Pasta $pasta): ?PastaAndamento
    {
        $this->em()->clear();

        return $this->em()->getRepository(PastaAndamento::class)->findOneBy(['pasta' => $pasta->getId(), 'via' => 'judicial']);
    }

    private function contrato(Pasta $pasta): string
    {
        return (string) $this->em()->getConnection()->fetchOne('SELECT situacao_contrato FROM pasta WHERE id = ?', [$pasta->getId()]);
    }

    private function ultimoCartao(Pasta $pasta, string $tipo): string
    {
        return (string) $this->em()->getConnection()->fetchOne('SELECT conteudo FROM pasta_mensagem WHERE pasta_id = ? AND sistema = ? ORDER BY id DESC LIMIT 1', [$pasta->getId(), $tipo]);
    }

    private function painel(string $query): string
    {
        $this->client->xmlHttpRequest('GET', '/expediente/painel/acervo-geral' . $query);
        self::assertResponseIsSuccessful();

        return (string) $this->client->getResponse()->getContent();
    }

    private function vincularProcesso(Pasta $pasta, Tenant $tenant, User $user): void
    {
        $proc = new Processo();
        $proc->setNumeroProcesso('07' . substr((string) hrtime(true), -5) . '-00.2026.8.07.0001');
        $proc->setTenant($tenant);
        $this->em()->persist($proc);
        $pasta->vincularProcesso($proc, $user);
        $this->em()->flush();
    }

    /** @return array{0: User, 1: Tenant} */
    private function criarAdmin(): array
    {
        $tenant = new Tenant();
        $tenant->setName('Tenant Andamento ' . uniqid());
        $this->em()->persist($tenant);
        $user = $this->novoUsuario('Admin Andamento');
        $role = new TenantRole();
        $role->setTenant($tenant);
        $role->setName('Administrador ' . uniqid());
        $role->setIsSystem(true);
        $this->em()->persist($role);
        $ut = new UserTenant($user, $tenant);
        $ut->setTenantRole($role);
        $this->em()->persist($ut);
        $this->em()->flush();

        return [$user, $tenant];
    }

    private function criarLeitor(Tenant $tenant): User
    {
        $user = $this->novoUsuario('Leitor Andamento');
        $role = new TenantRole();
        $role->setTenant($tenant);
        $role->setName('Leitor ' . uniqid());
        $role->setIsSystem(false);
        $this->em()->persist($role);
        foreach (['modules.pastas.view', 'resources.pasta.view'] as $codigo) {
            $perm = $this->em()->getRepository(Permission::class)->findOneBy(['code' => $codigo]);
            if ($perm === null) {
                $perm = new Permission();
                $perm->setCode($codigo);
                $perm->setDescription($codigo);
                $perm->setGroup(explode('.', $codigo)[0]);
                $this->em()->persist($perm);
            }
            $v = new TenantRolePermission();
            $v->setTenantRole($role);
            $v->setPermission($perm);
            $this->em()->persist($v);
            $role->getTenantRolePermissions()->add($v);
        }
        $ut = new UserTenant($user, $tenant);
        $ut->setTenantRole($role);
        $this->em()->persist($ut);
        $this->em()->flush();

        return $user;
    }

    private function novoUsuario(string $nome): User
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user = new User();
        $user->setEmail('and_' . uniqid() . '@test.com');
        $user->setFullName($nome);
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setPassword($hasher->hashPassword($user, 'senha123'));
        $this->em()->persist($user);

        return $user;
    }

    private function criarPasta(Tenant $tenant, string $rotulo = ''): Pasta
    {
        $pasta = new Pasta();
        $pasta->setNup('AN' . $rotulo . strtoupper(substr(uniqid(), -6)));
        $pasta->setTenant($tenant);
        $this->em()->persist($pasta);
        $this->em()->flush();

        return $pasta;
    }

    private function instalarCsrfStorage(): void
    {
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
    }
}
