<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\UseCase;

use App\TrabalhoExterno\Entity\RecusaDeOferta;
use App\TrabalhoExterno\Entity\Vaga;
use App\TrabalhoExterno\Enum\SituacaoVaga;
use App\TrabalhoExterno\Exception\AcaoNaoPermitida;
use App\TrabalhoExterno\Exception\TransicaoInvalida;
use App\TrabalhoExterno\Repository\CandidaturaRepository;
use App\TrabalhoExterno\Repository\CurriculoRepository;
use App\TrabalhoExterno\Repository\RecusaDeOfertaRepository;
use App\TrabalhoExterno\Repository\VagaRepository;
use App\TrabalhoExterno\Service\AvisosDoTrabalhoExterno;
use App\TrabalhoExterno\Service\Contexto;
use App\TrabalhoExterno\Service\LocalizadorDeVaga;
use App\TrabalhoExterno\Service\PoliticaDaVaga;

/**
 * "Recusar" a oferta com tempo (D11b, desenho 06 de 24/10, `recusar`, l.1169): o externo que vê a oferta aberta,
 * não é o dono e ainda não se candidatou. Grava a recusa (uma por vaga e usuário), a linha "Oferta recusada por
 * {quem}" no histórico e avisa o dono da vaga e os Master da Central (P7). Recusar de novo não muda nada nem
 * avisa outra vez. "Ofertar novamente" não apaga a recusa.
 */
final class RecusarOfertaUseCase
{
    public function __construct(
        private readonly LocalizadorDeVaga $localizador,
        private readonly PoliticaDaVaga $politica,
        private readonly VagaRepository $vagas,
        private readonly RecusaDeOfertaRepository $recusas,
        private readonly CurriculoRepository $curriculos,
        private readonly CandidaturaRepository $candidaturas,
        private readonly AvisosDoTrabalhoExterno $avisos,
    ) {
    }

    /** @return bool se gravou agora (false: já tinha recusado) */
    public function executar(Contexto $ctx, string $codigo): bool
    {
        $agora = new \DateTimeImmutable();
        $vaga = $this->localizador->localizar($ctx, $codigo, $agora);
        $this->exigirQuemRecusa($ctx, $vaga, $agora);

        $nova = $this->vagas->comAVagaTravada($vaga, function () use ($ctx, $vaga, $agora): bool {
            if ($this->recusas->daVagaEDoUsuario($vaga, $ctx->usuario) !== null) {
                return false;
            }
            $quem = $ctx->autor()->nome;
            $this->recusas->salvar(new RecusaDeOferta($vaga, $ctx->usuario, $agora));
            $vaga->registrarRecusa($quem, $agora);
            $this->avisos->ofertaRecusada($ctx, $vaga, $quem);
            $this->vagas->salvar($vaga, flush: true);

            return true;
        });

        return $nova;
    }

    private function exigirQuemRecusa(Contexto $ctx, Vaga $vaga, \DateTimeImmutable $agora): void
    {
        if ($ctx->somenteLeitura()) {
            throw new AcaoNaoPermitida('Na pré-visualização não é possível alterar nada.');
        }
        if ($this->politica->ehInterno($ctx) || $this->politica->ehDono($ctx, $vaga)) {
            throw new AcaoNaoPermitida('Só quem recebeu a oferta pode recusá-la.');
        }
        if (!$vaga->isOferta() || $vaga->situacaoEm($agora) !== SituacaoVaga::Aberta) {
            throw new TransicaoInvalida('Esta oferta não está mais disponível.');
        }
        $cv = $this->curriculos->doUsuario($ctx->usuario);
        if ($cv !== null && $this->candidaturas->daVagaEDoCurriculo($vaga, $cv) !== null) {
            throw new TransicaoInvalida('Você já aceitou esta oferta.');
        }
    }
}
