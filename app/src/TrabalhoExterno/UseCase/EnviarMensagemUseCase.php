<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\UseCase;

use App\TrabalhoExterno\Entity\DemandaExterna;
use App\TrabalhoExterno\Enum\TipoMensagem;
use App\TrabalhoExterno\Exception\AcaoNaoPermitida;
use App\TrabalhoExterno\Exception\DadosInvalidos;
use App\TrabalhoExterno\Repository\DemandaExternaRepository;
use App\TrabalhoExterno\Service\Contexto;
use App\TrabalhoExterno\Service\LocalizadorDeDemanda;
use App\TrabalhoExterno\Service\PoliticaDaDemanda;

/**
 * Mensagem no chat da demanda, ou nota interna ("só o Master e a equipe veem").
 *
 * Quem: quem vê a demanda e pode conversar (a Equipe só com "Responder no chat"); nota interna só
 * Master e Equipe. A própria mensagem nunca conta como não lida (a contagem ignora o autor), então
 * não há leitura a atualizar aqui.
 */
final class EnviarMensagemUseCase
{
    public const MAX_CARACTERES = 5000;

    public function __construct(
        private readonly LocalizadorDeDemanda $localizador,
        private readonly PoliticaDaDemanda $politica,
        private readonly DemandaExternaRepository $repositorio,
    ) {
    }

    public function executar(Contexto $ctx, string $codigo, string $texto, bool $notaInterna): DemandaExterna
    {
        $texto = trim($texto);
        if ($texto === '') {
            throw new DadosInvalidos('Escreva a mensagem.');
        }
        if (mb_strlen($texto) > self::MAX_CARACTERES) {
            throw new DadosInvalidos(sprintf('A mensagem passa de %d caracteres.', self::MAX_CARACTERES));
        }

        [$demanda, $ctx] = $this->localizador->localizar($ctx, $codigo);
        if (!$this->politica->podeConversar($ctx, $demanda)) {
            throw new AcaoNaoPermitida('Você não pode conversar nesta demanda.');
        }
        if ($notaInterna && !$this->politica->podeEscreverNotaInterna($ctx, $demanda)) {
            throw new AcaoNaoPermitida('Nota interna é só para o Master e a equipe.');
        }

        $demanda->registrar($ctx->autor(), $texto, $notaInterna ? TipoMensagem::Interna : TipoMensagem::Normal);
        $this->repositorio->salvar($demanda, flush: true);

        return $demanda;
    }
}
