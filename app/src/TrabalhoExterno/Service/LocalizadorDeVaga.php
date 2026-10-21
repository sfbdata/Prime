<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\Service;

use App\TrabalhoExterno\Entity\Vaga;
use App\TrabalhoExterno\Enum\Perfil;
use App\TrabalhoExterno\Exception\DemandaNaoEncontrada;
use App\TrabalhoExterno\Repository\CandidaturaRepository;
use App\TrabalhoExterno\Repository\CurriculoRepository;
use App\TrabalhoExterno\Repository\VagaRepository;

/**
 * A porta única de "vaga por código", como o `LocalizadorDeDemanda`: consulta escopada pelo perfil
 * e, por cima, a política. Qualquer falha é a mesma resposta de "não encontrada" (404), para não
 * confirmar que a vaga de outro escritório existe.
 *
 * O externo que se candidatou abre a vaga por "Minhas candidaturas" mesmo depois de fechada (`abrir`,
 * desenho l.1154); a candidatura alcança também a vaga que já foi publicada e fechou, para responder
 * "inscrições encerradas" (422) em vez de 404.
 */
final class LocalizadorDeVaga
{
    public function __construct(
        private readonly VagaRepository $repositorio,
        private readonly PoliticaDaVaga $politica,
        private readonly CurriculoRepository $curriculos,
        private readonly CandidaturaRepository $candidaturas,
    ) {
    }

    public function localizar(Contexto $ctx, string $codigo, \DateTimeImmutable $agora): Vaga
    {
        $id = Vaga::idDoCodigo($codigo);
        $vaga = $id !== null ? $this->noEscopo($ctx, $id) : null;
        if ($vaga !== null && $this->politica->podeVer($ctx, $vaga, $agora)) {
            return $vaga;
        }

        return ($id !== null ? $this->daMinhaCandidatura($ctx, $id) : null) ?? throw new DemandaNaoEncontrada('Vaga não encontrada.');
    }

    /** Para "Candidatar-se" e "Aceitar oferta": também a vaga publicada que já fechou (a regra recusa depois). */
    public function localizarParaCandidatura(Contexto $ctx, string $codigo, \DateTimeImmutable $agora): Vaga
    {
        try {
            return $this->localizar($ctx, $codigo, $agora);
        } catch (DemandaNaoEncontrada $e) {
            $id = Vaga::idDoCodigo($codigo);
            $publicada = $id !== null && $this->politica->veAba($ctx) && !$this->politica->ehInterno($ctx) ? $this->repositorio->publicadaPorId($id) : null;

            return $publicada ?? throw $e;
        }
    }

    private function noEscopo(Contexto $ctx, int $id): ?Vaga
    {
        return $this->politica->ehInterno($ctx)
            ? $this->repositorio->daCentralPorId($id)
            : $this->repositorio->visivelAoExternoPorId($id, $ctx->perfil === Perfil::Convidado ? null : $ctx->tenant, $ctx->usuario);
    }

    private function daMinhaCandidatura(Contexto $ctx, int $id): ?Vaga
    {
        if (!$this->politica->veAba($ctx) || $this->politica->ehInterno($ctx)) {
            return null;
        }
        $cv = $this->curriculos->doUsuario($ctx->usuario);

        return $cv !== null && $this->candidaturas->existeDoCurriculoNaVaga($cv, $id) ? $this->repositorio->daCentralPorId($id) : null;
    }

    /** @return list<Vaga> */
    public function visiveis(Contexto $ctx, \DateTimeImmutable $agora): array
    {
        if (!$this->politica->veAba($ctx)) {
            return [];
        }
        $vagas = $this->politica->ehInterno($ctx)
            ? $this->repositorio->daCentral()
            : $this->repositorio->visiveisAoExterno($ctx->perfil === Perfil::Convidado ? null : $ctx->tenant, $ctx->usuario);

        return array_values(array_filter($vagas, fn (Vaga $v): bool => $this->politica->podeVer($ctx, $v, $agora)));
    }
}
