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
use App\Voto\Controller\UnidadesDaAssembleiaController;
use App\Voto\Entity\Assembleia;
use App\Voto\Entity\LiberacaoDoVoto;
use App\Voto\Entity\UnidadeDaAssembleia;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Zenstruck\Foundry\Test\Factories;

/**
 * Lista de aptos (B2): aviso da soma das frações (na lista e na conferência) e a ordem "unidade · bloco" do desenho.
 */
#[CoversClass(UnidadesDaAssembleiaController::class)]
final class UnidadesDaAssembleiaControllerTest extends JusPrimeWebTestCase
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
        $this->escritorio = TenantFactory::createOne(['name' => 'Condomínio das Unidades ' . uniqid()])->_real();
        $estado = $this->em->getRepository(RecursoEstado::class)->findOneBy(['chave' => 'voto']);
        if (null !== $estado) {
            $estado->reativar(null, new \DateTimeImmutable());
            $this->em->flush();
        }
    }

    #[TestDox('Soma das frações: avisa na lista e na conferência quando não fecha 1 nem 100%; fechando, não avisa')]
    public function testSomaDasFracoes(): void
    {
        $this->liberar();
        $this->entrar();
        $a = AssembleiaFactory::new(['tenant' => $this->escritorio])->porFracao()->create()->_real();
        UnidadeDaAssembleiaFactory::createOne(['assembleia' => $a, 'identificacao' => '1', 'fracao' => '0.600000']);
        UnidadeDaAssembleiaFactory::createOne(['assembleia' => $a, 'identificacao' => '2', 'fracao' => '0.300000', 'situacao' => 'inadimplente']);

        $lista = $this->client->request('GET', $this->base($a));
        self::assertSame('A soma das frações da lista é 0,9; o esperado é 1 (ou 100%). Confira a planilha.', trim($lista->filter('[data-voto-soma-fracoes]')->text()));
        $conferencia = $this->client->request('GET', '/voto/assembleias/' . $a->getId() . '/publicar');
        self::assertSame('A soma das frações da lista é 0,9; o esperado é 1 (ou 100%). Confira a planilha.', trim($conferencia->filter('[data-voto-soma-fracoes]')->text()));

        UnidadeDaAssembleiaFactory::createOne(['assembleia' => $a, 'identificacao' => '3', 'fracao' => '0.100000']);
        $lista = $this->client->request('GET', $this->base($a));
        self::assertCount(0, $lista->filter('[data-voto-soma-fracoes]'), 'a lista inteira (aptas e não aptas) fecha 1');
    }

    #[TestDox('Lista mista (apta com CPF e apta sem): avisa quantas estão sem CPF; sem CPF nenhum ou todas com, não avisa')]
    public function testAvisoDeUnidadeAptaSemCpf(): void
    {
        [$a] = $this->cenario();
        UnidadeDaAssembleiaFactory::createOne(['assembleia' => $a, 'identificacao' => '1']);
        $lista = $this->client->request('GET', $this->base($a));
        self::assertCount(0, $lista->filter('[data-voto-cpf-faltando]'), 'lista sem CPF nenhum segue no modo antigo, sem aviso');

        UnidadeDaAssembleiaFactory::createOne(['assembleia' => $a, 'identificacao' => '2', 'cpf' => '52998224725']);
        UnidadeDaAssembleiaFactory::createOne(['assembleia' => $a, 'identificacao' => '3', 'situacao' => 'inadimplente']);
        $lista = $this->client->request('GET', $this->base($a));
        self::assertSame(
            '1 unidade apta está sem CPF. Enquanto faltar, o portal do eleitor pede nome, unidade e bloco, e não nome e CPF.',
            trim($lista->filter('[data-voto-cpf-faltando]')->text()),
            'a inapta sem CPF não conta',
        );
    }

    #[TestDox('Peso por unidade: não há aviso de soma')]
    public function testSemAvisoNoPesoPorUnidade(): void
    {
        [$a] = $this->cenario();
        UnidadeDaAssembleiaFactory::createOne(['assembleia' => $a, 'fracao' => '0.100000']);

        $lista = $this->client->request('GET', $this->base($a));
        self::assertCount(0, $lista->filter('[data-voto-soma-fracoes]'));
    }

    #[TestDox('Ordem do desenho: a unidade e depois o bloco')]
    public function testOrdemUnidadeBloco(): void
    {
        [$a] = $this->cenario();
        UnidadeDaAssembleiaFactory::createOne(['assembleia' => $a, 'identificacao' => 'A-101', 'bloco' => 'Bloco A']);

        $lista = $this->client->request('GET', $this->base($a));
        self::assertSame('A-101 · Bloco A', trim($lista->filter('.voto-unidade-dados > b')->text()));
    }

    #[TestDox('Rótulos renomeados aparecem no cabeçalho da lista; sem rótulo, Unidade e Bloco')]
    public function testCabecalhoComRotulos(): void
    {
        [$a] = $this->cenario();
        UnidadeDaAssembleiaFactory::createOne(['assembleia' => $a, 'identificacao' => '1']);
        $lista = $this->client->request('GET', $this->base($a));
        self::assertSame('Unidade · Bloco', trim($lista->filter('[data-voto-cabecalho-unidades]')->text()));

        $a->definirRotulos('Matrícula', 'Seção');
        $this->em->flush();
        $lista = $this->client->request('GET', $this->base($a));
        self::assertSame('Matrícula · Seção', trim($lista->filter('[data-voto-cabecalho-unidades]')->text()));
    }

    #[TestDox('O formulário da assembleia grava os nomes dos campos e os mostra preenchidos na edição')]
    public function testFormularioGravaRotulos(): void
    {
        [$a] = $this->cenario();
        $crawler = $this->client->request('GET', '/voto/assembleias/' . $a->getId() . '/editar');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('input[name="rotulo_unidade"][maxlength="40"][placeholder="Unidade"]'));
        $this->client->submit($crawler->selectButton('Salvar e sair')->form(['rotulo_unidade' => 'Matrícula', 'rotulo_bloco' => 'Seção']));
        $this->em->clear();
        $gravada = $this->em->find(Assembleia::class, $a->getId());
        self::assertSame('Matrícula', $gravada->getRotuloUnidade());
        self::assertSame('Seção', $gravada->getRotuloBloco());
        $crawler = $this->client->request('GET', '/voto/assembleias/' . $a->getId() . '/editar');
        self::assertSame('Matrícula', $crawler->filter('input[name="rotulo_unidade"]')->attr('value'));
    }

    #[TestDox('Menu ⋮ da linha tem "Editar"; salvar grava o cadastro e mantém o link')]
    public function testEditarUnidade(): void
    {
        [$a] = $this->cenario();
        $u = UnidadeDaAssembleiaFactory::createOne(['assembleia' => $a, 'identificacao' => '101', 'bloco' => 'A'])->_real();
        $id = $u->getId();
        $semente = $u->getSemente();

        $lista = $this->client->request('GET', $this->base($a));
        self::assertSame('Editar', trim($lista->filter('#unidade-' . $id . ' .vKm [data-voto-revelar]')->text()));
        self::assertCount(1, $lista->filter('#editar-unidade-' . $id . '[hidden]'));

        $token = (string) $lista->filter('#editar-unidade-' . $id . ' input[name="_token"]')->attr('value');
        $this->client->request('POST', $this->base($a) . '/' . $id . '/editar', [
            '_token' => $token, 'identificacao' => '102', 'bloco' => 'B', 'situacao' => '', 'fracao' => '',
            'responsavel' => 'Maria Souza', 'email' => 'maria@exemplo.com', 'telefone' => '(11) 99999-0000', 'cpf' => '529.982.247-25',
        ]);
        self::assertResponseRedirects($this->base($a) . '#unidade-' . $id);

        $this->em->clear();
        $depois = $this->em->getRepository(UnidadeDaAssembleia::class)->find($id);
        self::assertSame('102', $depois->getIdentificacao());
        self::assertSame('Maria Souza', $depois->getResponsavel());
        self::assertSame('11999990000', $depois->getTelefone());
        self::assertSame('52998224725', $depois->getCpf(), 'o CPF que faltava se corrige pelo Editar');
        self::assertSame($semente, $depois->getSemente());
    }

    #[TestDox('Editar com dado inválido não grava; sem token válido não grava')]
    public function testEditarInvalidoOuSemToken(): void
    {
        [$a] = $this->cenario();
        $u = UnidadeDaAssembleiaFactory::createOne(['assembleia' => $a, 'identificacao' => '101', 'bloco' => 'A'])->_real();
        $id = $u->getId();
        $token = (string) $this->client->request('GET', $this->base($a))->filter('#editar-unidade-' . $id . ' input[name="_token"]')->attr('value');
        $base = ['identificacao' => '999', 'bloco' => 'A', 'situacao' => '', 'fracao' => '', 'responsavel' => '', 'email' => '', 'telefone' => ''];

        $this->client->request('POST', $this->base($a) . '/' . $id . '/editar', ['_token' => $token, ...$base, 'email' => 'sem-arroba']);
        $this->client->request('POST', $this->base($a) . '/' . $id . '/editar', ['_token' => 'falso', ...$base]);

        $this->em->clear();
        self::assertSame('101', $this->em->getRepository(UnidadeDaAssembleia::class)->find($id)->getIdentificacao());
    }

    #[TestDox('Depois da abertura da votação o item Editar fica desabilitado com o motivo e o POST não grava')]
    public function testEditarTravadaDepoisDaAbertura(): void
    {
        [$a] = $this->cenario();
        $u = UnidadeDaAssembleiaFactory::createOne(['assembleia' => $a, 'identificacao' => '101', 'bloco' => 'A'])->_real();
        PautaDaAssembleiaFactory::new(['assembleia' => $a])->comOpcoesPadrao()->aberta()->create();
        $id = $u->getId();

        $lista = $this->client->request('GET', $this->base($a));
        $item = $lista->filter('#unidade-' . $id . ' .vKm button[disabled]');
        self::assertCount(1, $item);
        self::assertSame('A lista fica bloqueada depois da abertura da votação.', $item->attr('title'));
        self::assertCount(0, $lista->filter('#editar-unidade-' . $id));

        $token = (string) $lista->filter('#unidade-' . $id . ' input[name="_token"]')->attr('value');
        $this->client->request('POST', $this->base($a) . '/' . $id . '/editar', ['_token' => $token, 'identificacao' => '999']);
        $this->em->clear();
        self::assertSame('101', $this->em->getRepository(UnidadeDaAssembleia::class)->find($id)->getIdentificacao());
    }

    #[TestDox('Unidade de outro escritório (ou de outra assembleia): 404 e nada muda')]
    public function testEditarOutroEscritorio404(): void
    {
        [$a] = $this->cenario();
        $outro = TenantFactory::createOne(['name' => 'Outro ' . uniqid()])->_real();
        $alheia = AssembleiaFactory::new(['tenant' => $outro])->create()->_real();
        $deles = UnidadeDaAssembleiaFactory::createOne(['assembleia' => $alheia, 'identificacao' => '777'])->_real();
        $minha = UnidadeDaAssembleiaFactory::createOne(['assembleia' => $a, 'identificacao' => '101'])->_real();
        $token = (string) $this->client->request('GET', $this->base($a))->filter('#editar-unidade-' . $minha->getId() . ' input[name="_token"]')->attr('value');
        $idDeles = $deles->getId();

        // (A assembleia alheia com token próprio dela é coberta em AssembleiaGestorTest.)
        // Unidade alheia na minha assembleia.
        $this->client->request('POST', $this->base($a) . '/' . $idDeles . '/editar', ['_token' => $token, 'identificacao' => '1']);
        self::assertResponseStatusCodeSame(404);

        // Pelo banco: o filtro de tenant da sessão esconde as linhas do outro escritório do EntityManager.
        self::assertSame('777', $this->em->getConnection()->fetchOne('SELECT identificacao FROM voto.assembleia_unidade WHERE id = ?', [$idDeles]));
    }

    /** @return array{Assembleia} */
    private function cenario(): array
    {
        $this->liberar();
        $this->entrar();
        $a = AssembleiaFactory::new(['tenant' => $this->escritorio])->create()->_real();

        return [$a];
    }

    private function base(Assembleia $a): string
    {
        return '/voto/assembleias/' . $a->getId() . '/unidades';
    }

    private function liberar(): void
    {
        $liberacao = new LiberacaoDoVoto($this->escritorio);
        $liberacao->liberar();
        $this->em->persist($liberacao);
        $this->em->flush();
    }

    private function entrar(): void
    {
        $user = $this->usuario();
        $this->logarComTenant($this->client, $user, $this->escritorio);
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
