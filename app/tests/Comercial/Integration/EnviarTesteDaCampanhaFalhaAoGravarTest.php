<?php

declare(strict_types=1);

namespace App\Tests\Comercial\Integration;

use App\Comercial\Campanha\ComposicaoDaCampanha;
use App\Comercial\Campanha\ModelosDeCampanha;
use App\Comercial\DTO\MensagemDaCampanha;
use App\Comercial\Envio\CarteiroInterface;
use App\Comercial\Envio\ResultadoDoEnvio;
use App\Comercial\Exception\CampanhaRecusada;
use App\Comercial\Repository\CampanhaDeEmailRepository;
use App\Comercial\Repository\DestinoDaCampanhaRepository;
use App\Comercial\Repository\EnvioDeEmailRepository;
use App\Comercial\UseCase\EnviarTesteDaCampanhaUseCase;
use App\Entity\Auth\User;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;

/**
 * F3f: o Carteiro fecha a própria transação antes da gravação do teste. Se a gravação falhar, o e-mail já saiu: a tela
 * recebe uma recusa que diz isso (não um 500), para o Master não reenviar achando que nada aconteceu.
 */
#[CoversClass(EnviarTesteDaCampanhaUseCase::class)]
final class EnviarTesteDaCampanhaFalhaAoGravarTest extends KernelTestCase
{
    #[TestDox('Enviar teste: e-mail enviado e gravação que falha viram a recusa "não entrou no Relatório"')]
    public function testFalhaAoGravarDepoisDoEnvio(): void
    {
        self::bootKernel();
        $carteiro = $this->createMock(CarteiroInterface::class);
        $carteiro->expects(self::once())->method('enviar')->willReturn(new ResultadoDoEnvio(7, ResultadoDoEnvio::ENFILEIRADO));
        $envios = $this->createStub(EnvioDeEmailRepository::class);
        $envios->method('contarDaOrigemParaDesde')->willReturn(0);
        $campanhas = $this->createStub(CampanhaDeEmailRepository::class);
        $campanhas->method('emTransacao')->willThrowException(new \RuntimeException('banco fora'));
        $master = (new User())->setEmail('master@bluejus.test')->setFullName('Master');

        $caso = new EnviarTesteDaCampanhaUseCase(
            static::getContainer()->get(ComposicaoDaCampanha::class),
            $carteiro,
            $envios,
            new MockClock(),
            $campanhas,
            $this->createStub(DestinoDaCampanhaRepository::class),
            static::getContainer()->get(\App\Comercial\Campanha\ImagensDaCampanha::class),
        );

        $this->expectException(CampanhaRecusada::class);
        $this->expectExceptionMessage(EnviarTesteDaCampanhaUseCase::NAO_REGISTRADO);
        $caso->executar(MensagemDaCampanha::deArray((array) (new ModelosDeCampanha())->fixo('convite')), $master);
    }
}
