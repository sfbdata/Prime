<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\UseCase;

use App\TrabalhoExterno\Entity\DespachoDemanda;
use App\TrabalhoExterno\Exception\AcaoNaoPermitida;
use App\TrabalhoExterno\Exception\DadosInvalidos;
use App\TrabalhoExterno\Repository\DemandaExternaRepository;
use App\TrabalhoExterno\Service\AvisosDoTrabalhoExterno;
use App\TrabalhoExterno\Service\Contexto;
use App\TrabalhoExterno\Service\LocalizadorDeDemanda;
use App\TrabalhoExterno\Service\PoliticaDaDemanda;

/**
 * "Responder" ao despacho (desenho 06 de 24/10, `dpResponder`, l.1392): quem pediu e o profissional, com
 * "Aceitar resposta" ligado e ao menos um despacho. Texto de 2 a 3.000 caracteres; o chat recebe
 * "Resposta ao despacho de {quem}.".
 */
final class ResponderAoDespachoUseCase
{
    public function __construct(
        private readonly LocalizadorDeDemanda $localizador,
        private readonly PoliticaDaDemanda $politica,
        private readonly DemandaExternaRepository $repositorio,
        private readonly AvisosDoTrabalhoExterno $avisos,
    ) {
    }

    public function executar(Contexto $ctx, string $codigo, string $texto): DespachoDemanda
    {
        [$demanda, $ctx] = $this->localizador->localizar($ctx, $codigo);
        if (!$this->politica->podeResponderAoDespacho($ctx, $demanda)) {
            throw new AcaoNaoPermitida('As respostas ao despacho não estão liberadas nesta demanda.');
        }
        if (mb_strlen(trim($texto)) < 2) {
            throw new DadosInvalidos('Escreva a resposta ao despacho.');
        }

        $resposta = $demanda->responderAoDespacho($ctx->autor(), $texto);
        $resposta->usarCodigo($this->repositorio->codigoDeDespachoLivre($resposta->getCodigo()));
        $this->avisos->andamentoDaDemanda($ctx, $demanda);
        $this->avisos->respostaAoDespacho($ctx, $demanda, $resposta);
        $this->repositorio->salvar($demanda, flush: true);

        return $resposta;
    }
}
