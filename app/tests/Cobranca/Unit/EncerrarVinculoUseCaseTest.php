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
    // Tenant não é abstração do domínio: instância real, não mock.
    private Tenant $tenant;

    protected function setUp(): void
    {
        $this->vinculoRepository = $this->createMock(VinculoPessoaObjetoRepository::class);
        $this->sut = new EncerrarVinculoUseCase($this->vinculoRepository);
        $this->tenant = new Tenant();
    }

    #[Test]
    public function encerraVinculoAbertoRegistrandoDataFimEMotivoSemRemover(): void
    {
        $vinculo = new VinculoPessoaObjeto();
        $dataFim = new \DateTimeImmutable('2026-03-01');

        $this->vinculoRepository
            ->expects($this->once())
            ->method('findOneByIdDoTenant')
            ->with(7, $this->tenant)
            ->willReturn($vinculo);

        // Encerrar preserva o registro: salva 1x, nunca remove (invariável 11).
        $this->vinculoRepository
            ->expects($this->once())
            ->method('salvar')
            ->with(self::isInstanceOf(VinculoPessoaObjeto::class), true);
        $this->vinculoRepository->expects($this->never())->method('remover');

        $input = new EncerrarVinculoInput();
        $input->vinculoId = 7;
        $input->motivoEncerramento = '  Venda da unidade  ';
        $input->dataFim = $dataFim;
        $input->observacao = '  Repassado ao comprador  ';

        $resultado = $this->sut->executar($input, $this->tenant);

        self::assertSame($vinculo, $resultado);
        self::assertSame($dataFim, $resultado->getDataFim());
        self::assertSame('Venda da unidade', $resultado->getMotivoEncerramento());
        self::assertSame('Repassado ao comprador', $resultado->getObservacao());
        // Deixa de estar aberto após o encerramento.
        self::assertFalse($resultado->estaAberto());
    }

    #[Test]
    public function rejeitaQuandoVinculoNaoExisteNoTenant(): void
    {
        // Vínculo inexistente OU de outro escritório: findOneByIdDoTenant devolve null.
        $this->vinculoRepository->method('findOneByIdDoTenant')->willReturn(null);
        $this->vinculoRepository->expects($this->never())->method('salvar');

        $this->expectException(VinculoNaoEncontradoException::class);

        $this->sut->executar($this->input(), $this->tenant);
    }

    #[Test]
    public function rejeitaQuandoVinculoJaEncerrado(): void
    {
        // Vínculo já com data final: encerrar de novo sobrescreveria o histórico (invariável 11).
        $vinculo = new VinculoPessoaObjeto();
        $vinculo->setDataFim(new \DateTimeImmutable('2025-12-31'));

        $this->vinculoRepository->method('findOneByIdDoTenant')->willReturn($vinculo);
        $this->vinculoRepository->expects($this->never())->method('salvar');

        $this->expectException(VinculoJaEncerradoException::class);

        $this->sut->executar($this->input(), $this->tenant);
    }

    private function input(): EncerrarVinculoInput
    {
        $input = new EncerrarVinculoInput();
        $input->vinculoId = 7;
        $input->motivoEncerramento = 'Fim da locação';

        return $input;
    }
}
