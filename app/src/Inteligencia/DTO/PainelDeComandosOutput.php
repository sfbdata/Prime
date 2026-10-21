<?php

declare(strict_types=1);

namespace App\Inteligencia\DTO;

use App\Inteligencia\Enum\Comando;

/**
 * O painel do drawer (abas Análise e Relatórios): cada comando e cada relatório com as suas
 * análises nesta pasta. Todo comando tem entrada (sem análise, uma saída vazia) para o template
 * percorrer `Comando::comandos()`/`relatorios()` sem `??`.
 */
final readonly class PainelDeComandosOutput
{
    /**
     * @param array<string, AnalisesDoComandoOutput> $porComando chave = `Comando->value`
     */
    public function __construct(
        public array $porComando,
    ) {
    }

    public function de(Comando $comando): AnalisesDoComandoOutput
    {
        return $this->porComando[$comando->value] ?? AnalisesDoComandoOutput::vazio($comando);
    }

    public function temEmAndamento(): bool
    {
        foreach ($this->porComando as $saida) {
            if ($saida->temEmAndamento()) {
                return true;
            }
        }

        return false;
    }

    public function totalDeAnalises(): int
    {
        $total = 0;
        foreach ($this->porComando as $saida) {
            $total += $saida->total();
        }

        return $total;
    }
}
