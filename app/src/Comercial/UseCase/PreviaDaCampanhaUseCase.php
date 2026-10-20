<?php

declare(strict_types=1);

namespace App\Comercial\UseCase;

use App\Comercial\Campanha\ComposicaoDaCampanha;
use App\Comercial\DTO\MensagemDaCampanha;

/**
 * A prévia do e-mail (dc L.253, "a prévia atualiza na hora"; spec §8.3): o HTML completo, do mesmo montador do envio,
 * com o nome e o e-mail de exemplo do dc. Nada é enviado nem gravado. Serve também ao "Baixar HTML" (dc L.558).
 */
final class PreviaDaCampanhaUseCase
{
    public function __construct(private readonly ComposicaoDaCampanha $composicao)
    {
    }

    public function executar(MensagemDaCampanha $mensagem): string
    {
        return $this->composicao->previa($mensagem);
    }
}
