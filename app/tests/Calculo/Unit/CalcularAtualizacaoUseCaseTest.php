<?php

declare(strict_types=1);

namespace App\Tests\Calculo\Unit;

use App\AtualizacaoMonetaria\Repository\IndiceMonetarioRepository;
use App\AtualizacaoMonetaria\Service\TabelaIndices;
use App\Calculo\DTO\CalculoInput;
use App\Calculo\DTO\ErroDoCalculo;
use App\Calculo\DTO\ResultadoDoCalculo;
use App\Calculo\DTO\ValorDoCalculo;
use App\Calculo\Service\CalculadoraDeAtualizacao;
use App\Calculo\UseCase\CalcularAtualizacaoUseCase;
use App\Tests\Calculo\CalculoDeFixture;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Contracts\Translation\TranslatorInterface;

final class CalcularAtualizacaoUseCaseTest extends TestCase
{
    /** @param array<string, mixed> $campos */
    private function entrada(array $campos = []): CalculoInput
    {
        return CalculoInput::fromArray($campos + [
            'ate' => '2026-10-30', 'idx' => 'oficial', 'jur' => 'legal', 'ini' => 'valores', 'proj' => 'media',
            'vals' => [['d' => '2026-08-25', 'v' => '1.000,00', 't' => 'Parcela']],
        ]);
    }

    private function useCase(TabelaIndices $tabela = null): CalcularAtualizacaoUseCase
    {
        $repositorio = $this->createStub(IndiceMonetarioRepository::class);
        $repositorio->method('carregarTabela')->willReturn($tabela ?? CalculoDeFixture::tabela());
        $tradutor = $this->createStub(TranslatorInterface::class);
        $tradutor->method('trans')->willReturnCallback(static fn (string $id): string => $id);

        return new CalcularAtualizacaoUseCase($repositorio, new CalculadoraDeAtualizacao(), new MockClock('2026-10-30 14:00:00'), $tradutor);
    }

    public function testCalculaQuandoTudoEstaCerto(): void
    {
        $r = $this->useCase()->executar($this->entrada());

        self::assertInstanceOf(ResultadoDoCalculo::class, $r);
        self::assertSame('1,004974', number_format($r->linhas[0]->fator, 6, ',', ''));
    }

    public function testSemValorNenhumPedeParaIrAoCampoDeValores(): void
    {
        $r = $this->useCase()->executar($this->entrada(['vals' => [['d' => '', 'v' => '', 't' => '']]]));

        self::assertInstanceOf(ErroDoCalculo::class, $r);
        self::assertSame('calculo.erro.sem_valores', $r->mensagem);
        self::assertSame('nvV', $r->campo);
        self::assertSame('calculo.erro.ir_valores', $r->irTxt);
    }

    public function testValorSemDataNaoContaComoValor(): void
    {
        $r = $this->useCase()->executar($this->entrada(['vals' => [['d' => '', 'v' => '100,00', 't' => '']]]));

        self::assertInstanceOf(ErroDoCalculo::class, $r);
        self::assertSame('nvV', $r->campo);
    }

    public function testSemDataFinalPedeOCampo(): void
    {
        $r = $this->useCase()->executar($this->entrada(['ate' => '']));

        self::assertInstanceOf(ErroDoCalculo::class, $r);
        self::assertSame(['calculo.erro.sem_ate', 'ate'], [$r->mensagem, $r->campo]);
    }

    public function testJurosDeDataFixaOuCitacaoExigemADataDeInicio(): void
    {
        $fixa = $this->useCase()->executar($this->entrada(['ini' => 'fixa', 'dJur' => '']));
        $citacao = $this->useCase()->executar($this->entrada(['ini' => 'citacao', 'dJur' => '']));

        self::assertInstanceOf(ErroDoCalculo::class, $fixa);
        self::assertSame(['calculo.erro.sem_data_juros_fixa', 'dJur'], [$fixa->mensagem, $fixa->campo]);
        self::assertInstanceOf(ErroDoCalculo::class, $citacao);
        self::assertSame('calculo.erro.sem_data_juros_citacao', $citacao->mensagem);
    }

    public function testSemIndicesCarregadosAvisaQuandoOCalculoPrecisaDeles(): void
    {
        $vazio = new TabelaIndices([]);

        $comIndice = $this->useCase($vazio)->executar($this->entrada());
        $semNada = $this->useCase($vazio)->executar($this->entrada(['idx' => 'nenhum', 'jur' => '1']));

        self::assertInstanceOf(ErroDoCalculo::class, $comIndice);
        self::assertSame('calculo.erro.sem_indices', $comIndice->mensagem);
        self::assertInstanceOf(ResultadoDoCalculo::class, $semNada, 'sem correção e com juros simples não precisa de índice');
    }

    public function testDataInvalidaEhRecusada(): void
    {
        $r = $this->useCase()->executar($this->entrada(['vals' => [['d' => '2026-02-30', 'v' => '100,00', 't' => '']]]));

        self::assertInstanceOf(ErroDoCalculo::class, $r);
        self::assertSame('calculo.erro.data_invalida', $r->mensagem);
    }

    public function testAnoForaDaFaixaEhRecusado(): void
    {
        foreach (['0001-01-01', '9999-12-31'] as $data) {
            $r = $this->useCase()->executar($this->entrada(['vals' => [['d' => $data, 'v' => '100,00', 't' => '']]]));

            self::assertInstanceOf(ErroDoCalculo::class, $r, $data);
            self::assertSame('calculo.erro.data_invalida', $r->mensagem);
        }
    }

    public function testNumeroComExpoenteOuGigantePassaAValerZero(): void
    {
        foreach (['9e999', '1e3', '-5', '99999999999999999999'] as $texto) {
            self::assertSame(0.0, (new ValorDoCalculo('2026-08-25', $texto, ''))->valorNumerico(), $texto);
        }
        self::assertSame(1234.56, (new ValorDoCalculo('2026-08-25', '1.234,56', ''))->valorNumerico());
    }

    public function testRecusaListaGrandeDemais(): void
    {
        $muitos = array_fill(0, CalcularAtualizacaoUseCase::MAX_VALORES + 1, ['d' => '2026-08-25', 'v' => '1,00', 't' => '']);

        $r = $this->useCase()->executar($this->entrada(['vals' => $muitos]));

        self::assertInstanceOf(ErroDoCalculo::class, $r);
        self::assertSame('calculo.erro.muitos_valores', $r->mensagem);
    }
}
