<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Unit;

use App\Cobranca\DTO\EncerrarVinculoInput;
use App\Cobranca\Entity\VinculoPessoaObjeto;
use App\Cobranca\Exception\VinculoJaEncerradoException;
use App\Cobranca\Exception\VinculoNaoEncontradoException;
use App\Cobranca\Repository\VinculoPessoaObjetoRepository;
use App\Cobranca\UseCase\EncerrarVinculoUseCase;
use App\Entity\Tenant\Tenant;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(EncerrarVinculoUseCase::class)]
final class EncerrarVinculoUseCaseTest extends TestCase
{
    private VinculoPessoaObjetoRepository&MockObject $vinculoRepository;
    private EncerrarVinculoUseCase $sut;
    private Tenant $tenant;

    protected function setUp(): void
    {
        $this->vinculoRepository = $this->createMock(VinculoPessoaObjetoRepository::class);
        $this->sut = new EncerrarVinculoUseCase($this->vinculoRepository);
        // Tenant não é abstração do domínio: instância real, não mock.
        $this->tenant = new Tenant();
    }

    #[Test]
    public function encerraVinculoAbertoAplicandoDataFimEMotivoSemRecriar(): void
    {
        // Vínculo real e ABERTO (dataFim null por padrão no construtor).
        $vinculo = new VinculoPessoaObjeto();

        $this->vinculoRepository
            ->expects($this->once())
            ->method('findOneByIdDoTenant')
            ->with(55, $this->tenant)
            ->willReturn($vinculo);

        // Salva o MESMO vínculo (só marca o fim, não recria).
        $this->vinculoRepository
            ->expects($this->once())
            ->method('salvar')
            ->with($vinculo, true);

        $input = new EncerrarVinculoInput();
        $input->vinculoId = 55;
        $input->dataFim = new \DateTimeImmutable('2026-03-20');
        $input->motivoEncerramento = '  Fim da locação  ';
        $input->observacao = '  entrega das chaves  ';

        $resultado = $this->sut->executar($input, $this->tenant);

        self::assertSame($vinculo, $resultado);
        self::assertFalse($resultado->estaAberto());
        self::assertEquals(new \DateTimeImmutable('2026-03-20'), $resultado->getDataFim());
        self::assertSame('Fim da locação', $resultado->getMotivoEncerramento());
        self::assertSame('entrega das chaves', $resultado->getObservacao());
    }

    #[Test]
    public function usaDataDeHojeQuandoDataFimNaoInformada(): void
    {
        $vinculo = new VinculoPessoaObjeto();
        $this->vinculoRepository->method('findOneByIdDoTenant')->willReturn($vinculo);
        $this->vinculoRepository->expects($this->once())->method('salvar');

        $input = new EncerrarVinculoInput();
        $input->vinculoId = 1;
        $input->motivoEncerramento = 'Venda';

        $resultado = $this->sut->executar($input, $this->tenant);

        self::assertNotNull($resultado->getDataFim());
        self::assertSame(
            (new \DateTimeImmutable())->format('Y-m-d'),
            $resultado->getDataFim()->format('Y-m-d'),
        );
    }

    #[Test]
    public function rejeitaVinculoInexistente(): void
    {
        $this->vinculoRepository->method('findOneByIdDoTenant')->willReturn(null);
        $this->vinculoRepository->expects($this->never())->method('salvar');

        $this->expectException(VinculoNaoEncontradoException::class);

        $input = new EncerrarVinculoInput();
        $input->vinculoId = 999;
        $input->motivoEncerramento = 'Qualquer';

        $this->sut->executar($input, $this->tenant);
    }

    #[Test]
    public function rejeitaReencerramentoDeVinculoJaFechadoENaoSalva(): void
    {
        // Vínculo já encerrado: dataFim preenchida antes.
        $vinculo = new VinculoPessoaObjeto();
        $vinculo->setDataFim(new \DateTimeImmutable('2025-12-01'));

        $this->vinculoRepository->method('findOneByIdDoTenant')->willReturn($vinculo);
        $this->vinculoRepository->expects($this->never())->method('salvar');

        $this->expectException(VinculoJaEncerradoException::class);

        $input = new EncerrarVinculoInput();
        $input->vinculoId = 55;
        $input->motivoEncerramento = 'Tentativa de reencerrar';

        $this->sut->executar($input, $this->tenant);
    }
}
