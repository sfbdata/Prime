<?php

declare(strict_types=1);

namespace App\Conector\Ferramenta;

use App\Conector\DTO\ChamadaDoConector;
use App\Conector\DTO\ResultadoDaConfirmacao;

/**
 * O executor das propostas da `campanhas_de_email` (criar, editar, cancelar e enviar teste). Classe à parte porque a
 * ferramenta não pode implementar as duas interfaces (as duas declaram `TAG`); a regra mora toda na
 * {@see CampanhasDeEmailFerramenta}, e aqui só se repassa.
 */
final class CampanhasDeEmailExecutor implements ExecutorDeProposta
{
    public function __construct(private readonly CampanhasDeEmailFerramenta $ferramenta)
    {
    }

    public function ferramenta(): string
    {
        return CampanhasDeEmailFerramenta::NOME;
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
