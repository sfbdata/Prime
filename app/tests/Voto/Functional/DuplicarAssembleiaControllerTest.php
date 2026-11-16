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
use App\Voto\Controller\DuplicarAssembleiaController;
use App\Voto\Entity\LiberacaoDoVoto;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Zenstruck\Foundry\Test\Factories;

/** Duplicar votação pela tela: POST com CSRF cria o rascunho e abre as pautas; outro escritório dá 404; token inválido não cria. */
#[CoversClass(DuplicarAssembleiaController::class)]
final class DuplicarAssembleiaControllerTest extends JusPrimeWebTestCase
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
        $this->escritorio = TenantFactory::createOne(['name' => 'Condomínio do Voto ' . uniqid()])->_real();
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

    #[TestDox('O menu de três pontos do cartão tem "Duplicar votação" e o envio pelo menu cria a cópia')]
    public function testDuplicaPeloMenuDoPainel(): void
    {
        $origem = AssembleiaFactory::createOne(['tenant' => $this->escritorio, 'titulo' => 'AGE Obras'])->_real();
        $id = $origem->getId();

        $painel = $this->client->request('GET', '/voto?aba=ges');
        $botao = $painel->filter('#assembleia-' . $id . ' > .vEvH .vKw > .vKm > form > button')->reduce(static fn ($c): bool => 'Duplicar votação' === trim($c->text()));
        self::assertCount(1, $botao);
        $this->client->submit($botao->form());

        $copia = $this->em->getConnection()->fetchAssociative('SELECT id, titulo FROM voto.assembleia WHERE tenant_id = ? AND id <> ?', [$this->escritorio->getId(), $id]);
        self::assertIsArray($copia);
        self::assertSame('AGE Obras (cópia)', $copia['titulo']);
        self::assertResponseRedirects('/voto/assembleias/' . $copia['id'] . '/pautas');
    }

    #[TestDox('POST com CSRF cria um rascunho com " (cópia)", copia pautas e unidades e redireciona para as pautas da cópia')]
    public function testDuplicaERedireciona(): void
    {
        $origem = AssembleiaFactory::new(['tenant' => $this->escritorio, 'titulo' => 'AGO 2026'])->publicada()->create()->_real();
        PautaDaAssembleiaFactory::new(['assembleia' => $origem])->comOpcoesPadrao()->create();
        UnidadeDaAssembleiaFactory::createOne(['assembleia' => $origem, 'email' => 'sindica@example.com']);
        $id = $origem->getId();

        $this->client->request('POST', '/voto/assembleias/' . $id . '/duplicar', ['_token' => $this->tokenValido('voto_assembleia_' . $id)]);

        $banco = $this->em->getConnection();
        $copia = $banco->fetchAssociative('SELECT id, titulo, estado FROM voto.assembleia WHERE tenant_id = ? AND id <> ?', [$this->escritorio->getId(), $id]);
        self::assertIsArray($copia);
        self::assertSame('AGO 2026 (cópia)', $copia['titulo']);
        self::assertSame('rascunho', $copia['estado']);
        self::assertResponseRedirects('/voto/assembleias/' . $copia['id'] . '/pautas');
        self::assertSame(1, (int) $banco->fetchOne('SELECT COUNT(*) FROM voto.assembleia_pauta WHERE assembleia_id = ?', [$copia['id']]));
        self::assertSame(1, (int) $banco->fetchOne('SELECT COUNT(*) FROM voto.assembleia_unidade WHERE assembleia_id = ?', [$copia['id']]));
        self::assertSame('publicada', $banco->fetchOne('SELECT estado FROM voto.assembleia WHERE id = ?', [$id]), 'o original não mudou');
        self::assertNull($banco->fetchOne('SELECT email FROM voto.assembleia_unidade WHERE assembleia_id = ?', [$copia['id']]));
        self::assertSame(
            'Cópia criada como rascunho, sem votos. Confira nome, evento e datas. Lista de aptos copiada, sem os e-mails (reimporte a planilha para o acesso por código).',
            trim($this->client->followRedirect()->filter('.voto-toast')->text()),
        );
    }

    #[TestDox('Assembleia de outro escritório dá 404 e nada é criado')]
    public function testOutroEscritorioDa404(): void
    {
        $alheia = AssembleiaFactory::new()->publicada()->create()->_real();
        $id = $alheia->getId();
        $antes = (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM voto.assembleia');

        $this->client->request('POST', '/voto/assembleias/' . $id . '/duplicar', ['_token' => $this->tokenValido('voto_assembleia_' . $id)]);

        self::assertResponseStatusCodeSame(404);
        self::assertSame($antes, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM voto.assembleia'));
    }

    #[TestDox('Token inválido não duplica: volta ao painel com aviso')]
    public function testTokenInvalidoNaoDuplica(): void
    {
        $origem = AssembleiaFactory::createOne(['tenant' => $this->escritorio])->_real();
        $id = $origem->getId();

        $this->client->request('POST', '/voto/assembleias/' . $id . '/duplicar', ['_token' => 'invalido']);

        self::assertResponseRedirects();
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM voto.assembleia WHERE tenant_id = ?', [$this->escritorio->getId()]));
    }

    #[TestDox('GET não é aceito: só POST duplica')]
    public function testGetNaoDuplica(): void
    {
        $origem = AssembleiaFactory::createOne(['tenant' => $this->escritorio])->_real();

        $this->client->request('GET', '/voto/assembleias/' . $origem->getId() . '/duplicar');

        self::assertResponseStatusCodeSame(405);
    }

    /** Grava na sessão um token CSRF conhecido, como o SessionTokenStorage (ver AssembleiaGestorTest::tokenValido). */
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
