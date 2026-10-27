<?php

declare(strict_types=1);

namespace App\Tests\Tarefa\Unit;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Repository\UserTenantRepository;
use App\Tarefa\DTO\ConfigurarCaixaDaEsteiraInput;
use App\Tarefa\Entity\CaixaDaEsteira;
use App\Tarefa\Enum\EtapaDaPasta;
use App\Tarefa\Exception\CaixaNaoConfiguravelException;
use App\Tarefa\Repository\CaixaDaEsteiraRepository;
use App\Tarefa\Service\QuemConfiguraACaixa;
use App\Tarefa\UseCase\ConfigurarCaixaDaEsteiraUseCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(ConfigurarCaixaDaEsteiraUseCase::class)]
#[Group('tarefa')]
final class ConfigurarCaixaDaEsteiraUseCaseTest extends TestCase
{
    private Tenant $escritorio;
    private User $usuario;
    private CaixaDaEsteiraRepository&MockObject $caixas;

    protected function setUp(): void
    {
        $this->escritorio = new Tenant();
        $this->usuario    = $this->usuario(1, 'Primário');
        $this->caixas     = $this->createMock(CaixaDaEsteiraRepository::class);
    }

    #[TestDox('Sem ser primário nem administrar perfis: semPermissao, nada gravado')]
    public function testSemPermissao(): void
    {
        $this->caixas->expects(self::never())->method('salvar');
        $this->caixas->expects(self::never())->method('remover');

        $this->expectExceptionObject(CaixaNaoConfiguravelException::semPermissao());

        $this->useCase(false)->executar($this->usuario, $this->escritorio, EtapaDaPasta::Juridico, new ConfigurarCaixaDaEsteiraInput('X', ''));
    }

    #[TestDox('Avisado fora da equipe ativa: avisadoForaDaEquipe, nada gravado')]
    public function testAvisadoForaDaEquipe(): void
    {
        $this->caixas->expects(self::never())->method('salvar');

        $this->expectExceptionObject(CaixaNaoConfiguravelException::avisadoForaDaEquipe());

        $this->useCase(true, [$this->usuario(2, 'Ana')])
            ->executar($this->usuario, $this->escritorio, EtapaDaPasta::Juridico, new ConfigurarCaixaDaEsteiraInput('X', '', [2, 99]));
    }

    #[TestDox('Caso normal: grava nomes e avisados e salva com flush')]
    public function testCasoNormalSalva(): void
    {
        $ana = $this->usuario(2, 'Ana');
        $this->caixas->method('daEtapa')->willReturn(null);
        $this->caixas->expects(self::never())->method('remover');
        $this->caixas->expects(self::once())->method('salvar')
            ->with(self::callback(function (CaixaDaEsteira $caixa) use ($ana): bool {
                return $caixa->getEtapa() === EtapaDaPasta::Protocolo
                    && $caixa->getNomeDaCaixa() === 'Fila'
                    && $caixa->getNomeDaEtapa() === 'Protocolo e distribuição'
                    && $caixa->getAvisados()->toArray() === [$ana];
            }), true);

        $this->useCase(true, [$ana])->executar(
            $this->usuario,
            $this->escritorio,
            EtapaDaPasta::Protocolo,
            new ConfigurarCaixaDaEsteiraInput(' Fila ', 'Protocolo e distribuição', [2]),
        );
    }

    #[TestDox('Avisado repetido entra uma vez')]
    public function testRepetidosEntramUmaVez(): void
    {
        $ana   = $this->usuario(2, 'Ana');
        $bruno = $this->usuario(3, 'Bruno');
        $this->caixas->expects(self::once())->method('salvar')
            ->with(self::callback(static fn (CaixaDaEsteira $c): bool => $c->getAvisados()->toArray() === [$ana, $bruno]), true);

        $this->useCase(true, [$ana, $bruno])
            ->executar($this->usuario, $this->escritorio, EtapaDaPasta::Juridico, new ConfigurarCaixaDaEsteiraInput('', '', [2, 3, 2, 3]));
    }

    #[TestDox('Tudo de volta ao padrão apaga a linha gravada e não salva')]
    public function testVoltaAoPadraoApagaALinha(): void
    {
        $gravada = new CaixaDaEsteira($this->escritorio, EtapaDaPasta::Juridico);
        $gravada->configurar('Antigo', 'Antiga', []);
        (new \ReflectionProperty(CaixaDaEsteira::class, 'id'))->setValue($gravada, 10);
        $this->caixas->method('daEtapa')->willReturn($gravada);
        $this->caixas->expects(self::never())->method('salvar');
        $this->caixas->expects(self::once())->method('remover')->with($gravada, true);

        $this->useCase(true)->executar($this->usuario, $this->escritorio, EtapaDaPasta::Juridico, new ConfigurarCaixaDaEsteiraInput('  ', '', []));
    }

    #[TestDox('Tudo no padrão numa caixa que nunca foi gravada: nem salva nem remove')]
    public function testPadraoSemLinhaNaoFazNada(): void
    {
        $this->caixas->method('daEtapa')->willReturn(null);
        $this->caixas->expects(self::never())->method('salvar');
        $this->caixas->expects(self::never())->method('remover');

        $this->useCase(true)->executar($this->usuario, $this->escritorio, EtapaDaPasta::Juridico, new ConfigurarCaixaDaEsteiraInput('', ''));
    }

    /** @param list<User> $equipe */
    private function useCase(bool $pode, array $equipe = []): ConfigurarCaixaDaEsteiraUseCase
    {
        $vinculos = $this->createStub(UserTenantRepository::class);
        $vinculos->method('findAtivosComCargoELotacaoPorTenant')->willReturn(
            array_map(fn (User $u): UserTenant => new UserTenant($u, $this->escritorio), $equipe),
        );
        $quem = $this->createStub(QuemConfiguraACaixa::class);
        $quem->method('pode')->willReturn($pode);

        return new ConfigurarCaixaDaEsteiraUseCase($this->caixas, $vinculos, $quem);
    }

    private function usuario(int $id, string $nome): User
    {
        $usuario = new User();
        $usuario->setFullName($nome);
        (new \ReflectionProperty(User::class, 'id'))->setValue($usuario, $id);

        return $usuario;
    }
}
