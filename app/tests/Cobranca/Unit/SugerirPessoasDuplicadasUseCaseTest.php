<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Unit;

use App\Cobranca\Entity\Pessoa;
use App\Cobranca\Repository\PessoaRepository;
use App\Cobranca\UseCase\SugerirPessoasDuplicadasUseCase;
use App\Entity\Tenant\Tenant;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(SugerirPessoasDuplicadasUseCase::class)]
final class SugerirPessoasDuplicadasUseCaseTest extends TestCase
{
    private PessoaRepository&MockObject $pessoaRepository;
    private SugerirPessoasDuplicadasUseCase $sut;
    private Tenant $tenant;

    protected function setUp(): void
    {
        $this->pessoaRepository = $this->createMock(PessoaRepository::class);
        $this->sut = new SugerirPessoasDuplicadasUseCase($this->pessoaRepository);
        // Tenant não é abstração do domínio: instância real, não mock.
        $this->tenant = new Tenant();
    }

    #[Test]
    public function retornaVazioSemConsultarQuandoCpfECnpjEstaoAusentes(): void
    {
        // Sem CPF nem CNPJ não há o que deduplicar: o repositório nem é acionado.
        $this->pessoaRepository->expects($this->never())->method('buscarPossiveisDuplicadas');

        self::assertSame([], $this->sut->executar($this->tenant, null, '   '));
    }

    #[Test]
    public function normalizaCpfFormatadoParaDigitosEDelegaAoRepositorio(): void
    {
        $duplicadas = [new Pessoa(), new Pessoa()];

        // CPF formatado deve casar por dígitos; CNPJ ausente vira string vazia.
        $this->pessoaRepository
            ->expects($this->once())
            ->method('buscarPossiveisDuplicadas')
            ->with($this->tenant, '12345678901', '')
            ->willReturn($duplicadas);

        $resultado = $this->sut->executar($this->tenant, '123.456.789-01', null);

        self::assertSame($duplicadas, $resultado);
    }

    #[Test]
    public function normalizaCnpjFormatadoParaDigitosEDelegaAoRepositorio(): void
    {
        $duplicadas = [new Pessoa()];

        // CNPJ formatado deve casar por dígitos; CPF ausente vira string vazia.
        $this->pessoaRepository
            ->expects($this->once())
            ->method('buscarPossiveisDuplicadas')
            ->with($this->tenant, '', '12345678000190')
            ->willReturn($duplicadas);

        $resultado = $this->sut->executar($this->tenant, null, '12.345.678/0001-90');

        self::assertSame($duplicadas, $resultado);
    }
}
