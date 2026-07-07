<?php

declare(strict_types=1);

namespace App\Tests\Djen\Unit;

use App\Auth\Service\OabWebServiceClientInterface;
use App\Auth\Service\ValidadorOab;
use App\Djen\DTO\OabMonitoradaInput;
use App\Djen\Entity\OabMonitorada;
use App\Djen\Exception\OabMonitoradaJaExisteException;
use App\Djen\Repository\OabMonitoradaRepository;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Djen\UseCase\AdicionarOabMonitoradaUseCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(AdicionarOabMonitoradaUseCase::class)]
final class AdicionarOabMonitoradaUseCaseTest extends TestCase
{
    private OabMonitoradaRepository&MockObject $oabRepository;
    private AdicionarOabMonitoradaUseCase $sut;
    private Tenant&MockObject $tenant;
    private User&MockObject $user;

    protected function setUp(): void
    {
        $this->oabRepository = $this->createMock(OabMonitoradaRepository::class);
        // ValidadorOab é puro em validarFormato (não usa o client); construído com um client dummy.
        $validador = new ValidadorOab($this->createMock(OabWebServiceClientInterface::class));
        $this->sut = new AdicionarOabMonitoradaUseCase($this->oabRepository, $validador);
        $this->tenant = $this->createMock(Tenant::class);
        $this->user = $this->createMock(User::class);
    }

    #[Test]
    public function adicionaNormalizandoUfMaiusculaEApelido(): void
    {
        $this->oabRepository->method('findByNumeroUfDoTenant')->willReturn(null);
        $this->oabRepository->expects($this->once())->method('salvar');

        $oab = $this->sut->executar($this->input('67228', 'pr', '  Dra. Fulana  '), $this->tenant, $this->user);

        self::assertSame('67228', $oab->getNumero());
        self::assertSame('PR', $oab->getUf());
        self::assertSame('Dra. Fulana', $oab->getApelido());
        self::assertTrue($oab->isAtivo());
        self::assertSame($this->tenant, $oab->getTenant());
    }

    #[Test]
    public function normalizaNumeroComPontuacaoParaSoDigitos(): void
    {
        $this->oabRepository->method('findByNumeroUfDoTenant')->willReturn(null);
        $this->oabRepository->expects($this->once())->method('salvar');

        $oab = $this->sut->executar($this->input('67.228', 'PR', null), $this->tenant, $this->user);

        self::assertSame('67228', $oab->getNumero());
    }

    #[Test]
    public function oabDuplicadaLancaExcecaoENaoSalva(): void
    {
        $this->oabRepository->method('findByNumeroUfDoTenant')->willReturn(new OabMonitorada());
        $this->oabRepository->expects($this->never())->method('salvar');

        $this->expectException(OabMonitoradaJaExisteException::class);

        $this->sut->executar($this->input('67228', 'PR', null), $this->tenant, $this->user);
    }

    #[Test]
    public function formatoInvalidoLancaInvalidArgumentENaoSalva(): void
    {
        $this->oabRepository->expects($this->never())->method('salvar');

        $this->expectException(\InvalidArgumentException::class);

        // "abc" perde todos os dígitos na normalização -> número vazio -> formato inválido.
        $this->sut->executar($this->input('abc', 'PR', null), $this->tenant, $this->user);
    }

    private function input(?string $numero, ?string $uf, ?string $apelido): OabMonitoradaInput
    {
        $input = new OabMonitoradaInput();
        $input->numero = $numero;
        $input->uf = $uf;
        $input->apelido = $apelido;

        return $input;
    }
}
