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
use App\Voto\Controller\AssembleiaController;
use App\Voto\Entity\Assembleia;
use App\Voto\Entity\LiberacaoDoVoto;
use App\Voto\Entity\MembroDaEquipe;
use App\Voto\Enum\PapelNaEquipe;
use App\Voto\UseCase\ObterEquipeDaAssembleiaUseCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Zenstruck\Foundry\Test\Factories;

/**
 * Passo 6 do assistente, Equipe (desenho 18, H266; spec docs/specs/voto-equipe-e-master-1611.md, seção 1.3): a
 * Administração escolhe equipe padrão ou própria; os demais veem só leitura; "Indicar pessoas" leva à tela Perfis; e a
 * revisão (passo 7) avisa, sem bloquear, dos cargos sem pessoa indicada.
 */
#[CoversClass(AssembleiaController::class)]
#[CoversClass(ObterEquipeDaAssembleiaUseCase::class)]
final class PassoEquipeDoAssistenteTest extends JusPrimeWebTestCase
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
        $this->escritorio = TenantFactory::createOne(['name' => 'Passo da Equipe ' . uniqid()])->_real();
        $liberacao = new LiberacaoDoVoto($this->escritorio);
        $liberacao->liberar();
        $this->em->persist($liberacao);
        $estado = $this->em->getRepository(RecursoEstado::class)->findOneBy(['chave' => 'voto']);
        $estado?->reativar(null, new \DateTimeImmutable());
        $this->em->flush();
    }

    #[TestDox('Equipe padrão: os dois botões do desenho, a padrão marcada e nenhuma linha de cargo')]
    public function testEquipePadraoSemLinhas(): void
    {
        $a = $this->rascunho();
        $this->logarComTenant($this->client, $this->usuario(true), $this->escritorio);

        $pagina = $this->client->request('GET', '/voto/assembleias/' . $a->getId() . '/passo/6');

        self::assertResponseIsSuccessful();
        self::assertSame('Usar a equipe padrão', trim($pagina->filter('[data-voto-equipe-padrao]')->text()));
        self::assertSame('Equipe própria com os cargos desta categoria', trim($pagina->filter('[data-voto-equipe-propria]')->text()));
        self::assertCount(1, $pagina->filter('.vCats > button.vCatOn[data-voto-equipe-padrao]'));
        self::assertCount(0, $pagina->filter('[data-voto-equipe-lista]'));
        self::assertCount(0, $pagina->filter('[data-voto-indicar-pessoas]'));
    }

    #[TestDox('A Administração escolhe equipe própria e vê os cargos "A designar"; volta à padrão e as linhas somem')]
    public function testAdministracaoEscolheEquipePropriaEVoltaAPadrao(): void
    {
        $a = $this->rascunho();
        $id = (int) $a->getId();
        $this->logarComTenant($this->client, $this->usuario(true), $this->escritorio);

        $pagina = $this->client->request('GET', '/voto/assembleias/' . $id . '/passo/6');
        $this->client->submit($pagina->filter('form[data-voto-equipe-propria]')->form());
        self::assertResponseRedirects('/voto/assembleias/' . $id . '/passo/6');

        $pagina = $this->client->followRedirect();
        $linhas = $pagina->filter('[data-voto-equipe-lista] > .voto-equipe-passo-linha');
        self::assertGreaterThan(0, $linhas->count());
        self::assertCount($linhas->count(), $pagina->filter('.voto-equipe-passo-linha > span:contains("A designar")'));
        self::assertSame('A Administração indica o nome e o e-mail de cada cargo.', trim($pagina->filter('.voto-equipe-passo-indicar > span')->text()));
        self::assertCount(1, $pagina->filter('.vCats > button.vCatOn[data-voto-equipe-propria]'));
        self::assertSame($linhas->count(), $this->contarLinhas($a));

        $this->client->submit($pagina->filter('form[data-voto-equipe-padrao]')->form());
        self::assertResponseRedirects('/voto/assembleias/' . $id . '/passo/6');
        $pagina = $this->client->followRedirect();
        self::assertCount(0, $pagina->filter('[data-voto-equipe-lista]'));
        self::assertCount(1, $pagina->filter('.vCats > button.vCatOn[data-voto-equipe-padrao]'));
        self::assertSame(0, $this->contarLinhas($a));
    }

    #[TestDox('Indicar pessoas leva à tela Perfis com o grupo desta assembleia aberto')]
    public function testIndicarPessoasLevaAPerfis(): void
    {
        $a = $this->rascunho();
        $id = (int) $a->getId();
        $this->em->persist(new MembroDaEquipe($a, PapelNaEquipe::Comissao, MembroDaEquipe::NOME_A_DESIGNAR, null, 'Presidência da mesa', null));
        $this->em->flush();
        $this->logarComTenant($this->client, $this->usuario(true), $this->escritorio);

        $pagina = $this->client->request('GET', '/voto/assembleias/' . $id . '/passo/6');
        self::assertSame('Presidência da mesa', trim($pagina->filter('.voto-equipe-passo-linha > b')->text()));
        $link = $pagina->filter('a[data-voto-indicar-pessoas]');
        self::assertSame('Indicar pessoas', trim($link->text()));
        self::assertSame('/voto/equipe?grupo=' . $id . '#equipe-' . $id, $link->attr('href'));

        $perfis = $this->client->click($link->link());
        self::assertResponseIsSuccessful();
        self::assertCount(1, $perfis->filter('#equipe-' . $id . ' .vPerGOn'));
    }

    #[TestDox('Quem não é a Administração vê só leitura: lista, sem botões que alteram nem "Indicar pessoas"; o POST é 403')]
    public function testComissaoSemSerAdministracaoVeSoLeitura(): void
    {
        $a = $this->rascunho();
        $id = (int) $a->getId();
        $comissao = $this->usuario(false);
        $this->em->persist(new MembroDaEquipe($a, PapelNaEquipe::Comissao, 'Comissária', null, '', $comissao));
        $this->em->persist(new MembroDaEquipe($a, PapelNaEquipe::Fiscal, MembroDaEquipe::NOME_A_DESIGNAR, null, 'Fiscal', null));
        $this->em->flush();
        $this->logarComTenant($this->client, $comissao, $this->escritorio);

        $pagina = $this->client->request('GET', '/voto/assembleias/' . $id . '/passo/6');

        self::assertResponseIsSuccessful();
        self::assertCount(2, $pagina->filter('[data-voto-equipe-lista] > .voto-equipe-passo-linha'));
        self::assertCount(0, $pagina->filter('[data-voto-equipe-passo] form'));
        self::assertCount(0, $pagina->filter('[data-voto-indicar-pessoas]'));
        self::assertCount(1, $pagina->filter('.vCats > button.vCatOn[disabled][data-voto-equipe-propria]'));

        $this->client->request('POST', '/voto/equipe/' . $id . '/padrao', ['_token' => $this->token('voto_equipe'), 'depois' => 'passo-6']);
        self::assertResponseStatusCodeSame(403);
        self::assertSame(2, $this->contarLinhas($a));
    }

    #[TestDox('Assembleia de outro escritório dá 404 no passo 6')]
    public function testOutroEscritorioDa404(): void
    {
        $outro = TenantFactory::createOne(['name' => 'Outro ' . uniqid()])->_real();
        $alheia = AssembleiaFactory::createOne(['tenant' => $outro])->_real();
        $this->logarComTenant($this->client, $this->usuario(true), $this->escritorio);

        $this->client->request('GET', '/voto/assembleias/' . $alheia->getId() . '/passo/6');

        self::assertResponseStatusCodeSame(404);
    }

    #[TestDox('Revisão: cargo sem pessoa indicada é aviso do desenho, não bloqueia, e o "Tudo pronto" some')]
    public function testRevisaoAvisaCargoSemPessoaSemBloquear(): void
    {
        $a = $this->rascunho();
        $id = (int) $a->getId();
        $this->em->persist(new MembroDaEquipe($a, PapelNaEquipe::Comissao, MembroDaEquipe::NOME_A_DESIGNAR, null, 'Presidência', null));
        $this->em->persist(new MembroDaEquipe($a, PapelNaEquipe::Fiscal, MembroDaEquipe::NOME_A_DESIGNAR, null, 'Fiscal', null));
        $this->em->persist(new MembroDaEquipe($a, PapelNaEquipe::Auditoria, 'Pessoa Indicada', 'p@exemplo.invalid', 'Auditoria', null));
        $this->em->flush();
        $this->logarComTenant($this->client, $this->usuario(true), $this->escritorio);

        $pagina = $this->client->request('GET', '/voto/assembleias/' . $id . '/publicar');

        self::assertResponseIsSuccessful();
        self::assertSame('2 cargo(s) ainda sem pessoa indicada pela Administração.', trim($pagina->filter('[data-voto-cargos-sem-pessoa]')->text()));
        self::assertCount(0, $pagina->filter('[data-voto-pronto]'));
        self::assertCount(0, $pagina->filter('[data-voto-pendencia]'));
        self::assertNull($pagina->filter('form[data-voto-confirmar-publicacao] button')->attr('disabled'));
        self::assertSame('Própria, 3 cargos', trim($pagina->filterXPath('//div[contains(@class, "voto-revisao-linha")][span[normalize-space()="Equipe"]]/b')->text()));
    }

    #[TestDox('Revisão com equipe padrão ou cargos todos indicados: "Tudo pronto para publicar."')]
    public function testRevisaoProntaSemPendenciaDeEquipe(): void
    {
        $a = $this->rascunho();
        $id = (int) $a->getId();
        $this->logarComTenant($this->client, $this->usuario(true), $this->escritorio);

        $pagina = $this->client->request('GET', '/voto/assembleias/' . $id . '/publicar');
        self::assertCount(1, $pagina->filter('[data-voto-pronto]'));
        self::assertSame('Equipe padrão', trim($pagina->filterXPath('//div[contains(@class, "voto-revisao-linha")][span[normalize-space()="Equipe"]]/b')->text()));

        $this->em->persist(new MembroDaEquipe($a, PapelNaEquipe::Comissao, 'Pessoa Indicada', 'p@exemplo.invalid', 'Presidência', null));
        $this->em->flush();
        $pagina = $this->client->request('GET', '/voto/assembleias/' . $id . '/publicar');
        self::assertCount(0, $pagina->filter('[data-voto-cargos-sem-pessoa]'));
        self::assertCount(1, $pagina->filter('[data-voto-pronto]'));
    }

    private function token(string $id): string
    {
        $sessao = $this->client->getRequest()->getSession();
        $sessao->set('_csrf/' . $id, 'token-de-teste-' . $id);
        $sessao->save();

        return 'token-de-teste-' . $id;
    }

    private function rascunho(): Assembleia
    {
        $a = AssembleiaFactory::createOne(['tenant' => $this->escritorio, 'titulo' => 'AGO da Equipe'])->_real();
        PautaDaAssembleiaFactory::new(['assembleia' => $a, 'titulo' => 'Aprovação de contas'])->comOpcoesPadrao()->create();
        UnidadeDaAssembleiaFactory::createOne(['assembleia' => $a]);

        return $a;
    }

    private function contarLinhas(Assembleia $a): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM voto.membro_da_equipe WHERE assembleia_id = ?', [$a->getId()]);
    }

    /** `administracao`: perfil de sistema (primário); senão, colega com o módulo `voto`. */
    private function usuario(bool $administracao): User
    {
        $user = UserFactory::createOne(['fullName' => 'Pessoa ' . uniqid(), 'roles' => ['ROLE_USER']])->_real();
        $papel = (new TenantRole())->setTenant($this->escritorio)->setName('Perfil ' . uniqid())->setIsSystem($administracao);
        $this->em->persist($papel);
        if (!$administracao) {
            $permissao = $this->em->getRepository(Permission::class)->findOneBy(['code' => 'modules.voto.view']);
            if (null === $permissao) {
                $permissao = (new Permission())->setCode('modules.voto.view')->setDescription('Acesso ao módulo Voto (eleições e votações)')->setGroup('modules');
                $this->em->persist($permissao);
            }
            $concessao = (new TenantRolePermission())->setTenantRole($papel)->setPermission($permissao);
            $this->em->persist($concessao);
            $papel->getTenantRolePermissions()->add($concessao);
        }
        $this->em->persist((new UserTenant($user, $this->escritorio))->setTenantRole($papel));
        $this->em->flush();

        return $user;
    }
}
