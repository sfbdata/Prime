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
use App\Tests\Factory\Voto\UnidadeDaAssembleiaFactory;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Voto\Controller\UnidadesDaAssembleiaController;
use App\Voto\Entity\Assembleia;
use App\Voto\Entity\LiberacaoDoVoto;
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
