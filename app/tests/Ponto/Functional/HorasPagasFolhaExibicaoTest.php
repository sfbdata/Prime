<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Functional;

use App\Controller\TenantController;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Permission\Permission;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Entity\Tenant\TenantRolePermission;
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
        // Usuário SEM bypass (TenantRole não-isSystem, com a permissão real de módulo): prova que é o
        // colaborador visualizando a PRÓPRIA folha, não um perfil administrativo qualquer que passaria
        // por qualquer checagem.
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $colaborador = $this->criarColaboradorComum($tenant);
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
        $colaborador = $this->criarColaboradorComum($tenant);

        $this->logarComTenant($client, $colaborador, $tenant);
        $client->request('GET', '/ponto/');

        self::assertResponseIsSuccessful();
        $conteudo = (string) $client->getResponse()->getContent();
        self::assertStringNotContainsString('Horas pagas', $conteudo, 'sem lançamento a tela tem de ficar igual à de antes desta tarefa');
    }

    #[TestDox('dois lancamentos que se anulam na mesma competencia NAO produzem linha fantasma')]
    public function testLancamentosQueSeAnulamNaoMostramALinha(): void
    {
        // A implementação usa `!= 0` (não `is not null`) de propósito: um refactor para `is not null`
        // faria +600 e -600 na mesma competência renderizar "+0h00m" — nada foi pago de fato, e a
        // linha teria de sumir por completo.
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $colaborador = $this->criarColaboradorComum($tenant);
        $autor = $this->criarColaborador($tenant);
        $agora = new \DateTimeImmutable();
        $ano = (int) $agora->format('Y');
        $mes = (int) $agora->format('n');

        $this->criarLancamento($tenant, $colaborador, $autor, $ano, $mes, 600);
        $this->criarLancamento($tenant, $colaborador, $autor, $ano, $mes, -600);

        $this->logarComTenant($client, $colaborador, $tenant);
        $client->request('GET', '/ponto/');

        self::assertResponseIsSuccessful();
        $conteudo = (string) $client->getResponse()->getContent();
        self::assertStringNotContainsString('Horas pagas', $conteudo, 'lançamentos que se anulam não podem gerar uma linha "+0h00m" fantasma');
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

    #[TestDox('colaborador SEM nenhuma batida: admin e colaborador veem a mesma linha na competencia atual')]
    public function testColaboradorSemBatidaNenhumaFicaConsistenteEntreAsDuasTelas(): void
    {
        // O bug de paridade: TenantController só calculava horasPagasMinutosPonto DENTRO do
        // `if (competência com histórico de batida)`. Colaborador recém-admitido, zero batidas —
        // a lista de competências fica vazia e a ficha do admin nunca calculava nada, enquanto o
        // /ponto do próprio colaborador (que sempre resolve para o mês corrente) já refletia o
        // lançamento. Este teste ancora os dois caminhos batendo para o mesmo cenário.
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $admin = $this->criarAdmin($tenant);
        $colaborador = $this->criarColaboradorComum($tenant);
        $autor = $this->criarColaborador($tenant);
        $agora = new \DateTimeImmutable();
        $ano = (int) $agora->format('Y');
        $mes = (int) $agora->format('n');

        // Nenhum RegistroPonto é criado de propósito — é o cenário "zero batidas".
        $this->criarLancamento($tenant, $colaborador, $autor, $ano, $mes, -600);

        // Caminho 1 — o próprio colaborador em /ponto.
        $this->logarComTenant($client, $colaborador, $tenant);
        $client->request('GET', '/ponto/');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString(
            'Horas pagas',
            (string) $client->getResponse()->getContent(),
            'o colaborador sem batida nenhuma ainda assim tem de ver o lançamento do mês corrente',
        );

        // Caminho 2 — a ficha do mesmo colaborador vista pelo admin, mesma competência (corrente).
        $this->logarComTenant($client, $admin, $tenant);
        $client->request('GET', sprintf('/tenant/%d/user/%d/edit-role', $tenant->getId(), $colaborador->getId()));
        self::assertResponseIsSuccessful();
        self::assertStringContainsString(
            'Horas pagas',
            (string) $client->getResponse()->getContent(),
            'a ficha do admin não pode divergir do /ponto do próprio colaborador para o mesmo lançamento',
        );
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

    /**
     * Colaborador com `TenantRole` de verdade (NÃO-isSystem, logo sem bypass) carregando a permissão
     * real `modules.ponto.view` — prova que a rota é acessada como o próprio colaborador enxergaria,
     * não por um perfil administrativo que passa por qualquer checagem.
     */
    private function criarColaboradorComum(Tenant $tenant): User
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $codigoPermissao = 'modules.ponto.view';
        $permissao = $em->getRepository(Permission::class)->findOneBy(['code' => $codigoPermissao]);
        if ($permissao === null) {
            $permissao = new Permission();
            $permissao->setCode($codigoPermissao);
            $permissao->setDescription('Permissão de teste ' . $codigoPermissao);
            $permissao->setGroup(explode('.', $codigoPermissao)[0]);
            $em->persist($permissao);
        }

        $role = new TenantRole();
        $role->setTenant($tenant);
        $role->setName('Colaborador comum ' . uniqid());
        $role->setIsSystem(false);
        $em->persist($role);

        $vinculoPermissao = new TenantRolePermission();
        $vinculoPermissao->setTenantRole($role);
        $vinculoPermissao->setPermission($permissao);
        $em->persist($vinculoPermissao);
        $role->getTenantRolePermissions()->add($vinculoPermissao);

        $user = new User();
        $user->setEmail('colab_comum_' . uniqid() . '@test.com');
        $user->setFullName('Colaborador Comum Folha Horas Pagas');
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $em->persist($user);

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
