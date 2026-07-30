<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Functional;

use App\Controller\TenantController;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Ponto\Controller\PontoController;
use App\Ponto\Entity\LancamentoHorasPagas;
use App\Ponto\Entity\RegistroPonto;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * A linha "Horas pagas" no rodapé da folha (Tarefa 6): aparece só quando há lançamento na
 * competência exibida, some por completo quando não há, e não quebra a ficha do admin — que
 * inclui o MESMO partial `_folha_table.html.twig` (armadilha central desta tarefa: o partial é
 * incluído tanto por `ponto/index.html.twig` quanto por `tenant/edit_user_role.html.twig`, cada
 * um com o seu próprio conjunto de variáveis passadas via `only`).
 */
#[CoversClass(PontoController::class)]
#[CoversClass(TenantController::class)]
final class HorasPagasFolhaExibicaoTest extends JusPrimeWebTestCase
{
    #[TestDox('colaborador com lancamento na competencia do mes ve a linha Horas pagas com o sinal certo')]
    public function testColaboradorComLancamentoVeALinha(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $colaborador = $this->criarColaborador($tenant);
        $autor = $this->criarColaborador($tenant);
        $agora = new \DateTimeImmutable();
        $ano = (int) $agora->format('Y');
        $mes = (int) $agora->format('n');

        $this->criarLancamento($tenant, $colaborador, $autor, $ano, $mes, -600);

        $this->logarComTenant($client, $colaborador, $tenant);
        $client->request('GET', '/ponto/');

        self::assertResponseIsSuccessful();
        $conteudo = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Horas pagas', $conteudo, 'a linha tem de aparecer quando há lançamento na competência');
        self::assertStringContainsString('-10h00m', $conteudo, 'o sinal e o valor têm de bater com o lançamento (-600 minutos)');
    }

    #[TestDox('colaborador sem lancamento na competencia do mes NAO ve a linha Horas pagas')]
    public function testColaboradorSemLancamentoNaoVeALinha(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $colaborador = $this->criarColaborador($tenant);

        $this->logarComTenant($client, $colaborador, $tenant);
        $client->request('GET', '/ponto/');

        self::assertResponseIsSuccessful();
        $conteudo = (string) $client->getResponse()->getContent();
        self::assertStringNotContainsString('Horas pagas', $conteudo, 'sem lançamento a tela tem de ficar igual à de antes desta tarefa');
    }

    #[TestDox('ficha do admin (mesmo partial compartilhado) continua carregando com lancamento na competencia')]
    public function testFichaDoAdminContinuaCarregando(): void
    {
        // Armadilha central da Tarefa 6: `_folha_table.html.twig` é incluído tanto por
        // ponto/index.html.twig quanto por tenant/edit_user_role.html.twig, cada include com
        // `only` e o seu próprio conjunto de variáveis. Se só um dos dois passasse
        // `horasPagasMinutos`, este segundo render quebraria (ou renderizaria errado) em silêncio.
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $admin = $this->criarAdmin($tenant);
        $colaborador = $this->criarColaborador($tenant);
        $autor = $this->criarColaborador($tenant);
        $agora = new \DateTimeImmutable();
        $ano = (int) $agora->format('Y');
        $mes = (int) $agora->format('n');

        $this->criarRegistro($colaborador, $tenant, $agora->format('Y-m-d') . ' 09:00:00');
        $this->criarLancamento($tenant, $colaborador, $autor, $ano, $mes, 480);

        $this->logarComTenant($client, $admin, $tenant);
        $client->request('GET', sprintf('/tenant/%d/user/%d/edit-role', $tenant->getId(), $colaborador->getId()));

        self::assertResponseIsSuccessful('a ficha do admin não pode quebrar quando a folha do colaborador ganha o rodapé de horas pagas');
        $conteudo = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Horas pagas', $conteudo, 'a mesma linha também deve aparecer na aba de batidas da ficha do admin');
        self::assertStringContainsString('+8h00m', $conteudo, 'o lançamento positivo (acrescentar) tem de aparecer com o sinal certo');
    }

    // ----------------------------------------------------------------- helpers

    private function criarTenant(): Tenant
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $tenant = new Tenant();
        $tenant->setName('Tenant HORAS PAGAS FOLHA ' . uniqid());
        $em->persist($tenant);
        $em->flush();

        return $tenant;
    }

    private function criarColaborador(Tenant $tenant): User
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $user = new User();
        $user->setEmail('colab_folha_' . uniqid() . '@test.com');
        $user->setFullName('Colaborador Folha Horas Pagas');
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $em->persist($user);

        $role = new TenantRole();
        $role->setTenant($tenant);
        $role->setName('Perfil ' . uniqid());
        $role->setIsSystem(true);
        $em->persist($role);

        $userTenant = new UserTenant($user, $tenant);
        $userTenant->setTenantRole($role);
        $em->persist($userTenant);
        $em->flush();

        return $user;
    }

    private function criarAdmin(Tenant $tenant): User
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $user = new User();
        $user->setEmail('admin_folha_' . uniqid() . '@test.com');
        $user->setFullName('Admin Folha Horas Pagas');
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $em->persist($user);

        $role = new TenantRole();
        $role->setTenant($tenant);
        $role->setName('Administrador ' . uniqid());
        $role->setIsSystem(true);
        $em->persist($role);

        $userTenant = new UserTenant($user, $tenant);
        $userTenant->setTenantRole($role);
        $em->persist($userTenant);
        $em->flush();

        return $user;
    }

    private function criarRegistro(User $user, Tenant $tenant, string $dataHora): RegistroPonto
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $registro = new RegistroPonto();
        $registro->setUser($user);
        $registro->setTenant($tenant);
        $registro->setTipo(RegistroPonto::TIPO_ENTRADA);
        $registro->setDataHora(new \DateTime($dataHora));
        $registro->setSedeNomeSnapshot('Teste');
        $em->persist($registro);
        $em->flush();

        return $registro;
    }

    private function criarLancamento(Tenant $tenant, User $colaborador, User $autor, int $ano, int $mes, int $minutos): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $lancamento = new LancamentoHorasPagas();
        $lancamento->setTenant($tenant);
        $lancamento->setUser($colaborador);
        $lancamento->setAno($ano);
        $lancamento->setMes($mes);
        $lancamento->setMinutos($minutos);
        $lancamento->setMotivo('Lançamento de fixture');
        $lancamento->setCriadoPor($autor);
        $lancamento->setCriadoEm(new \DateTimeImmutable());
        $em->persist($lancamento);
        $em->flush();
    }
}
