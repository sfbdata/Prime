<?php

declare(strict_types=1);

namespace App\Tests\TrabalhoExterno\Unit;

use App\Shared\Armazenamento\ArmazenamentoDeArquivos;
use App\Shared\Armazenamento\RemocaoAposTransacao;
use App\TrabalhoExterno\DTO\DadosDoCurriculo;
use App\TrabalhoExterno\Enum\Perfil;
use App\TrabalhoExterno\Exception\TransicaoInvalida;
use App\TrabalhoExterno\Repository\CurriculoRepository;
use App\TrabalhoExterno\Service\PoliticaDaDemanda;
use App\TrabalhoExterno\Service\PoliticaDaVaga;
use App\TrabalhoExterno\Service\PoliticaDoCurriculo;
use App\TrabalhoExterno\UseCase\SalvarMeuCurriculoUseCase;
use Doctrine\DBAL\Driver\Exception as ExcecaoDoDriver;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[CoversClass(SalvarMeuCurriculoUseCase::class)]
final class SalvarMeuCurriculoUseCaseTest extends TestCase
{
    #[TestDox('dois primeiros envios ao mesmo tempo: o que esbarra no índice único recebe "Seu currículo já foi salvo. Atualize a página."')]
    public function testCorridaDoPrimeiroSalvar(): void
    {
        $repositorio = $this->createMock(CurriculoRepository::class);
        $repositorio->method('doUsuario')->willReturn(null);
        $repositorio->method('salvar')->willThrowException(new UniqueConstraintViolationException($this->createStub(ExcecaoDoDriver::class), null));
        $useCase = new SalvarMeuCurriculoUseCase(
            new PoliticaDoCurriculo(new PoliticaDaVaga(new PoliticaDaDemanda()), new PoliticaDaDemanda()),
            $repositorio,
            new RemocaoAposTransacao($this->createStub(ArmazenamentoDeArquivos::class), new NullLogger()),
        );

        $this->expectException(TransicaoInvalida::class);
        $this->expectExceptionMessage('Seu currículo já foi salvo. Atualize a página.');

        $useCase->executar(Apoio::contexto(Perfil::Solicitante), DadosDoCurriculo::deArray([
            'nome' => 'Ana', 'email' => 'ana@exemplo.test', 'situacao' => 'bach', 'lgpd' => true,
        ]));
    }
}
