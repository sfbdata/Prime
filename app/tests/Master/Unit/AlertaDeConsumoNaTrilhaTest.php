<?php

declare(strict_types=1);

namespace App\Tests\Master\Unit;

use App\Contribua\DTO\RetratoDoEspaco;
use App\Contribua\Entity\ConfiguracaoDoEspaco;
use App\Contribua\Enum\FaixaDoEspaco;
use App\Contribua\Enum\OrigemDoLimite;
use App\Contribua\Event\EspacoAtingiuFaixa;
use App\Contribua\Repository\ConfiguracaoDoEspacoRepository;
use App\Master\EventListener\AlertaDeConsumoNaTrilha;
use App\Master\Repository\PainelMasterRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * "Alerta de consumo" na trilha (spec perfis-1.2-planos-e-limites P11): grava com o início da faixa
 * alcançada e, se a gravação falhar, só registra no log (o envio de arquivo nunca cai por causa da trilha).
 */
#[CoversClass(AlertaDeConsumoNaTrilha::class)]
final class AlertaDeConsumoNaTrilhaTest extends TestCase
{
    #[TestDox('grava a conta do evento, o limiar da faixa (Alerta = 90) e o percentual')]
    public function testGrava(): void
    {
        $repositorio = $this->createMock(PainelMasterRepository::class);
        $repositorio->expects($this->once())->method('registrarAlertaDeConsumo')->with(7, 90, 92, 'alerta');

        $this->ouvinte($repositorio, $this->createMock(LoggerInterface::class))($this->evento());
    }

    #[TestDox('a gravação quebrando: o ouvinte não lança e registra o erro no log')]
    public function testFalhaNaGravacaoNaoDerrubaOEnvio(): void
    {
        $repositorio = $this->createMock(PainelMasterRepository::class);
        $repositorio->method('registrarAlertaDeConsumo')->willThrowException(new \RuntimeException('banco fora'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error')->with(self::stringContains('Alerta de consumo'), self::callback(static fn (array $c): bool => $c['tenant'] === 7 && $c['erro'] === 'banco fora'));

        $this->ouvinte($repositorio, $logger)($this->evento());
    }

    private function ouvinte(PainelMasterRepository $repositorio, LoggerInterface $logger): AlertaDeConsumoNaTrilha
    {
        $configuracoes = $this->createMock(ConfiguracaoDoEspacoRepository::class);
        $configuracoes->method('atual')->willReturn(new ConfiguracaoDoEspaco());

        return new AlertaDeConsumoNaTrilha($repositorio, $configuracoes, $logger);
    }

    private function evento(): EspacoAtingiuFaixa
    {
        $retrato = new RetratoDoEspaco(7, 0, 10 * RetratoDoEspaco::BYTES_POR_GB, 92, FaixaDoEspaco::Alerta, OrigemDoLimite::Plano, 'free', 'Free', 3, OrigemDoLimite::Plano, false);

        return new EspacoAtingiuFaixa(7, FaixaDoEspaco::Alerta, FaixaDoEspaco::Atencao, $retrato, 85);
    }
}
