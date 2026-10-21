<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\UseCase;

use App\TrabalhoExterno\DTO\ResultadoDaCandidatura;
use App\TrabalhoExterno\Entity\Candidatura;
use App\TrabalhoExterno\Entity\Curriculo;
use App\TrabalhoExterno\Entity\Vaga;
use App\TrabalhoExterno\Exception\AcaoNaoPermitida;
use App\TrabalhoExterno\Exception\CandidaturaRecusada;
use App\TrabalhoExterno\Exception\SemCurriculo;
use App\TrabalhoExterno\Repository\CandidaturaRepository;
use App\TrabalhoExterno\Repository\CurriculoRepository;
use App\TrabalhoExterno\Repository\VagaRepository;
use App\TrabalhoExterno\Service\AvisosDoTrabalhoExterno;
use App\TrabalhoExterno\Service\Contexto;
use App\TrabalhoExterno\Service\LocalizadorDeVaga;
use App\TrabalhoExterno\Service\PoliticaDaVaga;

/**
 * "Candidatar-se" e "Aceitar oferta" (spec VS 18.10.26 §4.6, desenho 06 l.974 e 978): o externo com
 * currículo se candidata; na oferta com tempo, o servidor decide que é aceite.
 *
 * Fluxo: localiza a vaga (404; a publicada que já fechou também, para responder 422); recusa o
 * interno e a vaga própria (422); sem currículo, 409 `semCurriculo`; depois, numa transação com a
 * linha da vaga travada (`SELECT … FOR UPDATE`), confere a repetida, a situação, o prazo e, no aceite,
 * os aceites da rodada atual contra a quantidade. A recusa sai DEPOIS da transação (que só lê), para
 * não fechar o EntityManager. A candidatura guarda o escritório de quem se candidatou (o do contexto),
 * onde ele recebe os avisos de andamento; a Central (ou o dono, no aceite) é avisada (spec §4.7).
 */
final class CandidatarOuAceitarUseCase
{
    public function __construct(
        private readonly LocalizadorDeVaga $localizador,
        private readonly PoliticaDaVaga $politica,
        private readonly CurriculoRepository $curriculos,
        private readonly CandidaturaRepository $candidaturas,
        private readonly VagaRepository $vagas,
        private readonly AvisosDoTrabalhoExterno $avisos,
    ) {
    }

    public function executar(Contexto $ctx, string $codigo): ResultadoDaCandidatura
    {
        $agora = new \DateTimeImmutable();
        $vaga = $this->localizador->localizarParaCandidatura($ctx, $codigo, $agora);
        $this->exigirExternoDeFora($ctx, $vaga);
        $cv = $this->curriculos->doUsuario($ctx->usuario) ?? throw new SemCurriculo('Cadastre seu currículo para se candidatar.');

        $resultado = $this->vagas->comAVagaTravada($vaga, function () use ($ctx, $vaga, $cv, $agora): ResultadoDaCandidatura|CandidaturaRecusada {
            return $this->repetida($vaga, $cv) ?? ($vaga->isOferta() ? $this->aceitar($ctx, $vaga, $cv, $agora) : $this->candidatar($ctx, $vaga, $cv, $agora));
        });

        return $resultado instanceof CandidaturaRecusada ? throw $resultado : $resultado;
    }

    private function exigirExternoDeFora(Contexto $ctx, Vaga $vaga): void
    {
        if ($ctx->previa) {
            throw new AcaoNaoPermitida('Na pré-visualização não é possível alterar nada.');
        }
        if ($this->politica->ehInterno($ctx)) {
            throw new CandidaturaRecusada('Candidatura não permitida', 'Só profissionais externos se candidatam às vagas.');
        }
        if ($this->politica->ehDono($ctx, $vaga)) {
            throw new CandidaturaRecusada('Candidatura não permitida', 'Você não pode se candidatar à sua própria vaga.');
        }
    }

    /** Já existe uma candidatura deste currículo: nada muda (l.974; "Você já aceitou", l.978). */
    private function repetida(Vaga $vaga, Curriculo $cv): ?ResultadoDaCandidatura
    {
        $existente = $this->candidaturas->daVagaEDoCurriculo($vaga, $cv);
        if ($existente === null) {
            return null;
        }

        return $existente->isAceiteDeOferta()
            ? new ResultadoDaCandidatura(false, true, 'Você já aceitou', 'Sua vaga está garantida.', $vaga->getTitulo())
            : new ResultadoDaCandidatura(false, false, 'Candidatura já enviada', 'Você já se candidatou a esta vaga.', $vaga->getTitulo());
    }

    private function candidatar(Contexto $ctx, Vaga $vaga, Curriculo $cv, \DateTimeImmutable $agora): ResultadoDaCandidatura|CandidaturaRecusada
    {
        if (!$vaga->aceitaInscricao($agora)) {
            return new CandidaturaRecusada('Inscrições encerradas', 'As inscrições desta vaga estão encerradas.');
        }
        $candidatura = Candidatura::candidatar($vaga, $cv, $agora, $ctx->tenant);
        $this->candidaturas->salvar($candidatura);
        $this->avisos->candidatura($ctx, $candidatura);
        $vaga->registrarCandidatura($cv->texto('nome'), $agora);

        return new ResultadoDaCandidatura(true, false, 'Candidatura enviada', 'Acompanhe em Meu currículo · Minhas candidaturas.', $vaga->getTitulo());
    }

    /** O aceite conta só a rodada atual ("Ofertar novamente" e "Reabrir" recomeçam do zero). */
    private function aceitar(Contexto $ctx, Vaga $vaga, Curriculo $cv, \DateTimeImmutable $agora): ResultadoDaCandidatura|CandidaturaRecusada
    {
        $aceites = count($this->candidaturas->aceitesDaRodada($vaga));
        if (!$vaga->aceitaInscricao($agora) || $aceites >= $vaga->getQtd()) {
            return new CandidaturaRecusada('Vaga indisponível', 'Esta vaga já foi preenchida ou encerrada.', $ctx->configuracao->getVagas()['motiv']);
        }
        $candidatura = Candidatura::aceitarOferta($vaga, $cv, $agora, $ctx->tenant);
        $this->candidaturas->salvar($candidatura);
        $this->avisos->candidatura($ctx, $candidatura);
        $vaga->registrarAceite($cv->texto('nome'), $aceites, $agora);

        return new ResultadoDaCandidatura(true, true, 'Oferta aceita', 'A vaga está reservada para você. O escritório já foi avisado.', $vaga->getTitulo());
    }
}
