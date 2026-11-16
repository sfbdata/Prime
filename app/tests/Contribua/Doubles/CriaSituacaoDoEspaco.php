<?php

declare(strict_types=1);

namespace App\Tests\Contribua\Doubles;

use App\Contribua\DTO\RetratoDoEspaco;
use App\Contribua\DTO\Solicitante;
use App\Contribua\Entity\ConfiguracaoDoEspaco;
use App\Contribua\Entity\EspacoDoEscritorio;
use App\Contribua\Entity\Plano;
use App\Contribua\Repository\BonusDeEspacoRepository;
use App\Contribua\Repository\ConfiguracaoDoEspacoRepository;
use App\Contribua\Repository\ConsumoDeArmazenamentoRepository;
use App\Contribua\Repository\EspacoDoEscritorioRepository;
use App\Contribua\Repository\PlanoRepository;
use App\Contribua\Service\SituacaoDoEspaco;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use Symfony\Component\Clock\MockClock;

/**
 * Monta, para os testes unitários, o `SituacaoDoEspaco` de um escritório (id 7) com o uso e o espaço dados, no plano
 * Free de 10 GB, sem bônus. Só para classes que estendem `TestCase`.
 */
trait CriaSituacaoDoEspaco
{
    private function escritorioDeTeste(int $id = 7): Tenant
    {
        $escritorio = (new Tenant())->setName('Escritório ' . $id);
        (new \ReflectionProperty(Tenant::class, 'id'))->setValue($escritorio, $id);

        return $escritorio;
    }

    private function planosDeTeste(): PlanoRepository
    {
        $free = new Plano('free', 'Free', 10, 0, 'Free', 1);
        $essencial = new Plano('essencial', 'Essencial', 20, 1990, 'Essencial', 2);
        $planos = $this->createStub(PlanoRepository::class);
        $planos->method('free')->willReturn($free);
        $planos->method('findOneBy')->willReturnCallback(static fn (array $c): ?Plano => ['free' => $free, 'essencial' => $essencial][$c['codigo']] ?? null);

        return $planos;
    }

    private function situacaoDeTeste(int $bytesUsados, ?EspacoDoEscritorio $espaco = null): SituacaoDoEspaco
    {
        $consumo = $this->createStub(ConsumoDeArmazenamentoRepository::class);
        $consumo->method('bytesDoEscritorio')->willReturn($bytesUsados);
        $configuracoes = $this->createStub(ConfiguracaoDoEspacoRepository::class);
        $configuracoes->method('atual')->willReturn(new ConfiguracaoDoEspaco());
        $espacos = $this->createStub(EspacoDoEscritorioRepository::class);
        $espacos->method('doTenant')->willReturn($espaco);

        return new SituacaoDoEspaco($consumo, $espacos, $this->planosDeTeste(), $configuracoes, $this->createStub(BonusDeEspacoRepository::class), new MockClock());
    }

    private function gb(float $gb): int
    {
        return (int) round($gb * RetratoDoEspaco::BYTES_POR_GB);
    }

    private function quemDeTeste(bool $primario, bool $master = false, int $tenantId = 7): Solicitante
    {
        $usuario = new User();
        (new \ReflectionProperty(User::class, 'id'))->setValue($usuario, 55);
        $usuario->setEmail('quem@teste.com');

        return new Solicitante($usuario, $this->escritorioDeTeste($tenantId), $master, $primario);
    }
}
