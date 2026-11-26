<?php

declare(strict_types=1);

namespace App\Tests\Voto\Functional;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Permission\Permission;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Entity\Tenant\TenantRolePermission;
use App\Master\Entity\RecursoEstado;
use App\Tests\Factory\Auth\UserFactory;
use App\Tests\Factory\Tenant\TenantFactory;
use App\Tests\Factory\Voto\AssembleiaFactory;
use App\Tests\Factory\Voto\PautaDaAssembleiaFactory;
use App\Tests\Factory\Voto\UnidadeDaAssembleiaFactory;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Voto\Entity\Assembleia;
use App\Voto\Entity\LiberacaoDoVoto;
use App\Voto\Entity\MembroDaEquipe;
use App\Voto\Entity\ParticipacaoNaPauta;
use App\Voto\Entity\PautaDaAssembleia;
use App\Voto\Entity\VotoNominal;
use App\Voto\Enum\CenarioDaAssembleia;
use App\Voto\Enum\PapelNaEquipe;
use App\Voto\Enum\SigiloDoVoto;
use App\Voto\Service\AcessoAAssembleia;
use App\Voto\UseCase\ObterAtaDaAssembleiaUseCase;
use App\Voto\UseCase\RegistrarAuditoriaDaRelacaoDeVotantesUseCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;
use Zenstruck\Foundry\Test\Factories;

/**
 * Relação de votantes da ata (W2-7 bloco B, spec docs/specs/voto-equipe-e-master-1611.md, seção 2): só cenário com
 * lista, só pauta encerrada, ordem alfabética, faixa horária no lugar da hora exata, trilha no `audit_log` e quem vê
 * (sem equipe o módulo; com equipe comissão, auditoria e fiscal, o conselho não), inclusive a relação nominal do voto
 * aberto.
 */
#[CoversClass(ObterAtaDaAssembleiaUseCase::class)]
#[CoversClass(RegistrarAuditoriaDaRelacaoDeVotantesUseCase::class)]
#[CoversClass(AcessoAAssembleia::class)]
#[Group('voto')]
final class RelacaoDeVotantesTest extends JusPrimeWebTestCase
{
    use Factories;

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Tenant $escritorio;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->escritorio = TenantFactory::createOne(['name' => 'Relação do Voto ' . uniqid()])->_real();
        $estado = $this->em->getRepository(RecursoEstado::class)->findOneBy(['chave' => 'voto']);
        if (null !== $estado) {
            $estado->reativar(null, new \DateTimeImmutable());
        }
        $liberacao = new LiberacaoDoVoto($this->escritorio);
        $liberacao->liberar();
        $this->em->persist($liberacao);
        $this->em->flush();
    }

    #[TestDox('Cenário com lista, pauta encerrada: nome, identificação e situação em ordem alfabética, com a faixa horária e sem a hora exata')]
    public function testRelacaoEmOrdemAlfabeticaComFaixaHoraria(): void
    {
        [$assembleia, $pauta] = $this->assembleiaEncerrada(CenarioDaAssembleia::Condominio);
        $this->unidade($assembleia, 'Zélia', 'Z-1', $pauta, '2026-11-20 10:15:42');
        $this->unidade($assembleia, 'Álvaro', 'AL-1', bloco: 'B');
        $this->unidade($assembleia, 'Dora', 'A-1', bloco: 'B');
        $this->unidade($assembleia, 'Ana', 'A-1');
        $this->unidade($assembleia, 'Beto', 'B-1', apta: false);
        $this->unidade($assembleia, 'Carla', 'C-1', $pauta, '2026-11-20 23:59:59');
        $this->entrar($this->colega());

        $ata = $this->client->request('GET', '/voto/assembleias/' . $assembleia->getId() . '/ata');
        $tabela = $ata->filter('#pauta-' . $pauta->getId() . ' table[data-voto-relacao]');
        self::assertCount(1, $tabela);
        self::assertSame(['Nome', 'Unidade', 'Situação'], $tabela->filter('thead th')->each(static fn (Crawler $c): string => trim($c->text())));
        $linhas = $tabela->filter('tbody tr')->each(static fn (Crawler $l): array => $l->filter('td')->each(static fn (Crawler $c): string => trim($c->text())));
        self::assertSame([
            ['Álvaro', 'AL-1', 'Não votou'],
            ['Ana', 'A · A-1', 'Não votou'],
            ['Beto', 'B-1', 'Inapto'],
            ['Carla', 'C-1', 'Votou 23h às 00h'],
            ['Dora', 'B · A-1', 'Não votou'],
            ['Zélia', 'Z-1', 'Votou 10h às 11h'],
        ], $linhas);
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsString('10:15', $html, 'a hora exata não aparece');
        self::assertStringNotContainsString('23:59', $html);
        self::assertStringContainsString('Não indica em quem cada pessoa votou.', $tabela->closest('section')?->text() ?? '');
    }

    #[TestDox('Cenário sem lista (classe, sindicato e demais): a ata não traz a relação nem grava a trilha')]
    public function testCenarioSemListaNaoMostra(): void
    {
        foreach ([CenarioDaAssembleia::Classe, CenarioDaAssembleia::Consulta] as $cenario) {
            [$assembleia, $pauta] = $this->assembleiaEncerrada($cenario);
            $this->unidade($assembleia, 'Ana', 'A-1', $pauta);
            $this->entrar($this->colega());

            $ata = $this->client->request('GET', '/voto/assembleias/' . $assembleia->getId() . '/ata');
            self::assertResponseIsSuccessful();
            self::assertCount(0, $ata->filter('[data-voto-relacao]'), $cenario->value);
            self::assertStringNotContainsString('Relação de votantes', (string) $this->client->getResponse()->getContent(), $cenario->value);
        }
        self::assertSame(0, $this->trilhas());
    }

    #[TestDox('Associação e sociedade também têm lista; o CPF da associação sai mascarado')]
    public function testAssociacaoESociedadeTemLista(): void
    {
        self::assertTrue(CenarioDaAssembleia::Condominio->temListaDeVotantes());
        self::assertTrue(CenarioDaAssembleia::Associacao->temListaDeVotantes());
        self::assertTrue(CenarioDaAssembleia::Sociedade->temListaDeVotantes());
        self::assertFalse(CenarioDaAssembleia::Classe->temListaDeVotantes());
        self::assertFalse(CenarioDaAssembleia::Livre->temListaDeVotantes());

        [$assembleia, $pauta] = $this->assembleiaEncerrada(CenarioDaAssembleia::Associacao);
        $this->unidade($assembleia, 'Ana', '12345678901', $pauta, bloco: '');
        $this->entrar($this->colega());

        $ata = $this->client->request('GET', '/voto/assembleias/' . $assembleia->getId() . '/ata');
        self::assertSame(['Nome', 'Unidade', 'Situação'], $ata->filter('[data-voto-relacao] thead th')->each(static fn (Crawler $c): string => trim($c->text())));
        self::assertSame('***.456.789-**', trim($ata->filter('[data-voto-relacao] tbody tr td')->eq(1)->text()));
    }

    #[TestDox('Pauta não encerrada: sem relação e sem trilha')]
    public function testPautaNaoEncerradaNaoMostra(): void
    {
        $assembleia = AssembleiaFactory::new(['tenant' => $this->escritorio, 'cenario' => CenarioDaAssembleia::Condominio])->publicada()->create()->_real();
        $aberta = PautaDaAssembleiaFactory::new(['assembleia' => $assembleia, 'titulo' => 'Em andamento'])->comOpcoesPadrao()->aberta()->create()->_real();
        $this->unidade($assembleia, 'Ana', 'A-1', $aberta);
        $this->entrar($this->colega());

        $ata = $this->client->request('GET', '/voto/assembleias/' . $assembleia->getId() . '/ata');
        self::assertResponseIsSuccessful();
        self::assertCount(0, $ata->filter('[data-voto-relacao]'));
        self::assertSame(0, $this->trilhas());
    }

    #[TestDox('Cada emissão grava "Relação de votantes emitida" no audit_log, com quem emitiu e as pautas, sem nome de votante')]
    public function testTrilhaGravada(): void
    {
        [$assembleia, $pauta] = $this->assembleiaEncerrada(CenarioDaAssembleia::Condominio);
        $this->unidade($assembleia, 'Ana Votante', 'A-1', $pauta);
        $quem = $this->colega();
        $this->entrar($quem);

        $this->client->request('GET', '/voto/assembleias/' . $assembleia->getId() . '/ata');
        $this->client->request('GET', '/voto/assembleias/' . $assembleia->getId() . '/ata');

        $linhas = $this->em->getConnection()->fetchAllAssociative(
            'SELECT action, entity_class, entity_id, actor_user_id, changes FROM audit_log WHERE action = ? AND tenant_id = ?',
            [RegistrarAuditoriaDaRelacaoDeVotantesUseCase::ACAO, $this->escritorio->getId()],
        );
        self::assertCount(2, $linhas, 'uma linha por emissão');
        self::assertSame(Assembleia::class, $linhas[0]['entity_class']);
        self::assertSame((string) $assembleia->getId(), $linhas[0]['entity_id']);
        self::assertSame($quem->getId(), (int) $linhas[0]['actor_user_id']);
        $mudancas = json_decode((string) $linhas[0]['changes'], true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame('Relação de votantes emitida', $mudancas['evento']);
        self::assertSame([$pauta->getId()], $mudancas['pauta_ids']);
        self::assertStringNotContainsString('Ana Votante', (string) $linhas[0]['changes']);
    }

    #[TestDox('Sem equipe própria quem tem o módulo vê; com equipe, comissão, auditoria e fiscal veem e o conselho não')]
    public function testQuemVeComESemEquipe(): void
    {
        [$assembleia, $pauta] = $this->assembleiaEncerrada(CenarioDaAssembleia::Condominio);
        $this->unidade($assembleia, 'Ana', 'A-1', $pauta);
        $url = '/voto/assembleias/' . $assembleia->getId() . '/ata';

        $semEquipe = $this->colega();
        $primario = $this->primario();
        $papeis = [];
        foreach ([PapelNaEquipe::Comissao, PapelNaEquipe::Auditoria, PapelNaEquipe::Fiscal, PapelNaEquipe::Conselho] as $papel) {
            $papeis[$papel->value] = $this->colega();
        }

        $this->entrar($semEquipe);
        $this->client->request('GET', $url);
        self::assertCount(1, $this->client->getCrawler()->filter('[data-voto-relacao]'), 'sem equipe: quem tem o módulo');

        $assembleia = $this->em->find(Assembleia::class, $assembleia->getId());
        foreach ($papeis as $papel => $pessoa) {
            $this->em->persist(new MembroDaEquipe($assembleia, PapelNaEquipe::from($papel), 'Membro ' . $papel, null, '', $this->em->find(User::class, $pessoa->getId())));
        }
        $this->em->flush();
        $esperado = ['comissao' => 1, 'auditoria' => 1, 'fiscal' => 1, 'conselho' => 0];
        foreach ($papeis as $papel => $pessoa) {
            $this->entrar($pessoa);
            $this->client->request('GET', $url);
            self::assertResponseIsSuccessful($papel);
            self::assertCount($esperado[$papel], $this->client->getCrawler()->filter('[data-voto-relacao]'), $papel);
        }

        $this->entrar($primario);
        $this->client->request('GET', $url);
        self::assertCount(1, $this->client->getCrawler()->filter('[data-voto-relacao]'), 'a Administração sempre');
    }

    #[TestDox('A relação nominal do voto aberto segue a mesma regra de quem vê (ata e Resultados)')]
    public function testNominalDoVotoAbertoSegueARegra(): void
    {
        [$assembleia, $pauta] = $this->assembleiaEncerrada(CenarioDaAssembleia::Condominio, SigiloDoVoto::Aberto);
        $unidade = $this->unidade($assembleia, 'Ana', 'A-1', $pauta);
        $this->em->persist(new VotoNominal($unidade, $pauta, $pauta->getOpcoesDeVoto()[0], null, $unidade->pesoDoVoto(), new \DateTimeImmutable('2026-11-20 10:15:42')));
        $comissao = $this->colega();
        $conselho = $this->colega();
        $this->em->persist(new MembroDaEquipe($assembleia, PapelNaEquipe::Comissao, 'Presidência', null, '', $comissao));
        $this->em->persist(new MembroDaEquipe($assembleia, PapelNaEquipe::Conselho, 'Conselho', null, '', $conselho));
        $this->em->flush();
        $url = '/voto/assembleias/' . $assembleia->getId() . '/ata';

        $this->entrar($comissao);
        $this->client->request('GET', $url);
        self::assertCount(1, $this->client->getCrawler()->filter('[data-voto-nominal]'));
        $this->client->request('GET', '/voto?aba=tra');
        self::assertCount(1, $this->client->getCrawler()->filter('[data-voto-nominal]'));

        $this->entrar($conselho);
        $this->client->request('GET', $url);
        self::assertResponseIsSuccessful();
        self::assertCount(0, $this->client->getCrawler()->filter('[data-voto-nominal]'));
        self::assertCount(0, $this->client->getCrawler()->filter('[data-voto-relacao]'));
        $this->client->request('GET', '/voto?aba=tra');
        self::assertCount(0, $this->client->getCrawler()->filter('[data-voto-nominal]'));
    }

    #[TestDox('A regra pura da equipe: sem linhas vale o módulo; conselho e quem não está na lista não veem')]
    public function testRegraPura(): void
    {
        $linhas = [
            ['assembleia' => 1, 'user' => 10, 'papel' => 'comissao'],
            ['assembleia' => 1, 'user' => 11, 'papel' => 'auditoria'],
            ['assembleia' => 1, 'user' => 12, 'papel' => 'fiscal'],
            ['assembleia' => 1, 'user' => 13, 'papel' => 'conselho'],
            ['assembleia' => 1, 'user' => null, 'papel' => 'comissao'],
        ];
        self::assertTrue(AcessoAAssembleia::relacaoPelaEquipe([], 99));
        foreach ([10, 11, 12] as $id) {
            self::assertTrue(AcessoAAssembleia::relacaoPelaEquipe($linhas, $id));
        }
        self::assertFalse(AcessoAAssembleia::relacaoPelaEquipe($linhas, 13));
        self::assertFalse(AcessoAAssembleia::relacaoPelaEquipe($linhas, 99));
    }

    /** @return array{0: Assembleia, 1: PautaDaAssembleia} */
    private function assembleiaEncerrada(CenarioDaAssembleia $cenario, SigiloDoVoto $sigilo = SigiloDoVoto::Secreto): array
    {
        $assembleia = AssembleiaFactory::new(['tenant' => $this->escritorio, 'cenario' => $cenario, 'titulo' => 'AGO ' . uniqid()])->publicada()->create()->_real();
        $pauta = PautaDaAssembleiaFactory::new(['assembleia' => $assembleia, 'titulo' => 'Contas', 'sigilo' => $sigilo])->comOpcoesPadrao()->encerrada()->create()->_real();

        return [$assembleia, $pauta];
    }

    private function unidade(Assembleia $assembleia, string $nome, string $identificacao, ?PautaDaAssembleia $votouEm = null, string $quando = '2026-11-20 10:00:00', bool $apta = true, string $bloco = 'A'): \App\Voto\Entity\UnidadeDaAssembleia
    {
        $fabrica = UnidadeDaAssembleiaFactory::new();
        $unidade = ($apta ? $fabrica : $fabrica->inapta())->create(['assembleia' => $assembleia, 'responsavel' => $nome, 'identificacao' => $identificacao, 'bloco' => $bloco])->_real();
        if (null !== $votouEm) {
            $this->em->persist(new ParticipacaoNaPauta($unidade, $votouEm, new \DateTimeImmutable($quando)));
            $this->em->flush();
        }

        return $unidade;
    }

    private function trilhas(): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM audit_log WHERE action = ? AND tenant_id = ?', [RegistrarAuditoriaDaRelacaoDeVotantesUseCase::ACAO, $this->escritorio->getId()]);
    }

    private function primario(): User
    {
        return $this->usuarioNoEscritorio(true);
    }

    private function colega(): User
    {
        return $this->usuarioNoEscritorio(false);
    }

    private function usuarioNoEscritorio(bool $sistema): User
    {
        $user = UserFactory::createOne(['fullName' => 'Pessoa ' . uniqid(), 'roles' => ['ROLE_USER']])->_real();
        $papel = (new TenantRole())->setTenant($this->escritorio)->setName('Perfil ' . uniqid())->setIsSystem($sistema);
        $this->em->persist($papel);
        $permissao = $this->em->getRepository(Permission::class)->findOneBy(['code' => 'modules.voto.view']);
        if (null === $permissao) {
            $permissao = (new Permission())->setCode('modules.voto.view')->setDescription('Acesso ao módulo Voto (eleições e votações)')->setGroup('modules');
            $this->em->persist($permissao);
        }
        $concessao = (new TenantRolePermission())->setTenantRole($papel)->setPermission($permissao);
        $this->em->persist($concessao);
        $papel->getTenantRolePermissions()->add($concessao);
        $this->em->persist((new UserTenant($user, $this->escritorio))->setTenantRole($papel));
        $this->em->flush();

        return $user;
    }

    private function entrar(User $user): void
    {
        $this->logarComTenant($this->client, $user, $this->escritorio);
    }
}
