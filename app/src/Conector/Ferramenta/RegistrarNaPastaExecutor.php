<?php

declare(strict_types=1);

namespace App\Conector\Ferramenta;

use App\Conector\DTO\ChamadaDoConector;
use App\Conector\DTO\ResultadoDaConfirmacao;

/**
 * O {@see ExecutorDeProposta} da `registrar_na_pasta` (editar e excluir um registro). Só repassa: a ferramenta não pode
 * implementar as duas interfaces (as duas declaram a constante `TAG`), e a regra mora nela
 * ({@see RegistrarNaPastaFerramenta}).
 */
final class RegistrarNaPastaExecutor implements ExecutorDeProposta
{
    public function __construct(private readonly RegistrarNaPastaFerramenta $ferramenta)
    {
    }

    public function ferramenta(): string
    {
        return RegistrarNaPastaFerramenta::NOME;
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
