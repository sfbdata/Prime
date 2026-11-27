<?php

declare(strict_types=1);

namespace App\Comercial\UseCase;

use App\Comercial\Campanha\ComposicaoDaCampanha;
use App\Comercial\Campanha\ImagensDaCampanha;
use App\Comercial\Campanha\MotivoDoEnvioDaCampanha;
use App\Comercial\DTO\DestinatarioDaCampanha;
use App\Comercial\DTO\MensagemDaCampanha;

/**
 * A prévia do e-mail (dc L.253, "a prévia atualiza na hora"; spec §8.3): o HTML completo, do mesmo montador do envio,
 * com o nome e o e-mail do primeiro destinatário do público escolhido (a tela o recebe da contagem dos públicos) ou,
 * sem ele, os de exemplo do dc (L.537). Nada é enviado nem gravado. Serve também ao "Baixar HTML" (dc L.558).
 * A imagem do modo simples (F3i) tem de existir e não ter vencido.
 *
 * C1: com o público escolhido (a tela o devolve junto do exemplo da contagem), o rodapé leva a frase da origem, a mesma
 * do envio; nos Contatos salvos, a da origem do contato do exemplo. Sem público, a frase de lista de antes.
 */
final class PreviaDaCampanhaUseCase
{
    public function __construct(
        private readonly ComposicaoDaCampanha $composicao,
        private readonly ImagensDaCampanha $imagens,
        private readonly MotivoDoEnvioDaCampanha $motivos,
    ) {
    }

    public function executar(MensagemDaCampanha $mensagem, ?DestinatarioDaCampanha $exemplo = null, string $nomeDaPrevia = '', string $publico = ''): string
    {
        $this->imagens->exigirVigente($mensagem);
        $motivo = match (true) {
            $publico === '' => null,
            $exemplo !== null => $this->motivos->doDestinatario($publico, $exemplo->lista, $exemplo->email),
            default => $this->motivos->frase($publico),
        };

        return $this->composicao->previa($mensagem, $exemplo, $nomeDaPrevia, $motivo);
    }
}
