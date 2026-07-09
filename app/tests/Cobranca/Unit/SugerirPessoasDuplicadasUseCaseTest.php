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
    // Tenant não é abstração do domínio: instância real, não mock.
    private Tenant $tenant;

    protected function setUp(): void
    {
        $this->pessoaRepository = $this->createMock(PessoaRepository::class);
        $this->sut = new SugerirPessoasDuplicadasUseCase($this->pessoaRepository);
        $this->tenant = new Tenant();
    }

    #[Test]
    public function retornaVazioSemDocumentosSemConsultarRepositorio(): void
    {
        // Sem CPF nem CNPJ (null ou em branco) não há o que comparar: nem toca o repositório.
        $this->pessoaRepository->expects($this->never())->method('buscarPossiveisDuplicadas');

        self::assertSame([], $this->sut->executar($this->tenant, null, '   '));
    }

    #[Test]
    public function delegaAoRepositorioComDocumentosNormalizados(): void
    {
        $esperado = [new Pessoa()];

        // O documento é normalizado (trim) antes de chegar ao repositório; escopo intra-tenant.
        $this->pessoaRepository
            ->expects($this->once())
            ->method('buscarPossiveisDuplicadas')
            ->with($this->tenant, '123.456', null)
            ->willReturn($esperado);

        $resultado = $this->sut->executar($this->tenant, '  123.456  ', null);

        self::assertSame($esperado, $resultado);
    }
}
