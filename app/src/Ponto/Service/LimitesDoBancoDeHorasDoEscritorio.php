<?php

declare(strict_types=1);

namespace App\Ponto\Service;

use App\Entity\Tenant\Tenant;
use App\Ponto\Entity\ConfiguracaoDoPonto;
use App\Ponto\Repository\ConfiguracaoDoPontoRepository;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Contracts\Service\ResetInterface;

/**
 * O limite e o prazo do banco de horas salvos nas Configurações do Ponto de cada escritório (E7, P73). Sem configuração
 * salva, valem os padrões do desenho (`LimitesPadraoDoBancoDeHoras`: 120 horas e 6 meses).
 *
 * A faixa do desenho é conferida aqui também, na leitura (spec ponto-desenho-0811, P73: prazo de 1 a 24 meses; o
 * limite, uma quantidade de horas maior que zero). Valor fora dela, que só chega por gravação direta no banco, volta
 * ao padrão: um prazo zero venceria todo lote no próprio mês e um limite zero acenderia o aviso para todo mundo.
 *
 * O banco pede o limite e o prazo na mesma conta: a linha do escritório é lida uma vez por pedido (o teto de consultas
 * do `BancoDeHorasConsultasTest`). `reset()` esquece o que leu entre um pedido e outro (worker, `kernel.reset`).
 */
#[AsAlias(id: LimitesDoBancoDeHoras::class)]
final class LimitesDoBancoDeHorasDoEscritorio implements LimitesDoBancoDeHoras, ResetInterface
{
    public const PRAZO_MINIMO_MESES = 1;
    public const PRAZO_MAXIMO_MESES = 24;

    /** @var array<int, ?ConfiguracaoDoPonto> a configuração já lida, por id do escritório */
    private array $lidas = [];

    public function __construct(
        private readonly ConfiguracaoDoPontoRepository $configuracoes,
    ) {
    }

    public function limiteEmMinutos(Tenant $tenant): int
    {
        $horas = $this->configuracao($tenant)?->getBancoLimiteHoras();
        if ($horas === null || $horas < 1) {
            $horas = LimitesPadraoDoBancoDeHoras::LIMITE_EM_HORAS;
        }

        return $horas * 60;
    }

    public function prazoEmMeses(Tenant $tenant): int
    {
        $meses = $this->configuracao($tenant)?->getBancoPrazoMeses();
        if ($meses === null || $meses < self::PRAZO_MINIMO_MESES || $meses > self::PRAZO_MAXIMO_MESES) {
            return LimitesPadraoDoBancoDeHoras::PRAZO_EM_MESES;
        }

        return $meses;
    }

    public function reset(): void
    {
        $this->lidas = [];
    }

    private function configuracao(Tenant $tenant): ?ConfiguracaoDoPonto
    {
        $id = $tenant->getId();
        if ($id === null) {
            return $this->configuracoes->doTenant($tenant);
        }
        if (!array_key_exists($id, $this->lidas)) {
            $this->lidas[$id] = $this->configuracoes->doTenant($tenant);
        }

        return $this->lidas[$id];
    }
}
