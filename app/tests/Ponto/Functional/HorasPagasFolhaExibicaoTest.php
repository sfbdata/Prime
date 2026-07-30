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
        // Não basta procurar "+8h00m" solto: a aba "Horas pagas" da Tarefa 5 já lista o MESMO
        // lançamento com a MESMA formatação, incondicionalmente — o rodapé precisa ser verificado
        // no seu próprio marcador HTML, senão a asserção passa mesmo com o rodapé quebrado.
        $this->assertRodapeHorasPagasPresente($conteudo, '+8h00m');
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
        //
        // ⚠️ Nem "Horas pagas" solto nem "-10h00m" solto provam algo aqui: a aba "Horas pagas" da
        // Tarefa 5 (`_horas_pagas_tab.html.twig`) lista o MESMO lançamento, com a MESMA formatação
        // ("-10h00m"), de forma INCONDICIONAL em toda resposta 200 dessa rota. Reverter a correção
        // do `TenantController` mantém as duas strings na página e o teste passaria mesmo quebrado
        // — só o marcador HTML exclusivo do rodapé da folha (Tarefa 6) prova o caminho certo.
        $this->logarComTenant($client, $admin, $tenant);
        $client->request('GET', sprintf('/tenant/%d/user/%d/edit-role', $tenant->getId(), $colaborador->getId()));
        self::assertResponseIsSuccessful();
        $this->assertRodapeHorasPagasPresente(
            (string) $client->getResponse()->getContent(),
            '-10h00m',
        );
    }

    #[TestDox('competencia malformada na querystring nao derruba a ficha do admin (cai no fallback, sem 500)')]
    public function testCompetenciaMalformadaNaQuerystringNaoQuebra(): void
    {
        // Regressão introduzida pela própria correção do achado 2 (rodada 1): promover o `explode`
        // para fora do `if` de validação fez a ficha do admin rodar `explode('-', 'julho')` direto —
        // sem hífen, o destructuring deixa o mês `null` e `somarHorasPagasDaCompetencia(..., int $mes)`
        // estoura TypeError (500). "2026-" e "abc-def" NÃO reproduzem (têm dois elementos); só
        // valores sem hífen — daí o valor de teste escolhido a dedo.
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $admin = $this->criarAdmin($tenant);
        $colaborador = $this->criarColaborador($tenant);

        $this->logarComTenant($client, $admin, $tenant);
        $client->request('GET', sprintf('/tenant/%d/user/%d/edit-role?competencia=julho', $tenant->getId(), $colaborador->getId()));

        self::assertResponseIsSuccessful('competência sem hífen na querystring tem de cair no fallback do mês corrente, não estourar 500');
    }

    #[TestDox('colaborador com batida so em mes antigo: o mes corrente ainda aparece como opcao no seletor do admin')]
    public function testMesCorrenteApareceNoSeletorMesmoComBatidaSoEmMesAntigo(): void
    {
        // O buraco maior do achado 3: `findCompetenciasComRegistroPorUsuario` só devolve
        // competências COM batida. Colaborador com última batida há meses e lançamento no mês
        // corrente — sem injetar o mês corrente na lista, o admin só alcança aquela competência
        // editando a URL na mão; o <select> nem oferece a opção.
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $admin = $this->criarAdmin($tenant);
        $colaborador = $this->criarColaborador($tenant);
        $autor = $this->criarColaborador($tenant);
        $agora = new \DateTimeImmutable();
        $competenciaAtual = $agora->format('Y-m');

        $mesAntigo = $agora->modify('-3 months');
        $this->criarRegistro($colaborador, $tenant, $mesAntigo->format('Y-m-d') . ' 09:00:00');
        $this->criarLancamento($tenant, $colaborador, $autor, (int) $agora->format('Y'), (int) $agora->format('n'), -600);

        $this->logarComTenant($client, $admin, $tenant);
        $client->request('GET', sprintf('/tenant/%d/user/%d/edit-role', $tenant->getId(), $colaborador->getId()));

        self::assertResponseIsSuccessful();
        self::assertStringContainsString(
            sprintf('value="%s"', $competenciaAtual),
            (string) $client->getResponse()->getContent(),
            'o seletor de competência tem de oferecer o mês corrente, mesmo sem batida nele',
        );
    }

    /**
     * Confere o valor exatamente dentro do marcador HTML exclusivo do rodapé "Horas pagas" da folha
     * (`_folha_table.html.twig:282`) — `<span class="text-muted">Horas pagas</span>` seguido do
     * `<span class="fw-semibold ...">valor</span>`. Não existe em nenhum outro template: a aba
     * "Horas pagas" da Tarefa 5 (`_horas_pagas_tab.html.twig`) usa outra marcação e lista o MESMO
     * lançamento com a MESMA formatação de valor — checar só a substring do valor (ou só a palavra
     * "Horas pagas") colide com aquela aba e prova exatamente nada sobre o rodapé desta tarefa.
     */
    private function assertRodapeHorasPagasPresente(string $conteudo, string $valorEsperado): void
    {
        $padrao = '/<span class="text-muted">Horas pagas<\/span>\s*<span class="fw-semibold[^"]*">\s*'
            . preg_quote($valorEsperado, '/') . '\s*<\/span>/';

        self::assertMatchesRegularExpression(
            $padrao,
            $conteudo,
            'o rodapé da folha (Tarefa 6) tem de mostrar o valor exato — não basta a aba de lançamentos (Tarefa 5) mostrar o mesmo número em outro lugar da página',
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
