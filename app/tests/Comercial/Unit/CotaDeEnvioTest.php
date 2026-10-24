<?php

declare(strict_types=1);

namespace App\Tests\Comercial\Unit;

use App\Comercial\DTO\SituacaoDaCota;
use App\Comercial\Envio\CotaDeEnvio;
use App\Comercial\Repository\DestinoDaCampanhaRepository;
use App\Comercial\Repository\EnvioDeEmailRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/**
 * As regras da cota de envio (spec comercial-e-emails.md §3.6 e §8.5) sobre um registro em memória: janela corrida de
 * 24 h, teto total, teto das campanhas com a reserva dos demais, isentas, por minuto, a volta da vaga e o aviso.
 * Teto 10, campanhas 6 (reserva 4), 100 por minuto, aviso em 80%.
 */
#[CoversClass(CotaDeEnvio::class)]
#[CoversClass(SituacaoDaCota::class)]
final class CotaDeEnvioTest extends TestCase
{
    private const AGORA = '2030-01-15 10:00:00';

    /** @var list<array{origem: string, situacao: string, criado: string, enviado: ?string}> */
    private array $linhas = [];
    private bool $haPendentes = false;

    #[TestDox('Janela corrida, não calendário: o que saiu ontem às 11:00 ainda conta às 10:00 de hoje; o de ontem às 09:59, não')]
    public function testJanelaCorrida(): void
    {
        $this->enviados(10, '2030-01-14 11:00:00', 'sistema');
        self::assertNotNull($this->cota()->disponivelEm('consumidor:push', new \DateTimeImmutable(self::AGORA)));

        $this->linhas = [];
        $this->enviados(10, '2030-01-14 09:59:00', 'sistema');
        self::assertNull($this->cota()->disponivelEm('consumidor:push', new \DateTimeImmutable(self::AGORA)));
    }

    #[TestDox('Sem vaga, a volta é o enviado mais antigo da janela + 24 h + 60 s')]
    public function testLiberaEmPeloMaisAntigo(): void
    {
        $this->enviados(1, '2030-01-14 12:30:00', 'sistema');
        $this->enviados(9, '2030-01-15 08:00:00', 'sistema');

        self::assertSame('2030-01-15 12:31:00', $this->cota()->disponivelEm('consumidor:push', new \DateTimeImmutable(self::AGORA))?->format('Y-m-d H:i:s'));
    }

    #[TestDox('Isenta passa acima do teto total (só respeita o por minuto), e conta no total')]
    public function testIsentaPassaAcimaDoTeto(): void
    {
        $this->enviados(12, '2030-01-15 08:00:00', 'automacao:val');

        self::assertNull($this->cota()->disponivelEm('automacao:cad', new \DateTimeImmutable(self::AGORA)));
        self::assertNotNull($this->cota()->disponivelEm('consumidor:push', new \DateTimeImmutable(self::AGORA)));
        self::assertSame(12, $this->cota()->situacao()->enviados24h);
    }

    #[TestDox('Por minuto vale para todos, inclusive as isentas: +60 s')]
    public function testPorMinuto(): void
    {
        $this->enviados(3, '2030-01-15 09:59:30', 'sistema');

        self::assertSame('2030-01-15 10:01:00', $this->cota(porMinuto: 3)->disponivelEm('automacao:cad', new \DateTimeImmutable(self::AGORA))?->format('Y-m-d H:i:s'));
    }

    #[TestDox('Campanha no handler: para no próprio teto (6) e volta pelo envio de campanha mais antigo')]
    public function testCampanhaNoProprioTeto(): void
    {
        $this->enviados(1, '2030-01-14 15:00:00', 'campanha:1');
        $this->enviados(5, '2030-01-15 07:00:00', 'campanha:1');
        $this->enviados(1, '2030-01-14 13:00:00', 'sistema');

        self::assertSame('2030-01-15 15:01:00', $this->cota()->disponivelEm('campanha:1', new \DateTimeImmutable(self::AGORA))?->format('Y-m-d H:i:s'));
        self::assertNull($this->cota()->disponivelEm('consumidor:push', new \DateTimeImmutable(self::AGORA)), 'os demais ainda têm a reserva');
    }

    #[TestDox('Transacionais acima da reserva encolhem a campanha: com 6 demais, a campanha para em 4 (10 - 6)')]
    public function testDemaisAcimaDaReservaEncolhemACampanha(): void
    {
        $this->enviados(6, '2030-01-15 06:00:00', 'sistema');
        $this->enviados(3, '2030-01-15 07:00:00', 'campanha:1');
        $cota = $this->cota();
        $agora = new \DateTimeImmutable(self::AGORA);

        self::assertNull($cota->disponivelEm('campanha:1', $agora), '3 + 6 < 10');
        self::assertSame(1, $cota->vagasDeCampanha($agora));

        $this->enviados(1, '2030-01-15 08:00:00', 'campanha:1');
        self::assertSame('2030-01-16 06:01:00', $cota->disponivelEm('campanha:1', $agora)?->format('Y-m-d H:i:s'), '4 + 6 = 10: volta pelo mais antigo geral');
        self::assertSame(0, $cota->vagasDeCampanha($agora));
    }

    #[TestDox('A campanha nunca toma a reserva: sem demais, o expansor libera no máximo o teto das campanhas (6)')]
    public function testCampanhaNaoTomaAReserva(): void
    {
        self::assertSame(6, $this->cota()->vagasDeCampanha(new \DateTimeImmutable(self::AGORA)));

        $this->registrados(4, '2030-01-15 09:00:00');
        self::assertSame(2, $this->cota()->vagasDeCampanha(new \DateTimeImmutable(self::AGORA)), 'os enfileirados já reservam a vaga');
    }

    #[TestDox('Aviso: perto em 80% do teto; pausadas com campanha pendente e sem vaga, volta pelo registro mais antigo')]
    public function testSituacao(): void
    {
        $this->enviados(7, '2030-01-15 08:00:00', 'sistema');
        self::assertFalse($this->cota()->situacao()->perto);

        $this->enviados(1, '2030-01-15 08:00:00', 'sistema');
        $situacao = $this->cota()->situacao();
        self::assertTrue($situacao->perto);
        self::assertFalse($situacao->campanhasPausadas);
        self::assertSame('perto:2030-01-15', $situacao->chaveDoEpisodio(new \DateTimeImmutable(self::AGORA)));

        $this->registrados(2, '2030-01-15 09:00:00');
        $this->haPendentes = true;
        $situacao = $this->cota()->situacao();
        self::assertTrue($situacao->campanhasPausadas);
        self::assertSame('2030-01-16 08:01:00', $situacao->liberaEm?->format('Y-m-d H:i:s'), '8 demais passam da reserva: volta pelo enviado geral mais antigo');
        self::assertSame('pausadas:2030-01-16', $situacao->chaveDoEpisodio(new \DateTimeImmutable(self::AGORA)));
    }

    #[TestDox('Sem campanha esperando, vaga zero não é pausa')]
    public function testSemPendenteNaoPausa(): void
    {
        $this->enviados(6, '2030-01-15 08:00:00', 'campanha:1');

        $situacao = $this->cota()->situacao();
        self::assertFalse($situacao->campanhasPausadas);
        self::assertNull($situacao->liberaEm);
        self::assertNull($situacao->chaveDoEpisodio(new \DateTimeImmutable(self::AGORA)));
    }

    private function cota(int $porMinuto = 100): CotaDeEnvio
    {
        $envios = $this->createStub(EnvioDeEmailRepository::class);
        $envios->method('contarEnviadosDesde')->willReturnCallback(fn (\DateTimeImmutable $d): int => \count($this->enviadosDesde($d, false)));
        $envios->method('contarEnviadosDeCampanhaDesde')->willReturnCallback(fn (\DateTimeImmutable $d): int => \count($this->enviadosDesde($d, true)));
        $envios->method('enviadoMaisAntigoDesde')->willReturnCallback(function (\DateTimeImmutable $d, bool $soCampanha): ?\DateTimeImmutable {
            $datas = array_map(static fn (array $l): string => (string) $l['enviado'], $this->enviadosDesde($d, $soCampanha));

            return $datas === [] ? null : new \DateTimeImmutable(min($datas));
        });
        $envios->method('contarDeCampanhaDesde')->willReturnCallback(fn (\DateTimeImmutable $d): int => \count($this->registradosDesde($d)));
        $envios->method('registradoDeCampanhaMaisAntigoDesde')->willReturnCallback(function (\DateTimeImmutable $d): ?\DateTimeImmutable {
            $datas = array_map(static fn (array $l): string => $l['criado'], $this->registradosDesde($d));

            return $datas === [] ? null : new \DateTimeImmutable(min($datas));
        });
        $envios->method('contarDeCampanhaNaFila')->willReturnCallback(fn (): int => \count(array_filter($this->linhas, static fn (array $l): bool => $l['situacao'] === 'enfileirado')));
        $destinos = $this->createStub(DestinoDaCampanhaRepository::class);
        $destinos->method('haPendentes')->willReturnCallback(fn (): bool => $this->haPendentes);

        return new CotaDeEnvio($envios, $destinos, new MockClock(new \DateTimeImmutable(self::AGORA)), 10, 6, $porMinuto, 80);
    }

    private function enviados(int $quantos, string $em, string $origem): void
    {
        for ($i = 0; $i < $quantos; ++$i) {
            $this->linhas[] = ['origem' => $origem, 'situacao' => 'enviado', 'criado' => $em, 'enviado' => $em];
        }
    }

    private function registrados(int $quantos, string $em): void
    {
        for ($i = 0; $i < $quantos; ++$i) {
            $this->linhas[] = ['origem' => 'campanha:1', 'situacao' => 'enfileirado', 'criado' => $em, 'enviado' => null];
        }
    }

    /** @return list<array{origem: string, situacao: string, criado: string, enviado: ?string}> */
    private function enviadosDesde(\DateTimeImmutable $desde, bool $soCampanha): array
    {
        return array_values(array_filter($this->linhas, static fn (array $l): bool => $l['situacao'] === 'enviado'
            && $l['enviado'] >= $desde->format('Y-m-d H:i:s') && (!$soCampanha || str_starts_with($l['origem'], 'campanha:'))));
    }

    /** @return list<array{origem: string, situacao: string, criado: string, enviado: ?string}> */
    private function registradosDesde(\DateTimeImmutable $desde): array
    {
        return array_values(array_filter($this->linhas, static fn (array $l): bool => str_starts_with($l['origem'], 'campanha:')
            && $l['criado'] >= $desde->format('Y-m-d H:i:s')));
    }
}
