<?php

declare(strict_types=1);

namespace App\Tests\Inteligencia\Unit;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Inteligencia\Entity\AnaliseDeInteligencia;
use App\Inteligencia\Enum\TipoDeAnalise;
use App\Inteligencia\Exception\AnaliseNaoEncontradaException;
use App\Inteligencia\Repository\AnaliseDeInteligenciaRepository;
use App\Inteligencia\UseCase\MarcarAnaliseComoNaoLidaUseCase;
use App\Tests\Inteligencia\Support\DefineId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(MarcarAnaliseComoNaoLidaUseCase::class)]
#[CoversClass(AnaliseDeInteligencia::class)]
final class MarcarAnaliseComoNaoLidaUseCaseTest extends TestCase
{
    private function analise(Tenant $tenant): AnaliseDeInteligencia
    {
        $analise = new AnaliseDeInteligencia($tenant, null, TipoDeAnalise::ResumoPush, AnaliseDeInteligencia::ALVO_PASTA, 7, 'v1', str_repeat('a', 64), []);
        DefineId::em($analise, 3);

        return $analise;
    }

    #[TestDox('lida → não lida: zera lida_em e lida_por e salva; repetir é inofensivo')]
    public function testZeraALeitura(): void
    {
        $tenant = new Tenant();
        $analise = $this->analise($tenant);
        $analise->marcarLida(new User());
        $repo = $this->createMock(AnaliseDeInteligenciaRepository::class);
        $repo->method('findOneDoTenantEAlvo')->with(3, $tenant, AnaliseDeInteligencia::ALVO_PASTA, 7)->willReturn($analise);
        $repo->expects(self::exactly(2))->method('salvar')->with($analise, true);
        $useCase = new MarcarAnaliseComoNaoLidaUseCase($repo);

        $saida = $useCase->executar(3, 7, $tenant);

        self::assertFalse($saida->lida);
        self::assertNull($analise->getLidaEm());
        self::assertFalse($useCase->executar(3, 7, $tenant)->lida);
    }

    #[TestDox('não encontrada (outro escritório/pasta) ou excluída → AnaliseNaoEncontradaException, sem salvar')]
    public function testNaoEncontrada(): void
    {
        $tenant = new Tenant();
        $excluida = $this->analise($tenant);
        $excluida->excluir(new User());
        $repo = $this->createMock(AnaliseDeInteligenciaRepository::class);
        $repo->method('findOneDoTenantEAlvo')->willReturnOnConsecutiveCalls(null, $excluida);
        $repo->expects(self::never())->method('salvar');
        $useCase = new MarcarAnaliseComoNaoLidaUseCase($repo);

        foreach ([1, 2] as $tentativa) {
            try {
                $useCase->executar(3, 7, $tenant);
                self::fail('esperava AnaliseNaoEncontradaException na tentativa ' . $tentativa);
            } catch (AnaliseNaoEncontradaException) {
                self::addToAssertionCount(1);
            }
        }
    }
}
