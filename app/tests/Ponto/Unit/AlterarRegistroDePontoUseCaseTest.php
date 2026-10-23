<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Unit;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Ponto\DTO\AlterarRegistroDePontoInput;
use App\Ponto\Entity\BlocoJornadaColaborador;
use App\Ponto\Entity\JornadaColaborador;
use App\Ponto\Exception\ChoqueDeJornadaException;
use App\Ponto\Repository\JornadaColaboradorRepository;
use App\Ponto\Repository\JornadaTenantRepository;
use App\Ponto\Service\ChoqueDeJornadaEntreEscritorios;
use App\Ponto\Service\IntervalosDeTrabalho;
use App\Ponto\Service\JornadaResolver;
use App\Ponto\UseCase\AlterarRegistroDePontoUseCase;
use App\Repository\UserTenantRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * A chave "Registra ponto eletrônico" (spec seguranca-b-jornada, regras 2 e 3). A pessoa trabalha em A de segunda das
 * 09:00 às 12:00, e em B, onde já registra ponto, de segunda das 08:00 às 10:00.
 */
#[CoversClass(AlterarRegistroDePontoUseCase::class)]
final class AlterarRegistroDePontoUseCaseTest extends TestCase
{
    private User $pessoa;
    private Tenant $a;
    private Tenant $b;
    private UserTenant $emA;
    private UserTenantRepository&MockObject $vinculos;

    protected function setUp(): void
    {
        $this->pessoa = new User();
        $this->a = $this->escritorio(1);
        $this->b = $this->escritorio(2);
        $this->emA = new UserTenant($this->pessoa, $this->a);
        $this->emA->alterarRegistroDePonto(false);
        $this->vinculos = $this->createMock(UserTenantRepository::class);
        $this->vinculos->method('findAtivoPorUserETenant')->willReturnCallback(
            fn (User $u, Tenant $t): ?UserTenant => $t === $this->a ? $this->emA : null
        );
        $this->vinculos->method('findActiveByUser')->willReturn([$this->emA, new UserTenant($this->pessoa, $this->b)]);
    }

    #[TestDox('Ligar a chave quando a jornada daqui bate com outro escritório: recusa com a mensagem e não grava')]
    public function testLigarComChoqueRecusa(): void
    {
        $this->vinculos->expects(self::never())->method('salvar');

        try {
            $this->sut(['09:00', '12:00'])->executar(new AlterarRegistroDePontoInput($this->pessoa, $this->a, true));
            self::fail('deveria recusar');
        } catch (ChoqueDeJornadaException $e) {
            self::assertSame(sprintf(ChoqueDeJornadaEntreEscritorios::MENSAGEM_CHOQUE, '08:00', '10:00'), $e->getMessage());
        }
        self::assertFalse($this->emA->isRegistraPonto());
    }

    #[TestDox('Ligar sem choque grava a chave ligada, com um flush')]
    public function testLigarSemChoqueGrava(): void
    {
        $this->vinculos->expects(self::once())->method('salvar')->with($this->emA, true);

        $this->sut(['10:00', '12:00'])->executar(new AlterarRegistroDePontoInput($this->pessoa, $this->a, true));

        self::assertTrue($this->emA->isRegistraPonto());
    }

    #[TestDox('Desligar não confere choque nenhum')]
    public function testDesligarNaoConfereChoque(): void
    {
        $this->emA->alterarRegistroDePonto(true);
        $this->vinculos->expects(self::once())->method('salvar');

        $this->sut(['09:00', '12:00'])->executar(new AlterarRegistroDePontoInput($this->pessoa, $this->a, false));

        self::assertFalse($this->emA->isRegistraPonto());
    }

    #[TestDox('Sem vínculo ativo no escritório: recusa')]
    public function testSemVinculoRecusa(): void
    {
        $this->expectException(\DomainException::class);

        $this->sut(['09:00', '12:00'])->executar(new AlterarRegistroDePontoInput($this->pessoa, $this->b, true));
    }

    /** @param array{0: string, 1: string} $segundaEmA */
    private function sut(array $segundaEmA): AlterarRegistroDePontoUseCase
    {
        $pessoais = [1 => $this->pessoal(...$segundaEmA), 2 => $this->pessoal('08:00', '10:00')];
        $jornadas = $this->createStub(JornadaColaboradorRepository::class);
        $jornadas->method('findByUserETenant')->willReturnCallback(
            static fn (User $u, Tenant $t): ?JornadaColaborador => $pessoais[(int) $t->getId()] ?? null
        );
        $intervalos = new IntervalosDeTrabalho(new JornadaResolver(), $jornadas, $this->createStub(JornadaTenantRepository::class));

        return new AlterarRegistroDePontoUseCase($this->vinculos, $intervalos, new ChoqueDeJornadaEntreEscritorios($intervalos, $jornadas, $this->vinculos));
    }

    private function pessoal(string $entrada, string $saida): JornadaColaborador
    {
        return (new JornadaColaborador())->addBloco(
            (new BlocoJornadaColaborador())->setDiasSemana([1])->setEntrada($entrada)->setSaida($saida)
        );
    }

    private function escritorio(int $id): Tenant
    {
        $tenant = $this->createStub(Tenant::class);
        $tenant->method('getId')->willReturn($id);

        return $tenant;
    }
}
