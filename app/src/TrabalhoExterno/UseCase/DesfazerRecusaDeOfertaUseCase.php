<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\UseCase;

use App\TrabalhoExterno\Exception\AcaoNaoPermitida;
use App\TrabalhoExterno\Repository\RecusaDeOfertaRepository;
use App\TrabalhoExterno\Service\Contexto;
use App\TrabalhoExterno\Service\LocalizadorDeVaga;

/**
 * "Você recusou · aceitar mesmo assim" (D11b, l.1169): apaga a recusa de quem lê; a tela candidata logo depois
 * (`candidatar`), como no desenho. Sem recusa, nada muda. A Central (Master e Equipe) não recusa nem desfaz.
 */
final class DesfazerRecusaDeOfertaUseCase
{
    public function __construct(
        private readonly LocalizadorDeVaga $localizador,
        private readonly RecusaDeOfertaRepository $recusas,
    ) {
    }

    public function executar(Contexto $ctx, string $codigo): void
    {
        if ($ctx->somenteLeitura()) {
            throw new AcaoNaoPermitida('Na pré-visualização não é possível alterar nada.');
        }
        if ($ctx->ehInterno()) {
            throw new AcaoNaoPermitida('Só quem recebeu a oferta pode recusá-la.');
        }
        $vaga = $this->localizador->localizar($ctx, $codigo, new \DateTimeImmutable());
        $recusa = $this->recusas->daVagaEDoUsuario($vaga, $ctx->usuario);
        if ($recusa !== null) {
            $this->recusas->remover($recusa, flush: true);
        }
    }
}
