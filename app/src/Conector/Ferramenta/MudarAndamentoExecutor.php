<?php

declare(strict_types=1);

namespace App\Conector\Ferramenta;

use App\Conector\DTO\ChamadaDoConector;
use App\Conector\DTO\ResultadoDaConfirmacao;

/**
 * O executor das propostas da `mudar_andamento` (voltar fase, reativar finalizada, trocar ou remover o nome de uma
 * etapa). Classe à parte porque a ferramenta não pode implementar as duas interfaces (as duas declaram `TAG`); a regra
 * mora toda na {@see MudarAndamentoFerramenta}, e aqui só se repassa.
 */
final class MudarAndamentoExecutor implements ExecutorDeProposta
{
    public function __construct(private readonly MudarAndamentoFerramenta $ferramenta)
    {
    }

    public function ferramenta(): string
    {
        return MudarAndamentoFerramenta::NOME;
    }

    public function reconferir(string $acao, array $dados, ChamadaDoConector $chamada): void
    {
        $this->ferramenta->reconferir($acao, $dados, $chamada);
    }

    public function executarConfirmada(string $acao, array $dados, ChamadaDoConector $chamada): ResultadoDaConfirmacao
    {
        return $this->ferramenta->executarConfirmada($acao, $dados, $chamada);
    }
}
