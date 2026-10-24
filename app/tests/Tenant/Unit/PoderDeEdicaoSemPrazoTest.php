<?php

declare(strict_types=1);

namespace App\Tests\Tenant\Unit;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Repository\UserTenantRepository;
use App\Tenant\Enum\PoderSemPrazo;
use App\Tenant\Repository\ConfiguracaoDeEdicaoRepository;
use App\Tenant\Service\PoderDeEdicaoSemPrazo;
use App\Tenant\Service\RegraDosPrimarios;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/** Ponto único da edição sem prazo (spec `edicao-sem-prazo-master-primario.md` §4 e §7). */
#[CoversClass(PoderDeEdicaoSemPrazo::class)]
#[CoversClass(PoderSemPrazo::class)]
#[Group('tenant')]
final class PoderDeEdicaoSemPrazoTest extends TestCase
{
    private Tenant $escritorio;
    private Tenant $outro;
    /** @var array<int, UserTenant> vínculos ativos por id de usuário, só do $escritorio */
    private array $vinculos = [];
    private bool $opcaoLigada = true;
    private int $proximoId = 1;

    protected function setUp(): void
    {
        $this->escritorio = $this->tenant(10);
        $this->outro = $this->tenant(20);
    }

    #[TestDox('Master: sempre, em qualquer escritório, mesmo com a opção do primário desligada e sem vínculo')]
    public function testMasterSempre(): void
    {
        $this->opcaoLigada = false;
        $master = $this->usuario()->setRoles(['ROLE_SUPER_ADMIN']);

        self::assertSame(PoderSemPrazo::Master, $this->servico()->de($master, $this->escritorio));
        self::assertSame(PoderSemPrazo::Master, $this->servico()->de($master, $this->outro));
    }

    #[TestDox('Primário: com a opção ligada (padrão) tem o poder; com ela desligada, não')]
    public function testPrimarioComAOpcao(): void
    {
        $primario = $this->vincular($this->usuario(), true);

        self::assertSame(PoderSemPrazo::Primario, $this->servico()->de($primario, $this->escritorio));
        $this->opcaoLigada = false;
        self::assertNull($this->servico()->de($primario, $this->escritorio));
    }

    #[TestDox('Secundário nunca; primário de outro escritório nunca; conta desativada não é primário')]
    public function testQuemNaoTem(): void
    {
        $secundario = $this->vincular($this->usuario(), false);
        $primarioDeOutro = $this->usuario();
        $desativado = $this->vincular($this->usuario()->setIsActive(false), true);

        self::assertNull($this->servico()->de($secundario, $this->escritorio));
        self::assertNull($this->servico()->de($primarioDeOutro, $this->escritorio));
        self::assertNull($this->servico()->de($desativado, $this->escritorio));
    }

    #[TestDox('libera = regra de sempre OU poder; o selo só sai quando foi o poder que liberou')]
    public function testLiberaESelo(): void
    {
        $servico = $this->servico();
        $secundario = $this->vincular($this->usuario(), false);
        $primario = $this->vincular($this->usuario(), true);

        self::assertTrue($servico->libera($secundario, $this->escritorio, true));
        self::assertFalse($servico->libera($secundario, $this->escritorio, false));
        self::assertTrue($servico->libera($primario, $this->escritorio, false));
        self::assertNull($servico->selo($primario, $this->escritorio, true));
        self::assertSame(PoderSemPrazo::Primario, $servico->selo($primario, $this->escritorio, false));
        self::assertNull($servico->selo($secundario, $this->escritorio, false));
        self::assertSame('edicao_sem_prazo.selo.primario', PoderSemPrazo::Primario->chaveDoSelo());
    }

    #[TestDox('A resposta fica guardada na requisição; esquecer() relê a opção')]
    public function testMemoria(): void
    {
        $servico = $this->servico();
        $primario = $this->vincular($this->usuario(), true);
        self::assertNotNull($servico->de($primario, $this->escritorio));

        $this->opcaoLigada = false;
        self::assertNotNull($servico->de($primario, $this->escritorio), 'guardada');
        $servico->esquecer();
        self::assertNull($servico->de($primario, $this->escritorio));
    }

    private function servico(): PoderDeEdicaoSemPrazo
    {
        $vinculos = $this->createStub(UserTenantRepository::class);
        $vinculos->method('findAtivoPorUserETenant')->willReturnCallback(
            fn (User $u, Tenant $t): ?UserTenant => $t === $this->escritorio ? ($this->vinculos[(int) $u->getId()] ?? null) : null,
        );
        $configuracoes = $this->createStub(ConfiguracaoDeEdicaoRepository::class);
        $configuracoes->method('primarioSemPrazo')->willReturnCallback(fn (): bool => $this->opcaoLigada);

        return new PoderDeEdicaoSemPrazo(new RegraDosPrimarios($vinculos), $vinculos, $configuracoes);
    }

    private function vincular(User $usuario, bool $primario): User
    {
        $this->vinculos[(int) $usuario->getId()] = (new UserTenant($usuario, $this->escritorio))
            ->setTenantRole((new TenantRole())->setIsSystem($primario));

        return $usuario;
    }

    private function usuario(): User
    {
        $usuario = (new User())->setEmail(uniqid('p_') . '@teste.com')->setIsActive(true);
        (new \ReflectionProperty(User::class, 'id'))->setValue($usuario, $this->proximoId++);

        return $usuario;
    }

    private function tenant(int $id): Tenant
    {
        $tenant = new Tenant();
        (new \ReflectionProperty(Tenant::class, 'id'))->setValue($tenant, $id);

        return $tenant;
    }
}
