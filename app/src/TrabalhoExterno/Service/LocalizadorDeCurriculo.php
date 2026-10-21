<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\Service;

use App\TrabalhoExterno\Entity\Curriculo;
use App\TrabalhoExterno\Exception\DemandaNaoEncontrada;
use App\TrabalhoExterno\Repository\CandidaturaRepository;
use App\TrabalhoExterno\Repository\CurriculoRepository;

/**
 * A porta única de "currículo por código", como o `LocalizadorDeVaga`: a Central alcança o banco
 * inteiro; o externo, só o dele. Qualquer outro caso é a mesma resposta de "não encontrado" (404),
 * para não confirmar que o currículo de outra pessoa existe.
 *
 * O dono externo de uma vaga também alcança o currículo de um candidato ENVIADO a ele por essa vaga
 * (spec §4.6, decisão 1 da §4.4), e só no recorte {@see ehDoDonoDaVaga()}: sem nota, sem situação no
 * banco e sem as outras candidaturas.
 */
final class LocalizadorDeCurriculo
{
    public function __construct(
        private readonly CurriculoRepository $repositorio,
        private readonly PoliticaDoCurriculo $politica,
        private readonly CandidaturaRepository $candidaturas,
        private readonly PoliticaDaVaga $vagas,
    ) {
    }

    public function localizar(Contexto $ctx, string $codigo): Curriculo
    {
        $id = Curriculo::idDoCodigo($codigo);
        $cv = match (true) {
            $id === null || !$this->politica->veAba($ctx) => null,
            $this->politica->ehInterno($ctx) => $this->repositorio->porId($id),
            default => $this->meuComId($ctx, $id) ?? $this->enviadoAMim($ctx, $id),
        };
        if ($cv === null || !($this->politica->podeVer($ctx, $cv) || $this->ehDoDonoDaVaga($ctx, $cv))) {
            throw new DemandaNaoEncontrada('Currículo não encontrado.');
        }

        return $cv;
    }

    /** O currículo do próprio usuário externo; null para o interno e para quem ainda não tem. */
    public function meu(Contexto $ctx): ?Curriculo
    {
        return $this->politica->temMeuCurriculo($ctx) ? $this->repositorio->doUsuario($ctx->usuario) : null;
    }

    /** @return list<Curriculo> o banco de talentos, só para a Central */
    public function visiveis(Contexto $ctx): array
    {
        return $this->politica->veAba($ctx) && $this->politica->ehInterno($ctx) ? $this->repositorio->recebidos() : [];
    }

    /**
     * O externo lê o currículo de outra pessoa: só porque ela foi enviada a uma vaga dele. Quem chama já
     * passou pelo {@see localizar()}.
     */
    public function ehDoDonoDaVaga(Contexto $ctx, Curriculo $cv): bool
    {
        if ($ctx->ehInterno() || $this->politica->ehDono($ctx, $cv) || !$this->politica->veAba($ctx)) {
            return false;
        }
        foreach ($this->candidaturas->vagasEnviadasDo($cv) as $vaga) {
            if ($this->vagas->ehDono($ctx, $vaga)) {
                return true;
            }
        }

        return false;
    }

    private function enviadoAMim(Contexto $ctx, int $id): ?Curriculo
    {
        $cv = $this->repositorio->porId($id);

        return $cv !== null && $this->ehDoDonoDaVaga($ctx, $cv) ? $cv : null;
    }

    private function meuComId(Contexto $ctx, int $id): ?Curriculo
    {
        $meu = $this->meu($ctx);

        return $meu !== null && $meu->getId() === $id ? $meu : null;
    }
}
