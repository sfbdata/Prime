<?php

declare(strict_types=1);

namespace App\ServiceDesk\UseCase;

use App\ServiceDesk\Entity\PrecoDoAtendimento;
use App\ServiceDesk\Enum\TipoDeChamado;
use App\ServiceDesk\Repository\PrecoDoAtendimentoRepository;

/**
 * O Master define (ou limpa, com null) o preço de um tipo de atendimento. Só vale para chamados abertos
 * depois: o valor estimado de cada chamado é gravado ao abrir. Quem chama garante que é Master.
 */
final class DefinirPrecoDoAtendimentoUseCase
{
    public function __construct(private readonly PrecoDoAtendimentoRepository $precos)
    {
    }

    public function executar(TipoDeChamado $tipo, ?int $valorCentavos): void
    {
        $atual = $this->precos->doTipo($tipo);
        if (null === $valorCentavos) {
            null !== $atual && $this->precos->remover($atual, flush: true);

            return;
        }
        if (null === $atual) {
            $this->precos->salvar(new PrecoDoAtendimento($tipo, $valorCentavos), flush: true);

            return;
        }
        $atual->alterarValor($valorCentavos);
        $this->precos->salvar($atual, flush: true);
    }
}
