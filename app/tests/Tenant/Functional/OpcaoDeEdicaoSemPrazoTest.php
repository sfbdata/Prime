<?php

declare(strict_types=1);

namespace App\Tests\Tenant\Functional;

use App\Controller\TenantRoleController;
use App\Entity\Audit\AuditLog;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Tenant\Controller\EdicaoSemPrazoController;
use App\Tenant\Entity\ConfiguracaoDeEdicao;
use App\Tenant\UseCase\AlterarEdicaoSemPrazoUseCase;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Tests\Tenant\Support\CenarioDeEdicaoSemPrazo;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * A opção "Usuário primário pode editar e excluir qualquer registro sem limite de tempo" (spec
 * `edicao-sem-prazo-master-primario.md` §3 e §7): na página do perfil "Usuário Primário", ligada por padrão, só o
 * primário grava.
 */
#[CoversClass(EdicaoSemPrazoController::class)]
#[CoversClass(AlterarEdicaoSemPrazoUseCase::class)]
#[CoversClass(TenantRoleController::class)]
#[Group('tenant')]
final class OpcaoDeEdicaoSemPrazoTest extends JusPrimeWebTestCase
{
    use CenarioDeEdicaoSemPrazo;

    private KernelBrowser $client;
    private Tenant $escritorio;
    private User $primario;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->instalarCsrfPrevisivel();
        $this->escritorio = $this->novoEscritorio();
        $this->primario = $this->novaPessoa($this->escritorio, 'Paula Primária', true);
    }

    #[TestDox('Página do primário: a linha de checkbox do desenho, marcada sem linha na tabela (padrão ligado)')]
    public function testPaginaComOpcaoLigadaPorPadrao(): void
    {
        $this->logarComTenant($this->client, $this->primario, $this->escritorio);

        $c = $this->client->request('GET', $this->urlDoPerfil());

        self::assertResponseIsSuccessful();
        $caixa = $c->filter('.pf-page > .pf-card.pf-sem-prazo[data-pf-sem-prazo] > form[data-pf-sem-prazo-form] > label.pf-item > input[type="checkbox"][name="ligado"]');
        self::assertCount(1, $caixa);
        self::assertNotNull($caixa->attr('checked'));
        self::assertNull($caixa->attr('disabled'));
        self::assertSame('Usuário primário pode editar e excluir qualquer registro sem limite de tempo', trim($caixa->closest('label')?->text() ?? ''));
        self::assertNull($this->em->getRepository(ConfiguracaoDeEdicao::class)->findOneBy(['tenant' => $this->escritorio]));
    }

    #[TestDox('O primário desliga e religa; a escolha fica gravada com quem mudou e entra na auditoria')]
    public function testPrimarioGrava(): void
    {
        $this->logarComTenant($this->client, $this->primario, $this->escritorio);

        $this->client->request('POST', $this->urlDaOpcao(), ['_token' => $this->token()]);
        self::assertResponseRedirects($this->urlDoPerfil());
        $configuracao = $this->em->getRepository(ConfiguracaoDeEdicao::class)->findOneBy(['tenant' => $this->escritorio]);
        self::assertNotNull($configuracao);
        self::assertFalse($configuracao->isPrimarioSemPrazo());
        self::assertSame($this->primario->getId(), $configuracao->getAlteradoPor()?->getId());
        $c = $this->client->request('GET', $this->urlDoPerfil());
        self::assertNull($c->filter('[data-pf-sem-prazo] input[name="ligado"]')->attr('checked'));

        $this->client->request('POST', $this->urlDaOpcao(), ['_token' => $this->token(), 'ligado' => '1']);
        $this->em->clear();
        self::assertTrue($this->em->getRepository(ConfiguracaoDeEdicao::class)->findOneBy(['tenant' => $this->escritorio->getId()])?->isPrimarioSemPrazo());
        self::assertNotEmpty($this->em->getRepository(AuditLog::class)->findBy(['entityClass' => ConfiguracaoDeEdicao::class, 'tenantId' => $this->escritorio->getId()]));
    }

    #[TestDox('Secundário e Master sem vínculo de primário levam 403 e nada grava; sem o token, 403')]
    public function testQuemNaoGrava(): void
    {
        $secundario = $this->novaPessoa($this->escritorio, 'Sérgio Secundário');
        $master = $this->novaPessoa($this->escritorio, 'Marta Master', false, ['ROLE_SUPER_ADMIN']);

        foreach ([$secundario, $master] as $quem) {
            $this->logarComTenant($this->client, $quem, $this->escritorio);
            $this->client->request('POST', $this->urlDaOpcao(), ['_token' => $this->token()]);
            self::assertResponseStatusCodeSame(403);
        }
        $this->logarComTenant($this->client, $this->primario, $this->escritorio);
        $this->client->request('POST', $this->urlDaOpcao(), ['_token' => 'errado']);
        self::assertResponseStatusCodeSame(403);
        self::assertNull($this->em->getRepository(ConfiguracaoDeEdicao::class)->findOneBy(['tenant' => $this->escritorio]));
    }

    #[TestDox('O Master vê a opção travada na página do primário')]
    public function testMasterVeTravada(): void
    {
        $master = $this->novaPessoa($this->escritorio, 'Marta Master', false, ['ROLE_SUPER_ADMIN']);
        $this->logarComTenant($this->client, $master, $this->escritorio);

        $c = $this->client->request('GET', $this->urlDoPerfil());

        self::assertNotNull($c->filter('[data-pf-sem-prazo] label.pf-item.is-travado > input[name="ligado"]')->attr('disabled'));
    }

    #[TestDox('Primário de outro escritório não grava na opção deste (a rota exige o escritório da sessão)')]
    public function testOutroEscritorio(): void
    {
        $outro = $this->novoEscritorio('Outro');
        $primarioDeOutro = $this->novaPessoa($outro, 'Otto Primário', true);
        $this->logarComTenant($this->client, $primarioDeOutro, $outro);

        $this->client->request('POST', $this->urlDaOpcao(), ['_token' => $this->token()]);

        self::assertResponseStatusCodeSame(403);
        self::assertNull($this->em->getRepository(ConfiguracaoDeEdicao::class)->findOneBy(['tenant' => $this->escritorio]));
    }

    private function token(): string
    {
        return 'TOKEN_edicao_sem_prazo_' . $this->escritorio->getId();
    }

    private function urlDaOpcao(): string
    {
        return sprintf('/tenant/%d/roles/primarios/edicao-sem-prazo', $this->escritorio->getId());
    }

    private function urlDoPerfil(): string
    {
        $perfil = $this->em->getRepository(TenantRole::class)->findOneBy(['tenant' => $this->escritorio, 'isSystem' => true]);

        return sprintf('/tenant/%d/roles/%d/edit', $this->escritorio->getId(), $perfil?->getId());
    }
}
