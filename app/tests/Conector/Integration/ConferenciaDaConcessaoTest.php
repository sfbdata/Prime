<?php

declare(strict_types=1);

namespace App\Tests\Conector\Integration;

use App\Conector\Exception\AcessoDoConectorRecusado;
use App\Conector\Exception\ConcessaoRecusada;
use App\Conector\Service\ConferenciaDaConcessao;
use App\Tests\Conector\Support\CenarioDoServidorDeAutorizacaoTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

/**
 * A conferência da concessão que o `/oauth/token` usa e o `/mcp` (PR 5) vai reusar (spec mcp-remoto-oauth 4, regra 3,
 * e 9.2): cada item recusa sozinho, com o motivo próprio.
 */
#[CoversClass(ConferenciaDaConcessao::class)]
#[CoversClass(ConcessaoRecusada::class)]
final class ConferenciaDaConcessaoTest extends KernelTestCase
{
    use CenarioDoServidorDeAutorizacaoTrait;
    use ClockSensitiveTrait;

    protected function setUp(): void
    {
        self::bootKernel();
    }

    private function sut(): ConferenciaDaConcessao
    {
        return static::getContainer()->get(ConferenciaDaConcessao::class);
    }

    private function assertRecusa(string $motivo, callable $conferir): void
    {
        try {
            $conferir();
            self::fail('passou; esperado ' . $motivo);
        } catch (ConcessaoRecusada|AcessoDoConectorRecusado $recusa) {
            self::assertSame($motivo, $recusa->motivo);
        }
    }

    #[TestDox('Concessão viva, pessoa e escritório em ordem, liberado: passa')]
    public function testTudoEmOrdemPassa(): void
    {
        self::mockTime('2026-11-16 10:00:00');
        $conexao = $this->conexaoAutorizada();

        $this->sut()->exigir($conexao['concessao'], $this->agora());

        $this->addToAssertionCount(1);
    }

    #[TestDox('Corte de "Sair de todos os aparelhos" no mesmo segundo da autorização derruba, mesmo com fração na autorização em memória; corte um segundo antes não')]
    public function testCorteNoMesmoSegundoCai(): void
    {
        self::mockTime(new \DateTimeImmutable('2026-11-16 10:00:00.700000'));
        $conexao = $this->conexaoAutorizada();
        self::assertSame('700000', $conexao['concessao']->getAutorizadoEm()->format('u'), 'a autorização em memória tem fração');

        $conexao['pessoa']->encerrarSessoes(new \DateTimeImmutable('2026-11-16 09:59:59'));
        $this->sut()->exigir($conexao['concessao'], $this->agora());

        $conexao['pessoa']->encerrarSessoes(new \DateTimeImmutable('2026-11-16 10:00:00'));
        $this->assertRecusa(ConcessaoRecusada::MOTIVO_SESSOES_ENCERRADAS, fn () => $this->sut()->exigir($conexao['concessao'], $this->agora()));
    }

    #[TestDox('Senha trocada depois da autorização: senha_trocada')]
    public function testSenhaTrocadaCai(): void
    {
        $conexao = $this->conexaoAutorizada();
        $conexao['pessoa']->setPassword('outro-hash');

        $this->assertRecusa(ConcessaoRecusada::MOTIVO_SENHA_TROCADA, fn () => $this->sut()->exigir($conexao['concessao'], $this->agora()));
    }

    #[TestDox('Concessão revogada, vencida ou com o consentimento revogado: concessao_invalida; app desativado: cliente_inativo')]
    public function testConcessaoEClienteCaem(): void
    {
        self::mockTime('2026-11-16 10:00:00');
        $vencida = $this->conexaoAutorizada();
        $this->assertRecusa(ConcessaoRecusada::MOTIVO_CONCESSAO_INVALIDA, fn () => $this->sut()->exigir($vencida['concessao'], new \DateTimeImmutable('2026-11-23 10:00:00')));

        $revogado = $this->conexaoAutorizada();
        $revogado['consentimento']->revogar($this->agora());
        $this->assertRecusa(ConcessaoRecusada::MOTIVO_CONCESSAO_INVALIDA, fn () => $this->sut()->exigir($revogado['concessao'], $this->agora()));

        $inativo = $this->conexaoAutorizada();
        $inativo['cliente']->desativar();
        $this->assertRecusa(ConcessaoRecusada::MOTIVO_CLIENTE_INATIVO, fn () => $this->sut()->exigir($inativo['concessao'], $this->agora()));
    }

    #[TestDox('Pessoa desativada recusa pelo portão; escritório desligado recusa pela liberação')]
    public function testPortaoELiberacao(): void
    {
        $pessoa = $this->conexaoAutorizada();
        $pessoa['pessoa']->setIsActive(false);
        $this->em()->flush();
        $this->assertRecusa(AcessoDoConectorRecusado::MOTIVO_PESSOA, fn () => $this->sut()->exigir($pessoa['concessao'], $this->agora()));

        $liberacao = $this->conexaoAutorizada();
        $liberacao['liberacao']->bloquearPeloMaster();
        $this->em()->flush();
        $this->assertRecusa(AcessoDoConectorRecusado::MOTIVO_LIBERACAO, fn () => $this->sut()->exigir($liberacao['concessao'], $this->agora()));
    }

    #[TestDox('Na renovação a liberação fica de fora (suspender não derruba), mas o resto vale: pessoa desativada e concessão vencida recusam')]
    public function testNaRenovacaoSemLiberacao(): void
    {
        $suspenso = $this->conexaoAutorizada();
        $suspenso['liberacao']->bloquearPeloMaster();
        $suspenso['liberacao']->desligar();
        $this->em()->flush();
        $this->sut()->exigirNaRenovacao($suspenso['concessao'], $this->agora());

        $pessoa = $this->conexaoAutorizada();
        $pessoa['pessoa']->setIsActive(false);
        $this->em()->flush();
        $this->assertRecusa(AcessoDoConectorRecusado::MOTIVO_PESSOA, fn () => $this->sut()->exigirNaRenovacao($pessoa['concessao'], $this->agora()));

        $this->assertRecusa(ConcessaoRecusada::MOTIVO_CONCESSAO_INVALIDA, fn () => $this->sut()->exigirNaRenovacao($suspenso['concessao'], $this->agora()->modify('+7 days')));
    }
}
