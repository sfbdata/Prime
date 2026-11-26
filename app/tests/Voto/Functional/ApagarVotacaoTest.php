<?php

declare(strict_types=1);

namespace App\Tests\Voto\Functional;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Master\Entity\RecursoEstado;
use App\Tests\Factory\Auth\UserFactory;
use App\Tests\Factory\Tenant\TenantFactory;
use App\Tests\Factory\Voto\AssembleiaFactory;
use App\Tests\Factory\Voto\PautaDaAssembleiaFactory;
use App\Tests\Factory\Voto\UnidadeDaAssembleiaFactory;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Voto\Controller\CicloDaPautaController;
use App\Voto\Entity\Assembleia;
use App\Voto\Entity\LiberacaoDoVoto;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Zenstruck\Foundry\Test\Factories;

/**
 * "Apagar votação" da assembleia sem pauta aberta ou programada (POST /voto/assembleias/{id}/apagar): CSRF, confirmação digitada (nome ou
 * APAGAR), recusa com votação aberta, tudo some junto e outro escritório dá 404. O item do menu só aparece quando a
 * votação pode ser apagada.
 */
#[CoversClass(CicloDaPautaController::class)]
#[Group('voto')]
final class ApagarVotacaoTest extends JusPrimeWebTestCase
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
        $this->escritorio = TenantFactory::createOne(['name' => 'Condomínio do Apagar ' . uniqid()])->_real();
        $estado = $this->em->getRepository(RecursoEstado::class)->findOneBy(['chave' => 'voto']);
        if (null !== $estado) {
            $estado->reativar(null, new \DateTimeImmutable());
        }
        $liberacao = new LiberacaoDoVoto($this->escritorio);
        $liberacao->liberar();
        $this->em->persist($liberacao);
        $this->em->flush();
        $this->logarComTenant($this->client, $this->usuario(), $this->escritorio);
    }

    #[TestDox('Encerrada: com o nome digitado apaga a assembleia, as pautas, as unidades e os votos, e avisa')]
    public function testApagaComONome(): void
    {
        $assembleia = $this->encerrada('AGO Final');
        $id = $assembleia->getId();

        $this->postar($id, 'ago final');

        self::assertResponseRedirects('/voto?aba=ges#assembleia-' . $id);
        $banco = $this->em->getConnection();
        self::assertFalse($banco->fetchOne('SELECT id FROM voto.assembleia WHERE id = ?', [$id]));
        self::assertSame(0, (int) $banco->fetchOne('SELECT COUNT(*) FROM voto.assembleia_pauta WHERE assembleia_id = ?', [$id]));
        self::assertSame(0, (int) $banco->fetchOne('SELECT COUNT(*) FROM voto.assembleia_unidade WHERE assembleia_id = ?', [$id]));
        self::assertSame('Votação apagada para sempre.', $this->avisoDoPainel());
        self::assertGreaterThan(0, (int) $banco->fetchOne("SELECT COUNT(*) FROM audit_log WHERE entity_class = ? AND entity_id = ? AND action = 'delete'", [Assembleia::class, (string) $id]), 'a exclusão fica no audit_log');
    }

    #[TestDox('Encerrada: a palavra APAGAR também confirma')]
    public function testApagaComAPalavra(): void
    {
        $id = $this->encerrada('AGO Palavra')->getId();

        $this->postar($id, 'APAGAR');

        self::assertFalse($this->em->getConnection()->fetchOne('SELECT id FROM voto.assembleia WHERE id = ?', [$id]));
    }

    #[TestDox('Confirmação vazia ou errada: avisa e nada é apagado')]
    public function testConfirmacaoErrada(): void
    {
        $id = $this->encerrada('AGO Errada')->getId();

        foreach (['', 'outra coisa'] as $digitado) {
            $this->postar($id, $digitado);
            self::assertSame('Para apagar, digite o nome da assembleia ou APAGAR.', $this->avisoDoPainel());
        }
        self::assertNotFalse($this->em->getConnection()->fetchOne('SELECT id FROM voto.assembleia WHERE id = ?', [$id]));
    }

    #[TestDox('Com votação aberta ou suspensa: recusa e nada é apagado')]
    public function testRecusas(): void
    {
        $aberta = AssembleiaFactory::new(['tenant' => $this->escritorio, 'titulo' => 'AGO Aberta'])->publicada()->create()->_real();
        PautaDaAssembleiaFactory::new(['assembleia' => $aberta])->comOpcoesPadrao()->aberta()->create();

        $this->postar($aberta->getId(), 'APAGAR');
        self::assertSame('Só votação sem pauta aberta ou programada pode ser apagada.', $this->avisoDoPainel());

        self::assertNotFalse($this->em->getConnection()->fetchOne('SELECT id FROM voto.assembleia WHERE id = ?', [$aberta->getId()]));
    }

    #[TestDox('Nunca aberta, arquivada ou sem pauta: apaga; a programada para abrir recusa')]
    public function testNaoAtivaApaga(): void
    {
        $espera = AssembleiaFactory::new(['tenant' => $this->escritorio, 'titulo' => 'AGO Espera'])->publicada()->create()->_real();
        PautaDaAssembleiaFactory::new(['assembleia' => $espera])->comOpcoesPadrao()->create();
        $semPauta = AssembleiaFactory::new(['tenant' => $this->escritorio, 'titulo' => 'AGO Sem Pauta'])->publicada()->create()->_real();
        $programada = AssembleiaFactory::new(['tenant' => $this->escritorio, 'titulo' => 'AGO Programada'])->publicada()->create()->_real();
        $pautaProgramada = PautaDaAssembleiaFactory::new(['assembleia' => $programada])->comOpcoesPadrao()->create()->_real();
        $this->em->getConnection()->executeStatement("UPDATE voto.assembleia_pauta SET abre_em = now() + interval '1 day', encerra_em = now() + interval '2 days' WHERE id = ?", [$pautaProgramada->getId()]);
        $this->em->clear();

        $this->postar($programada->getId(), 'APAGAR');
        self::assertSame('Só votação sem pauta aberta ou programada pode ser apagada.', $this->avisoDoPainel());
        self::assertNotFalse($this->em->getConnection()->fetchOne('SELECT id FROM voto.assembleia WHERE id = ?', [$programada->getId()]));

        foreach ([$espera, $semPauta] as $a) {
            $this->postar($a->getId(), 'APAGAR');
            self::assertSame('Votação apagada para sempre.', $this->avisoDoPainel());
            self::assertFalse($this->em->getConnection()->fetchOne('SELECT id FROM voto.assembleia WHERE id = ?', [$a->getId()]));
        }
    }

    #[TestDox('CSRF inválido: aviso de sessão expirada e nada é apagado')]
    public function testCsrfInvalido(): void
    {
        $id = $this->encerrada('AGO Csrf')->getId();

        $this->client->request('POST', '/voto/assembleias/' . $id . '/apagar', ['_token' => 'invalido', 'confirmacao' => 'APAGAR']);

        self::assertSame('A sessão expirou. Recarregue a página e tente de novo.', $this->avisoDoPainel());
        self::assertNotFalse($this->em->getConnection()->fetchOne('SELECT id FROM voto.assembleia WHERE id = ?', [$id]));
    }

    #[TestDox('Assembleia de outro escritório dá 404 e não é apagada; GET não é aceito')]
    public function testOutroEscritorio(): void
    {
        $alheia = AssembleiaFactory::new(['titulo' => 'AGO Alheia'])->publicada()->create()->_real();
        PautaDaAssembleiaFactory::new(['assembleia' => $alheia])->comOpcoesPadrao()->encerrada()->create();

        $this->postar($alheia->getId(), 'APAGAR');
        self::assertResponseStatusCodeSame(404);
        self::assertNotFalse($this->em->getConnection()->fetchOne('SELECT id FROM voto.assembleia WHERE id = ?', [$alheia->getId()]));

        $this->client->request('GET', '/voto/assembleias/' . $alheia->getId() . '/apagar');
        self::assertResponseStatusCodeSame(405);
    }

    #[TestDox('Menu de três pontos: "Apagar votação" quando não há pauta aberta, com a janela que pede o nome; na aberta não aparece')]
    public function testMenuEJanela(): void
    {
        $encerrada = $this->encerrada('AGO Menu');
        $aberta = AssembleiaFactory::new(['tenant' => $this->escritorio, 'titulo' => 'AGO Menu Aberta'])->publicada()->create()->_real();
        PautaDaAssembleiaFactory::new(['assembleia' => $aberta])->comOpcoesPadrao()->aberta()->create();

        $tela = $this->client->request('GET', '/voto?aba=ges');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $tela->filter('[data-voto-janela="#janela-apagar-' . $encerrada->getId() . '"]'));
        self::assertCount(0, $tela->filter('[data-voto-janela="#janela-apagar-' . $aberta->getId() . '"]'));
        $janela = $tela->filter('#janela-apagar-' . $encerrada->getId());
        self::assertCount(1, $janela);
        self::assertStringContainsString('somem para sempre', $janela->text());
        self::assertCount(1, $janela->filter('form[action="/voto/assembleias/' . $encerrada->getId() . '/apagar"] input[name="confirmacao"]'));
    }

    private function encerrada(string $titulo): Assembleia
    {
        $assembleia = AssembleiaFactory::new(['tenant' => $this->escritorio, 'titulo' => $titulo])->publicada()->create()->_real();
        PautaDaAssembleiaFactory::new(['assembleia' => $assembleia])->comOpcoesPadrao()->encerrada()->create();
        UnidadeDaAssembleiaFactory::createOne(['assembleia' => $assembleia]);

        return $assembleia;
    }

    private function postar(?int $id, string $confirmacao): void
    {
        $this->client->request('POST', '/voto/assembleias/' . $id . '/apagar', ['_token' => $this->tokenValido('voto_assembleia_' . $id), 'confirmacao' => $confirmacao]);
    }

    private function avisoDoPainel(): string
    {
        if (!$this->client->getResponse()->isRedirect()) {
            return '';
        }
        $tela = $this->client->followRedirect();

        return trim($tela->filter('.voto-toast')->count() > 0 ? $tela->filter('.voto-toast')->first()->text() : '');
    }

    /** Grava na sessão um token CSRF conhecido (ver CicloDaPautaControllerTest::tokenValido). */
    private function tokenValido(string $id): string
    {
        $this->client->request('GET', '/voto?aba=ele');
        $sessao = $this->client->getRequest()->getSession();
        $sessao->set('_csrf/' . $id, 'token-de-teste-' . $id);
        $sessao->save();

        return 'token-de-teste-' . $id;
    }

    private function usuario(): User
    {
        $user = UserFactory::createOne(['fullName' => 'Síndica Teste', 'roles' => ['ROLE_USER']])->_real();
        $papel = (new TenantRole())->setTenant($this->escritorio)->setName('Perfil do Voto ' . uniqid())->setIsSystem(true);
        $this->em->persist($papel);
        $this->em->persist((new UserTenant($user, $this->escritorio))->setTenantRole($papel));
        $this->em->flush();

        return $user;
    }
}
