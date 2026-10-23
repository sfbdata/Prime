<?php

declare(strict_types=1);

namespace App\Tests\Indicacao\Unit;

use App\Indicacao\Enum\CanalDeIndicacao;
use App\Indicacao\Exception\IndicacaoRecusada;
use App\Indicacao\UseCase\RegistrarIndicacaoPorCanalUseCase;
use App\Tests\Indicacao\Support\CenarioDeIndicacao;
use App\Tests\Indicacao\Support\Pessoas;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/** "WhatsApp" e "Copiar link curto" (spec indicar-bluejus.md, comportamentos 5 e 6). */
#[CoversClass(RegistrarIndicacaoPorCanalUseCase::class)]
final class RegistrarIndicacaoPorCanalUseCaseTest extends TestCase
{
    use CenarioDeIndicacao;

    protected function setUp(): void
    {
        $this->montarCenario();
    }

    private function useCase(): RegistrarIndicacaoPorCanalUseCase
    {
        return new RegistrarIndicacaoPorCanalUseCase($this->registro(), $this->relogio);
    }

    #[TestDox('WhatsApp e link: registram o canal (sem e-mail nem nome) com os textos do desenho')]
    public function testRegistraOsCanais(): void
    {
        $escritorio = Pessoas::escritorio(3);

        self::assertSame('Mensagem aberta no WhatsApp.', $this->useCase()->executar(CanalDeIndicacao::Whatsapp, Pessoas::usuario(7), $escritorio));
        self::assertSame('Link copiado.', $this->useCase()->executar(CanalDeIndicacao::Link, Pessoas::usuario(7), null));

        self::assertSame([CanalDeIndicacao::Whatsapp, CanalDeIndicacao::Link], array_map(static fn ($i) => $i->getCanal(), $this->gravadas));
        self::assertNull($this->gravadas[0]->getEmail());
        self::assertSame($escritorio, $this->gravadas[0]->getTenantOrigem());
        self::assertSame([7, 7], $this->travas);
    }

    #[TestDox('os canais contam nos limites: no limite do mês, recusa com o texto do desenho')]
    public function testContaNoLimite(): void
    {
        $this->em30d = 200;

        $this->expectException(IndicacaoRecusada::class);
        $this->expectExceptionMessage('Você chegou ao limite de 200 convites por mês.');

        $this->useCase()->executar(CanalDeIndicacao::Link, Pessoas::usuario(7), null);
    }

    #[TestDox('pausa global recusa também o WhatsApp')]
    public function testPausaGlobal(): void
    {
        $this->config->pausarParaTodos(true, Pessoas::master(), new \DateTimeImmutable());

        $this->expectExceptionMessage('Os convites estão pausados pela administração do BlueJus no momento.');

        $this->useCase()->executar(CanalDeIndicacao::Whatsapp, Pessoas::usuario(7), null);
    }

    #[TestDox('cadastro incompleto recusa e não grava')]
    public function testCadastroIncompleto(): void
    {
        try {
            $this->useCase()->executar(CanalDeIndicacao::Link, Pessoas::usuario(7, 'Ana Souza', null), null);
            self::fail('Esperava a recusa.');
        } catch (IndicacaoRecusada $e) {
            self::assertSame('Complete seu cadastro para enviar convites', $e->getMessage());
        }
        self::assertSame([], $this->gravadas);
    }

    #[TestDox('o canal e-mail não passa por aqui')]
    public function testEmailTemCaminhoProprio(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->useCase()->executar(CanalDeIndicacao::Email, Pessoas::usuario(7), null);
    }
}
