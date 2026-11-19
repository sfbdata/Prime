<?php

declare(strict_types=1);

namespace App\Tests\Profile\Unit;

use App\Entity\Auth\User;
use App\Profile\Entity\UserProfile;
use App\Profile\Enum\FormatoDeData;
use App\Profile\Enum\FormatoDeNumero;
use App\Profile\Enum\FusoHorario;
use App\Profile\Enum\Idioma;
use App\Profile\Exception\IdiomaIndisponivelException;
use App\Profile\Exception\OpcaoAvancadaInvalidaException;
use App\Profile\Repository\UserProfileRepository;
use App\Profile\UseCase\ObterOuCriarPerfilUseCase;
use App\Profile\UseCase\SalvarOpcoesAvancadasUseCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(SalvarOpcoesAvancadasUseCase::class)]
final class SalvarOpcoesAvancadasUseCaseTest extends TestCase
{
    #[TestDox('grava idioma, fuso, formato de data e de número de uma vez no perfil, que nasce se não existir')]
    public function testGravaTudo(): void
    {
        [$useCase, $perfil, $usuario] = $this->montar(salva: true);

        $devolvido = $useCase->executar($usuario, 'pt-BR', 'America/Los_Angeles', 'mdy', 'us');

        self::assertSame($perfil, $devolvido);
        self::assertSame(Idioma::PortuguesBrasil, $perfil->getIdioma());
        self::assertSame(FusoHorario::LosAngeles, $perfil->getFusoHorario());
        self::assertSame(FormatoDeData::MesDiaAno, $perfil->getFormatoData());
        self::assertSame(FormatoDeNumero::EstadosUnidos, $perfil->getFormatoNumero());
    }

    #[TestDox('vazio ou nulo volta ao padrão (nulo no banco); Brasília escolhida também fica nula, porque é o fuso do sistema')]
    public function testPadraoFicaNulo(): void
    {
        [$useCase, $perfil, $usuario] = $this->montar(salva: true);
        $perfil->setFusoHorario(FusoHorario::Londres)->setFormatoData(FormatoDeData::AnoMesDia)->setFormatoNumero(FormatoDeNumero::Brasil);

        $useCase->executar($usuario, 'pt-BR', 'America/Sao_Paulo', '', null);

        self::assertNull($perfil->getFusoHorario());
        self::assertNull($perfil->getFormatoData());
        self::assertNull($perfil->getFormatoNumero());
    }

    #[TestDox('idioma "em breve" ou desconhecido: recusa sem gravar nada')]
    public function testRecusaIdiomaNaoLiberado(): void
    {
        [$useCase, $perfil, $usuario] = $this->montar(salva: false);

        foreach (['es', 'xx', ''] as $valor) {
            try {
                $useCase->executar($usuario, $valor, 'Europe/London', 'ymd', 'us');
                self::fail('deveria recusar ' . $valor);
            } catch (IdiomaIndisponivelException) {
            }
        }
        self::assertNull($perfil->getFusoHorario());
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function opcoesForaDaLista(): iterable
    {
        yield 'fuso que não está na lista' => ['Asia/Tokyo', 'dmy', 'br'];
        yield 'fuso inventado' => ['Lua/Base', 'dmy', 'br'];
        yield 'formato de data' => ['UTC', 'd/m/Y', 'br'];
        yield 'formato de número' => ['UTC', 'dmy', 'fr'];
    }

    #[DataProvider('opcoesForaDaLista')]
    #[TestDox('opção fora da lista ($_dataName): recusa com a chave do catálogo, sem gravar nada')]
    public function testRecusaOpcaoForaDaLista(string $fuso, string $data, string $numero): void
    {
        [$useCase, $perfil, $usuario] = $this->montar(salva: false);

        try {
            $useCase->executar($usuario, 'pt-BR', $fuso, $data, $numero);
            self::fail('deveria recusar');
        } catch (OpcaoAvancadaInvalidaException $e) {
            self::assertSame('opcoes_avancadas.erro_opcao', $e->getMessage());
        }
        self::assertNull($perfil->getFusoHorario());
        self::assertNull($perfil->getFormatoData());
        self::assertNull($perfil->getFormatoNumero());
    }

    /** @return array{SalvarOpcoesAvancadasUseCase, UserProfile, User} */
    private function montar(bool $salva): array
    {
        $usuario = new User();
        $perfil = new UserProfile($usuario);
        $repositorio = $this->createMock(UserProfileRepository::class);
        $repositorio->method('buscarPorUsuario')->willReturn($perfil);
        $repositorio->expects($salva ? self::once() : self::never())->method('salvar')->with($perfil, true);

        return [new SalvarOpcoesAvancadasUseCase(new ObterOuCriarPerfilUseCase($repositorio), $repositorio), $perfil, $usuario];
    }
}
