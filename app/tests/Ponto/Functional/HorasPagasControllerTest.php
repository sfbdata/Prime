<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Functional;

use App\Entity\Audit\AuditLog;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Permission\Permission;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Entity\Tenant\TenantRolePermission;
use App\Ponto\Controller\HorasPagasController;
use App\Ponto\Entity\LancamentoHorasPagas;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

/**
 * Rotas de escrita de horas pagas (risco ALTO: mexem em banco de horas, que é verba trabalhista).
 *
 * Toda asserção de recusa tem DUAS partes: o status/mensagem E a contagem de lançamentos
 * inalterada. Status 403 com o dado gravado é o defeito clássico aqui — o 403 sozinho não prova
 * nada sobre o banco.
 */
#[CoversClass(HorasPagasController::class)]
final class HorasPagasControllerTest extends JusPrimeWebTestCase
{
    #[TestDox('colaborador sem admin.users.manage recebe 403 e nada e gravado')]
    public function testSemPermissaoRetorna403(): void
    {
        $client = static::createClient();
        $this->instalarCsrfStorage();
        $tenant      = $this->criarTenant();
        $semPoder    = $this->criarUsuario($tenant);
        $colaborador = $this->criarUsuario($tenant);

        $this->logarComTenant($client, $semPoder, $tenant);
        $client->request('POST', $this->rotaLancar($tenant, $colaborador), $this->payload($this->tokenLancar($colaborador)));

        self::assertResponseStatusCodeSame(403, 'sem admin.users.manage não se lança horas pagas');
        self::assertSame(0, $this->contarLancamentos($colaborador), 'nada pode ter sido gravado sem permissão');
    }

    #[TestDox('perfil com OUTRA permissao (sem admin.users.manage) recebe 403 e nada e gravado')]
    public function testPerfilComOutraPermissaoRetorna403(): void
    {
        // O caso "sem TenantRole nenhum" acima morre no primeiro `if` do PermissionChecker e não
        // prova QUAL permissão protege a rota: trocar 'admin.users.manage' por qualquer outra
        // string o manteria verde. Aqui o usuário tem perfil de verdade (não-isSystem) com uma
        // permissão real do catálogo — só não é a que esta rota exige.
        $client = static::createClient();
        $this->instalarCsrfStorage();
        $tenant      = $this->criarTenant();
        $quaseAdmin  = $this->criarUsuarioComPermissao($tenant, 'modules.ponto.view');
        $colaborador = $this->criarUsuario($tenant);

        $this->logarComTenant($client, $quaseAdmin, $tenant);
        $client->request('POST', $this->rotaLancar($tenant, $colaborador), $this->payload($this->tokenLancar($colaborador)));

        self::assertResponseStatusCodeSame(403, 'ver o módulo Ponto não dá poder de mexer no banco de horas alheio');
        self::assertSame(0, $this->contarLancamentos($colaborador), 'nada pode ter sido gravado sem admin.users.manage');
    }

    #[TestDox('editar exige admin.users.manage: perfil com outra permissao recebe 403 e nada muda')]
    public function testEdicaoSemPermissaoRetorna403(): void
    {
        $client = static::createClient();
        $this->instalarCsrfStorage();
        $tenant      = $this->criarTenant();
        $admin       = $this->criarAdmin($tenant);
        $quaseAdmin  = $this->criarUsuarioComPermissao($tenant, 'modules.ponto.view');
        $colaborador = $this->criarUsuario($tenant);
        $lancamento  = $this->criarLancamento($tenant, $colaborador, $admin, -600);
        $lancamentoId = (int) $lancamento->getId();

        $this->logarComTenant($client, $quaseAdmin, $tenant);
        $client->request(
            'POST',
            $this->rotaEditar($tenant, $colaborador, $lancamentoId),
            $this->payload('TOKEN_horas_pagas_editar_' . $lancamentoId, ['horas' => 2, 'minutos' => 0]),
        );

        self::assertResponseStatusCodeSame(403);
        self::assertSame(-600, $this->recarregarLancamento($lancamentoId)->getMinutos(), 'o valor não pode mudar sem permissão');
    }

    #[TestDox('excluir exige admin.users.manage: perfil com outra permissao recebe 403 e nada some')]
    public function testExclusaoSemPermissaoRetorna403(): void
    {
        $client = static::createClient();
        $this->instalarCsrfStorage();
        $tenant      = $this->criarTenant();
        $admin       = $this->criarAdmin($tenant);
        $quaseAdmin  = $this->criarUsuarioComPermissao($tenant, 'modules.ponto.view');
        $colaborador = $this->criarUsuario($tenant);
        $lancamento  = $this->criarLancamento($tenant, $colaborador, $admin, -600);
        $lancamentoId = (int) $lancamento->getId();

        $this->logarComTenant($client, $quaseAdmin, $tenant);
        $client->request(
            'POST',
            $this->rotaExcluir($tenant, $colaborador, $lancamentoId),
            ['_token' => 'TOKEN_horas_pagas_excluir_' . $lancamentoId],
        );

        self::assertResponseStatusCodeSame(403);
        self::assertSame(1, $this->contarLancamentos($colaborador), 'nada pode ser apagado sem permissão');
    }

    #[TestDox('POST com token CSRF ERRADO retorna 403 e nada e gravado')]
    public function testTokenCsrfErradoRetorna403(): void
    {
        // Sem este caso, degradar a checagem para "token não-vazio" deixaria a suíte verde.
        $client = static::createClient();
        $this->instalarCsrfStorage();
        $tenant      = $this->criarTenant();
        $admin       = $this->criarAdmin($tenant);
        $colaborador = $this->criarUsuario($tenant);

        $this->logarComTenant($client, $admin, $tenant);
        $client->request('POST', $this->rotaLancar($tenant, $colaborador), $this->payload('TOKEN_de_outra_intencao'));

        self::assertResponseStatusCodeSame(403, 'token preenchido mas de outra intenção não vale');
        self::assertSame(0, $this->contarLancamentos($colaborador));
    }

    #[TestDox('POST sem token CSRF retorna 403, com a mensagem, e nada e gravado')]
    public function testSemCsrfRetorna403(): void
    {
        $client = static::createClient();
        $tenant      = $this->criarTenant();
        $admin       = $this->criarAdmin($tenant);
        $colaborador = $this->criarUsuario($tenant);

        $this->logarComTenant($client, $admin, $tenant);
        $client->request('POST', $this->rotaLancar($tenant, $colaborador), $this->payload(null));

        self::assertResponseStatusCodeSame(403, 'lançamento sem CSRF deveria ser barrado');
        self::assertSame(0, $this->contarLancamentos($colaborador), 'nada pode ter sido gravado sem CSRF');

        // Segunda passada só para capturar a MENSAGEM da recusa: o status 403 sozinho não
        // distingue "token inválido" de "sem permissão", e a mensagem é o que o próximo
        // desenvolvedor lê quando o modal parar de funcionar.
        $client->catchExceptions(false);

        try {
            $client->request('POST', $this->rotaLancar($tenant, $colaborador), $this->payload(null));
            self::fail('a rota deveria ter lançado AccessDenied sem o token CSRF');
        } catch (\Throwable $excecao) {
            self::assertStringContainsString('Token CSRF inválido.', $excecao->getMessage());
        }

        self::assertSame(0, $this->contarLancamentos($colaborador));
    }

    #[TestDox('admin com permissao lanca e o registro nasce com autoria correta')]
    public function testLancamentoValidoGrava(): void
    {
        $client = static::createClient();
        $this->instalarCsrfStorage();
        $tenant      = $this->criarTenant();
        $admin       = $this->criarAdmin($tenant);
        $colaborador = $this->criarUsuario($tenant);
        [$ano, $mes] = $this->competenciaPassada();

        $this->logarComTenant($client, $admin, $tenant);
        $client->request('POST', $this->rotaLancar($tenant, $colaborador), $this->payload($this->tokenLancar($colaborador)));

        self::assertResponseRedirects();

        $lancamentos = $this->lancamentosDe($colaborador);
        self::assertCount(1, $lancamentos, 'o lançamento válido deveria ter sido gravado');

        $lancamento = $lancamentos[0];
        self::assertSame(-630, $lancamento->getMinutos(), 'descontar 10h30 tem de virar -630 minutos');
        self::assertSame($ano, $lancamento->getAno());
        self::assertSame($mes, $lancamento->getMes());
        self::assertSame('Horas pagas na folha', $lancamento->getMotivo());
        self::assertSame($admin->getId(), $lancamento->getCriadoPor()?->getId(), 'a autoria tem de ser de quem lançou');
        self::assertSame($tenant->getId(), $lancamento->getTenant()?->getId());
        self::assertNotNull($lancamento->getCriadoEm());
        self::assertNull($lancamento->getAtualizadoPor(), 'lançamento novo não nasce editado');
    }

    #[TestDox('acrescentar ao banco grava minutos POSITIVOS')]
    public function testLancamentoAcrescentarGravaPositivo(): void
    {
        $client = static::createClient();
        $this->instalarCsrfStorage();
        $tenant      = $this->criarTenant();
        $admin       = $this->criarAdmin($tenant);
        $colaborador = $this->criarUsuario($tenant);

        $this->logarComTenant($client, $admin, $tenant);
        $client->request(
            'POST',
            $this->rotaLancar($tenant, $colaborador),
            $this->payload($this->tokenLancar($colaborador), ['operacao' => 'acrescentar', 'horas' => 8, 'minutos' => 0]),
        );

        self::assertResponseRedirects();
        self::assertSame(480, $this->lancamentosDe($colaborador)[0]->getMinutos(), 'acrescentar tem de somar, não descontar');
    }

    #[TestDox('operacao fora do catalogo nao grava nada (o sinal nao pode ser invertido por typo)')]
    public function testOperacaoInvalidaNaoGrava(): void
    {
        $client = static::createClient();
        $this->instalarCsrfStorage();
        $tenant      = $this->criarTenant();
        $admin       = $this->criarAdmin($tenant);
        $colaborador = $this->criarUsuario($tenant);

        $this->logarComTenant($client, $admin, $tenant);
        $client->request(
            'POST',
            $this->rotaLancar($tenant, $colaborador),
            $this->payload($this->tokenLancar($colaborador), ['operacao' => 'descontar_do_banco']),
        );

        self::assertResponseRedirects();
        self::assertSame(0, $this->contarLancamentos($colaborador), 'operação desconhecida não pode virar crédito');
    }

    #[TestDox('admin do tenant A lancando para colaborador do tenant B recebe 403 e nada e gravado')]
    public function testCrossTenantBloqueado(): void
    {
        $client = static::createClient();
        $this->instalarCsrfStorage();
        $tenantA = $this->criarTenant();
        $tenantB = $this->criarTenant();
        $adminA  = $this->criarAdmin($tenantA);
        $alvoB   = $this->criarUsuario($tenantB);

        $this->logarComTenant($client, $adminA, $tenantA);
        // URL do PRÓPRIO tenant, id de funcionário do vizinho: é o IDOR que a guarda de vínculo fecha.
        $client->request('POST', $this->rotaLancar($tenantA, $alvoB), $this->payload($this->tokenLancar($alvoB)));

        self::assertResponseStatusCodeSame(403, 'funcionário de outro escritório não é alcançável pelo id');
        self::assertSame(0, $this->contarLancamentos($alvoB), 'nada pode ter sido gravado cross-tenant');
    }

    #[TestDox('admin do tenant A usando a URL do tenant B recebe 403 e nada e gravado')]
    public function testUrlDeOutroTenantBloqueada(): void
    {
        $client = static::createClient();
        $this->instalarCsrfStorage();
        $tenantA = $this->criarTenant();
        $tenantB = $this->criarTenant();
        $adminA  = $this->criarAdmin($tenantA);
        $alvoB   = $this->criarUsuario($tenantB);

        $this->logarComTenant($client, $adminA, $tenantA);
        $client->request('POST', $this->rotaLancar($tenantB, $alvoB), $this->payload($this->tokenLancar($alvoB)));

        self::assertResponseStatusCodeSame(403, 'ser admin do escritório A não dá poder no escritório B');
        self::assertSame(0, $this->contarLancamentos($alvoB));
    }

    #[TestDox('admin nao lanca horas pagas para si mesmo')]
    public function testAutoLancamentoBloqueado(): void
    {
        $client = static::createClient();
        $this->instalarCsrfStorage();
        $tenant = $this->criarTenant();
        $admin  = $this->criarAdmin($tenant);

        $this->logarComTenant($client, $admin, $tenant);
        $client->request('POST', $this->rotaLancar($tenant, $admin), $this->payload($this->tokenLancar($admin)));

        self::assertResponseRedirects();
        self::assertSame(0, $this->contarLancamentos($admin), 'ninguém acerta o próprio banco de horas');

        $flashes = $client->getRequest()->getSession()->getFlashBag()->peekAll()['error'] ?? [];
        self::assertNotEmpty($flashes, 'a recusa tem de aparecer para quem tentou, não falhar em silêncio');
        self::assertStringContainsString('para si mesmo', implode(' ', $flashes));
    }

    #[TestDox('editar altera o lancamento e registra quem editou')]
    public function testEdicaoAltera(): void
    {
        $client = static::createClient();
        $this->instalarCsrfStorage();
        $tenant      = $this->criarTenant();
        $admin       = $this->criarAdmin($tenant);
        $colaborador = $this->criarUsuario($tenant);
        $lancamento  = $this->criarLancamento($tenant, $colaborador, $admin, -600);
        $lancamentoId = (int) $lancamento->getId();

        $this->logarComTenant($client, $admin, $tenant);
        $client->request(
            'POST',
            $this->rotaEditar($tenant, $colaborador, $lancamentoId),
            $this->payload('TOKEN_horas_pagas_editar_' . $lancamentoId, ['horas' => 2, 'minutos' => 0]),
        );

        self::assertResponseRedirects();

        $recarregado = $this->recarregarLancamento($lancamentoId);
        self::assertSame(-120, $recarregado->getMinutos(), 'a edição deveria ter substituído o valor');
        self::assertSame($admin->getId(), $recarregado->getAtualizadoPor()?->getId());
        self::assertNotNull($recarregado->getAtualizadoEm());
    }

    #[TestDox('editar sem token CSRF retorna 403 e o valor original permanece')]
    public function testEdicaoSemCsrfRetorna403(): void
    {
        $client = static::createClient();
        $tenant      = $this->criarTenant();
        $admin       = $this->criarAdmin($tenant);
        $colaborador = $this->criarUsuario($tenant);
        $lancamento  = $this->criarLancamento($tenant, $colaborador, $admin, -600);
        $lancamentoId = (int) $lancamento->getId();

        $this->logarComTenant($client, $admin, $tenant);
        $client->request(
            'POST',
            $this->rotaEditar($tenant, $colaborador, $lancamentoId),
            $this->payload(null, ['horas' => 2, 'minutos' => 0]),
        );

        self::assertResponseStatusCodeSame(403);
        self::assertSame(-600, $this->recarregarLancamento($lancamentoId)->getMinutos(), 'o valor não pode ter mudado sem CSRF');
    }

    #[TestDox('excluir remove o lancamento')]
    public function testExclusaoRemove(): void
    {
        $client = static::createClient();
        $this->instalarCsrfStorage();
        $tenant      = $this->criarTenant();
        $admin       = $this->criarAdmin($tenant);
        $colaborador = $this->criarUsuario($tenant);
        $lancamento  = $this->criarLancamento($tenant, $colaborador, $admin, -600);
        $lancamentoId = (int) $lancamento->getId();

        $this->logarComTenant($client, $admin, $tenant);
        $client->request(
            'POST',
            $this->rotaExcluir($tenant, $colaborador, $lancamentoId),
            ['_token' => 'TOKEN_horas_pagas_excluir_' . $lancamentoId],
        );

        self::assertResponseRedirects();
        self::assertSame(0, $this->contarLancamentos($colaborador), 'o lançamento deveria ter sido removido');
    }

    #[TestDox('excluir sem token CSRF retorna 403 e o lancamento continua la')]
    public function testExclusaoSemCsrfRetorna403(): void
    {
        $client = static::createClient();
        $tenant      = $this->criarTenant();
        $admin       = $this->criarAdmin($tenant);
        $colaborador = $this->criarUsuario($tenant);
        $lancamento  = $this->criarLancamento($tenant, $colaborador, $admin, -600);

        $this->logarComTenant($client, $admin, $tenant);
        $client->request('POST', $this->rotaExcluir($tenant, $colaborador, (int) $lancamento->getId()), []);

        self::assertResponseStatusCodeSame(403);
        self::assertSame(1, $this->contarLancamentos($colaborador), 'nada pode ser apagado sem CSRF');
    }

    #[TestDox('excluir lancamento de OUTRO escritorio retorna 404 e nao apaga nada')]
    public function testExclusaoDeLancamentoDeOutroTenantRetorna404(): void
    {
        $client = static::createClient();
        $this->instalarCsrfStorage();
        $tenantA = $this->criarTenant();
        $tenantB = $this->criarTenant();
        $adminA  = $this->criarAdmin($tenantA);
        $adminB  = $this->criarAdmin($tenantB);
        $alvoA   = $this->criarUsuario($tenantA);
        $alvoB   = $this->criarUsuario($tenantB);
        $lancamentoB  = $this->criarLancamento($tenantB, $alvoB, $adminB, -600);
        $lancamentoId = (int) $lancamentoB->getId();

        $this->logarComTenant($client, $adminA, $tenantA);
        // Id de lançamento do vizinho na URL do próprio escritório: buscarDoTenant tem de recusar.
        $client->request(
            'POST',
            $this->rotaExcluir($tenantA, $alvoA, $lancamentoId),
            ['_token' => 'TOKEN_horas_pagas_excluir_' . $lancamentoId],
        );

        self::assertResponseStatusCodeSame(404);
        self::assertSame(1, $this->contarLancamentos($alvoB), 'o lançamento do vizinho não pode ser apagado');
    }

    #[TestDox('editar lancamento de OUTRO colaborador do mesmo escritorio retorna 404')]
    public function testEdicaoDeLancamentoDeOutroColaboradorRetorna404(): void
    {
        $client = static::createClient();
        $this->instalarCsrfStorage();
        $tenant     = $this->criarTenant();
        $admin      = $this->criarAdmin($tenant);
        $dono       = $this->criarUsuario($tenant);
        $outro      = $this->criarUsuario($tenant);
        $lancamento = $this->criarLancamento($tenant, $dono, $admin, -600);
        $lancamentoId = (int) $lancamento->getId();

        $this->logarComTenant($client, $admin, $tenant);
        $client->request(
            'POST',
            $this->rotaEditar($tenant, $outro, $lancamentoId),
            $this->payload('TOKEN_horas_pagas_editar_' . $lancamentoId, ['horas' => 2, 'minutos' => 0]),
        );

        self::assertResponseStatusCodeSame(404, 'a URL tem de ser coerente: lançamento do colaborador da rota');
        self::assertSame(-600, $this->recarregarLancamento($lancamentoId)->getMinutos());
    }

    #[TestDox('POST sem o mes e sem a operacao devolve flash, nao 500, e nada e gravado')]
    public function testCamposDeEscolhaAusentesNaoQuebram(): void
    {
        // `mes` e `operacao` são ChoiceType: campo ausente vira null e estouraria TypeError ao ser
        // escrito nas propriedades tipadas do DTO — 500 no log de uma rota de ponto, onde deveria
        // haver flash. Nenhum sentido de operação pode ser assumido por omissão.
        $client = static::createClient();
        $this->instalarCsrfStorage();
        $tenant      = $this->criarTenant();
        $admin       = $this->criarAdmin($tenant);
        $colaborador = $this->criarUsuario($tenant);
        [$ano] = $this->competenciaPassada();

        $this->logarComTenant($client, $admin, $tenant);
        $client->request('POST', $this->rotaLancar($tenant, $colaborador), [
            'lancamento_horas_pagas' => [
                'ano'     => $ano,
                'horas'   => 10,
                'minutos' => 0,
                'motivo'  => 'Horas pagas na folha',
            ],
            '_token' => $this->tokenLancar($colaborador),
        ]);

        self::assertResponseRedirects();
        self::assertSame(0, $this->contarLancamentos($colaborador), 'sem mês e sem operação não se grava nada');

        $flashes = $client->getRequest()->getSession()->getFlashBag()->peekAll()['error'] ?? [];
        self::assertNotEmpty($flashes, 'campo de escolha faltando tem de virar aviso, não erro de servidor');
    }

    #[TestDox('lancar, editar e excluir deixam rastro no audit_log')]
    public function testAsTresOperacoesGeramAuditLog(): void
    {
        // O dono optou por editar/excluir sem histórico dentro do produto; o audit_log é a
        // mitigação que substituiu esse histórico. Se ele não nascer, não sobra prova nenhuma de
        // uma alteração de verba trabalhista.
        $client = static::createClient();
        // Três requisições no mesmo teste: sem desligar o reboot, o kernel é recriado a cada uma e
        // o storage de CSRF falso instalado abaixo se perde (a 2ª requisição levaria 403).
        $client->disableReboot();
        $this->instalarCsrfStorage();
        $tenant      = $this->criarTenant();
        $admin       = $this->criarAdmin($tenant);
        $colaborador = $this->criarUsuario($tenant);

        $this->logarComTenant($client, $admin, $tenant);

        // 1. criar pela rota
        $client->request('POST', $this->rotaLancar($tenant, $colaborador), $this->payload($this->tokenLancar($colaborador)));
        self::assertResponseRedirects();

        $lancamentoId = (int) $this->lancamentosDe($colaborador)[0]->getId();

        // No INSERT o subscriber ainda não tem o id (o Doctrine só o atribui depois do onFlush),
        // então a linha de `create` nasce com `entity_id` nulo — vale para toda entidade do
        // sistema, não é particularidade daqui. O que identifica a linha é o payload.
        $criacoes = $this->auditsDaClasse('create');
        self::assertCount(1, $criacoes, 'o lançamento novo tem de deixar rastro');
        self::assertStringContainsString(
            'Horas pagas na folha',
            json_encode($criacoes[0]->getChanges(), JSON_UNESCAPED_UNICODE) ?: '',
            'o rastro da criação tem de guardar o estado gravado',
        );
        self::assertSame($admin->getId(), $criacoes[0]->getActorUserId(), 'o rastro tem de apontar quem lançou');

        // 2. editar pela rota
        $client->request(
            'POST',
            $this->rotaEditar($tenant, $colaborador, $lancamentoId),
            $this->payload('TOKEN_horas_pagas_editar_' . $lancamentoId, ['horas' => 2, 'minutos' => 0]),
        );
        self::assertResponseRedirects();
        self::assertNotEmpty($this->auditsDoLancamento($lancamentoId, 'update'), 'a edição tem de deixar rastro');

        // 3. excluir pela rota
        $client->request(
            'POST',
            $this->rotaExcluir($tenant, $colaborador, $lancamentoId),
            ['_token' => 'TOKEN_horas_pagas_excluir_' . $lancamentoId],
        );
        self::assertResponseRedirects();
        self::assertSame(0, $this->contarLancamentos($colaborador));

        $exclusoes = $this->auditsDoLancamento($lancamentoId, 'delete');
        self::assertNotEmpty($exclusoes, 'a exclusão tem de deixar rastro — é a única prova que sobra');
        self::assertSame($admin->getId(), $exclusoes[0]->getActorUserId(), 'o rastro tem de apontar quem excluiu');
    }

    #[TestDox('competencia futura e recusada e nada e gravado')]
    public function testCompetenciaFuturaRecusada(): void
    {
        $client = static::createClient();
        $this->instalarCsrfStorage();
        $tenant      = $this->criarTenant();
        $admin       = $this->criarAdmin($tenant);
        $colaborador = $this->criarUsuario($tenant);
        $futuro      = new \DateTimeImmutable('first day of next month');

        $this->logarComTenant($client, $admin, $tenant);
        $client->request(
            'POST',
            $this->rotaLancar($tenant, $colaborador),
            $this->payload($this->tokenLancar($colaborador), [
                'ano' => (int) $futuro->format('Y'),
                'mes' => (int) $futuro->format('n'),
            ]),
        );

        self::assertResponseRedirects();
        self::assertSame(0, $this->contarLancamentos($colaborador), 'competência futura não pode gravar');
    }

    // ----------------------------------------------------------------- helpers

    private function rotaLancar(Tenant $tenant, User $colaborador): string
    {
        return sprintf('/tenant/%d/users/%d/horas-pagas', $tenant->getId(), $colaborador->getId());
    }

    private function rotaEditar(Tenant $tenant, User $colaborador, int $lancamentoId): string
    {
        return $this->rotaLancar($tenant, $colaborador) . '/' . $lancamentoId . '/editar';
    }

    private function rotaExcluir(Tenant $tenant, User $colaborador, int $lancamentoId): string
    {
        return $this->rotaLancar($tenant, $colaborador) . '/' . $lancamentoId . '/excluir';
    }

    private function tokenLancar(User $colaborador): string
    {
        return 'TOKEN_horas_pagas_lancar_' . $colaborador->getId();
    }

    /**
     * Competência sempre no passado (mês anterior ao do relógio): a validação recusa competência
     * futura, e um teste com data fixa expiraria sozinho.
     *
     * @return array{int, int}
     */
    private function competenciaPassada(): array
    {
        $referencia = new \DateTimeImmutable('first day of last month');

        return [(int) $referencia->format('Y'), (int) $referencia->format('n')];
    }

    /**
     * @param  array<string, mixed> $sobrescrever
     * @return array<string, mixed>
     */
    private function payload(?string $token, array $sobrescrever = []): array
    {
        [$ano, $mes] = $this->competenciaPassada();

        $campos = array_merge([
            'ano'      => $ano,
            'mes'      => $mes,
            'operacao' => 'descontar',
            'horas'    => 10,
            'minutos'  => 30,
            'motivo'   => 'Horas pagas na folha',
        ], $sobrescrever);

        $payload = ['lancamento_horas_pagas' => $campos];
        if ($token !== null) {
            $payload['_token'] = $token;
        }

        return $payload;
    }

    private function contarLancamentos(User $user): int
    {
        return count($this->lancamentosDe($user));
    }

    /** @return LancamentoHorasPagas[] */
    private function lancamentosDe(User $user): array
    {
        return array_values(
            $this->emSemFiltro()->getRepository(LancamentoHorasPagas::class)->findBy(['user' => $user->getId()])
        );
    }

    private function recarregarLancamento(int $id): LancamentoHorasPagas
    {
        $lancamento = $this->emSemFiltro()->find(LancamentoHorasPagas::class, $id);
        self::assertInstanceOf(LancamentoHorasPagas::class, $lancamento);

        return $lancamento;
    }

    private function emSemFiltro(): EntityManagerInterface
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        if ($em->getFilters()->isEnabled('tenant')) {
            $em->getFilters()->disable('tenant');
        }
        $em->clear();

        return $em;
    }

    private function instalarCsrfStorage(): void
    {
        $storage = new class implements ClearableTokenStorageInterface {
            public function getToken(string $tokenId): string { return 'TOKEN_' . $tokenId; }
            public function setToken(string $tokenId, string $token): void {}
            public function removeToken(string $tokenId): ?string { return null; }
            public function hasToken(string $tokenId): bool { return true; }
            public function clear(): void {}
        };

        static::getContainer()->set('security.csrf.token_storage', $storage);
    }

    private function criarTenant(): Tenant
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $tenant = new Tenant();
        $tenant->setName('Tenant HORAS PAGAS ' . uniqid());
        $em->persist($tenant);
        $em->flush();

        return $tenant;
    }

    private function criarAdmin(Tenant $tenant): User
    {
        $container = static::getContainer();
        $em     = $container->get(EntityManagerInterface::class);
        $hasher = $container->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->setEmail('admin_' . uniqid() . '@test.com');
        $user->setFullName('Admin Horas Pagas');
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setPassword($hasher->hashPassword($user, 'senha123'));
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

    private function criarUsuario(Tenant $tenant): User
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $user = new User();
        $user->setEmail('alvo_' . uniqid() . '@test.com');
        $user->setFullName('Alvo Horas Pagas');
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $em->persist($user);
        $em->persist(new UserTenant($user, $tenant));
        $em->flush();

        return $user;
    }

    /**
     * Colaborador com `TenantRole` de verdade (NÃO-isSystem, logo sem bypass) carregando uma
     * permissão real do catálogo — que não é a exigida pela rota.
     */
    private function criarUsuarioComPermissao(Tenant $tenant, string $codigoPermissao): User
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);

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
        $role->setName('Perfil limitado ' . uniqid());
        $role->setIsSystem(false);
        $em->persist($role);

        $vinculoPermissao = new TenantRolePermission();
        $vinculoPermissao->setTenantRole($role);
        $vinculoPermissao->setPermission($permissao);
        $em->persist($vinculoPermissao);
        $role->getTenantRolePermissions()->add($vinculoPermissao);

        $user = new User();
        $user->setEmail('limitado_' . uniqid() . '@test.com');
        $user->setFullName('Colaborador Limitado');
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $em->persist($user);

        $userTenant = new UserTenant($user, $tenant);
        $userTenant->setTenantRole($role);
        $em->persist($userTenant);
        $em->flush();

        return $user;
    }

    /** @return AuditLog[] */
    private function auditsDoLancamento(int $lancamentoId, string $acao): array
    {
        return array_values($this->emSemFiltro()->getRepository(AuditLog::class)->findBy([
            'entityClass' => LancamentoHorasPagas::class,
            'entityId'    => (string) $lancamentoId,
            'action'      => $acao,
        ]));
    }

    /** @return AuditLog[] */
    private function auditsDaClasse(string $acao): array
    {
        return array_values($this->emSemFiltro()->getRepository(AuditLog::class)->findBy([
            'entityClass' => LancamentoHorasPagas::class,
            'action'      => $acao,
        ]));
    }

    private function criarLancamento(Tenant $tenant, User $colaborador, User $autor, int $minutos): LancamentoHorasPagas
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        [$ano, $mes] = $this->competenciaPassada();

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

        return $lancamento;
    }
}
