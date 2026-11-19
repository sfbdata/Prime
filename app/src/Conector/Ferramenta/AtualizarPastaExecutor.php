<?php

declare(strict_types=1);

namespace App\Conector\Ferramenta;

use App\Conector\DTO\ChamadaDoConector;
use App\Conector\DTO\ResultadoDaConfirmacao;

/**
 * O executor das propostas da `atualizar_pasta` (excluir pasta, marcador ou réu; tirar marcador da pasta; trocar dado,
 * nome do cliente ou cadastro já preenchido; editar marcador). Classe à parte porque a ferramenta não pode implementar
 * as duas interfaces (as duas declaram `TAG`); a regra mora toda na {@see AtualizarPastaFerramenta} e nos grupos dela, e
 * aqui só se repassa.
 */
final class AtualizarPastaExecutor implements ExecutorDeProposta
{
    public function __construct(private readonly AtualizarPastaFerramenta $ferramenta)
    {
    }

    public function ferramenta(): string
    {
        return AtualizarPastaFerramenta::NOME;
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
