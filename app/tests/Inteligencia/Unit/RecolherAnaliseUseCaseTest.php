<?php

declare(strict_types=1);

namespace App\Tests\Inteligencia\Unit;

use App\Dashboard\Repository\PreferenciaDoUsuarioRepository;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Inteligencia\Entity\AnaliseDeInteligencia;
use App\Inteligencia\Enum\TipoDeAnalise;
use App\Inteligencia\Exception\AnaliseNaoEncontradaException;
use App\Inteligencia\Preferencia\CatalogoDePreferenciasDaInteligencia;
use App\Inteligencia\Repository\AnaliseDeInteligenciaRepository;
use App\Inteligencia\UseCase\ObterAnalisesRecolhidasUseCase;
use App\Inteligencia\UseCase\RecolherAnaliseUseCase;
use App\Tests\Inteligencia\Support\DefineId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * IA-010: recolher/expandir é preferência do usuário. O UseCase confere a posse da análise ANTES
 * de gravar; o catálogo só deixa entrar lista de ids inteiros, sem repetição e com teto.
 */
#[CoversClass(RecolherAnaliseUseCase::class)]
#[CoversClass(ObterAnalisesRecolhidasUseCase::class)]
#[CoversClass(CatalogoDePreferenciasDaInteligencia::class)]
final class RecolherAnaliseUseCaseTest extends TestCase
{
    private const CHAVE = 'push.ia.recolhidas';

    private Tenant $tenant;
    private User $user;
    private AnaliseDeInteligenciaRepository&MockObject $analises;
    private PreferenciaDoUsuarioRepository&MockObject $preferencias;

    protected function setUp(): void
    {
        $this->tenant = new Tenant();
        $this->user = new User();
        $this->analises = $this->createMock(AnaliseDeInteligenciaRepository::class);
        $this->preferencias = $this->createMock(PreferenciaDoUsuarioRepository::class);
    }

    private function analise(): AnaliseDeInteligencia
    {
        $analise = new AnaliseDeInteligencia($this->tenant, null, TipoDeAnalise::ResumoPush, AnaliseDeInteligencia::ALVO_PASTA, 7, 'v1', str_repeat('a', 64), []);
        DefineId::em($analise, 9);

        return $analise;
    }

    private function useCase(): RecolherAnaliseUseCase
    {
        return new RecolherAnaliseUseCase($this->analises, $this->preferencias);
    }

    #[TestDox('recolher acrescenta o id no fim da lista do usuário, sem repetir')]
    public function testRecolherAcrescenta(): void
    {
        $this->analises->method('findOneDoTenantEAlvo')->with(9, $this->tenant, AnaliseDeInteligencia::ALVO_PASTA, 7)->willReturn($this->analise());
        $this->preferencias->method('valoresDoUsuario')->with($this->tenant, $this->user, [self::CHAVE])->willReturn([self::CHAVE => [3, 9, 4]]);
        $this->preferencias->expects(self::once())->method('gravar')->with($this->tenant, $this->user, self::CHAVE, [3, 4, 9]);

        self::assertTrue($this->useCase()->executar(9, 7, true, $this->user, $this->tenant));
    }

    #[TestDox('expandir retira o id; sem linha gravada começa da lista vazia')]
    public function testExpandirRetira(): void
    {
        $this->analises->method('findOneDoTenantEAlvo')->willReturn($this->analise());
        $this->preferencias->method('valoresDoUsuario')->willReturn([]);
        $this->preferencias->expects(self::once())->method('gravar')->with($this->tenant, $this->user, self::CHAVE, []);

        self::assertFalse($this->useCase()->executar(9, 7, false, $this->user, $this->tenant));
    }

    #[TestDox('análise de outro escritório/pasta (null) → AnaliseNaoEncontradaException e NADA gravado')]
    public function testNaoEncontradaNaoGrava(): void
    {
        $this->analises->method('findOneDoTenantEAlvo')->willReturn(null);
        $this->preferencias->expects(self::never())->method('gravar');

        $this->expectException(AnaliseNaoEncontradaException::class);
        $this->useCase()->executar(9, 7, true, $this->user, $this->tenant);
    }

    #[TestDox('análise excluída → AnaliseNaoEncontradaException e NADA gravado')]
    public function testExcluidaNaoGrava(): void
    {
        $analise = $this->analise();
        $analise->excluir($this->user);
        $this->analises->method('findOneDoTenantEAlvo')->willReturn($analise);
        $this->preferencias->expects(self::never())->method('gravar');

        $this->expectException(AnaliseNaoEncontradaException::class);
        $this->useCase()->executar(9, 7, true, $this->user, $this->tenant);
    }

    #[TestDox('a leitura devolve só ids válidos do usuário/escritório da sessão')]
    public function testObterRecolhidas(): void
    {
        $this->preferencias->method('valoresDoUsuario')->with($this->tenant, $this->user, [self::CHAVE])
            ->willReturn([self::CHAVE => [5, '6', -1, 5, 7.0, null, 8]]);

        self::assertSame([5, 8], (new ObterAnalisesRecolhidasUseCase($this->preferencias))->executar($this->tenant, $this->user));
    }

    /** @return iterable<string, array{mixed, list<int>}> */
    public static function valoresGravados(): iterable
    {
        yield 'nulo' => [null, []];
        yield 'texto' => ['1,2', []];
        yield 'objeto' => [['a' => 1], [1]];
        yield 'repetidos' => [[1, 1, 2], [1, 2]];
        yield 'zero e negativos' => [[0, -3, 4], [4]];
    }

    #[DataProvider('valoresGravados')]
    #[TestDox('valor gravado estranho ($_dataName) nunca derruba a tela: vira lista de ids válidos')]
    public function testLeituraTolerante(mixed $valor, array $esperado): void
    {
        self::assertSame($esperado, CatalogoDePreferenciasDaInteligencia::recolhidasDe($valor));
    }

    #[TestDox('teto de 200: ao recolher a 201ª, a mais antiga sai')]
    public function testTeto(): void
    {
        $atuais = range(1, CatalogoDePreferenciasDaInteligencia::MAXIMO_RECOLHIDAS);

        $nova = CatalogoDePreferenciasDaInteligencia::alternar($atuais, 999, true);

        self::assertCount(CatalogoDePreferenciasDaInteligencia::MAXIMO_RECOLHIDAS, $nova);
        self::assertSame(2, $nova[0]);
        self::assertSame(999, $nova[array_key_last($nova)]);
    }
}
