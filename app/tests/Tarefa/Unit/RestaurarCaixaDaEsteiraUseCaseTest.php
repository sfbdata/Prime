<?php

declare(strict_types=1);

namespace App\Tests\Tarefa\Unit;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Tarefa\Entity\CaixaDaEsteira;
use App\Tarefa\Enum\EtapaDaPasta;
use App\Tarefa\Exception\CaixaNaoConfiguravelException;
use App\Tarefa\Repository\CaixaDaEsteiraRepository;
use App\Tarefa\Service\QuemConfiguraACaixa;
use App\Tarefa\UseCase\RestaurarCaixaDaEsteiraUseCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(RestaurarCaixaDaEsteiraUseCase::class)]
#[Group('tarefa')]
final class RestaurarCaixaDaEsteiraUseCaseTest extends TestCase
{
    private Tenant $escritorio;
    private CaixaDaEsteiraRepository&MockObject $caixas;

    protected function setUp(): void
    {
        $this->escritorio = new Tenant();
        $this->caixas     = $this->createMock(CaixaDaEsteiraRepository::class);
    }

    #[TestDox('Sem permissão: semPermissao, nada removido')]
    public function testSemPermissao(): void
    {
        $this->caixas->expects(self::never())->method('daEtapa');
        $this->caixas->expects(self::never())->method('remover');

        $this->expectExceptionObject(CaixaNaoConfiguravelException::semPermissao());

        $this->useCase(false)->executar(new User(), $this->escritorio, EtapaDaPasta::Juridico);
    }

    #[TestDox('Caixa configurada: remove a linha com flush')]
    public function testRemoveSeExiste(): void
    {
        $caixa = new CaixaDaEsteira($this->escritorio, EtapaDaPasta::Protocolo);
        $this->caixas->method('daEtapa')->with($this->escritorio, EtapaDaPasta::Protocolo)->willReturn($caixa);
        $this->caixas->expects(self::once())->method('remover')->with($caixa, true);

        $this->useCase(true)->executar(new User(), $this->escritorio, EtapaDaPasta::Protocolo);
    }

    #[TestDox('Caixa já padrão: nada a fazer, sem erro')]
    public function testNadaSeNaoExiste(): void
    {
        $this->caixas->method('daEtapa')->willReturn(null);
        $this->caixas->expects(self::never())->method('remover');

        $this->useCase(true)->executar(new User(), $this->escritorio, EtapaDaPasta::Protocolo);
    }

    private function useCase(bool $pode): RestaurarCaixaDaEsteiraUseCase
    {
        $quem = $this->createStub(QuemConfiguraACaixa::class);
        $quem->method('pode')->willReturn($pode);

        return new RestaurarCaixaDaEsteiraUseCase($this->caixas, $quem);
    }
}
