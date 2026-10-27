<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\UseCase;

use App\TrabalhoExterno\DTO\DadosDaSolicitacao;
use App\TrabalhoExterno\DTO\DadosDaVaga;
use App\TrabalhoExterno\Entity\Candidatura;
use App\TrabalhoExterno\Entity\Curriculo;
use App\TrabalhoExterno\Enum\TipoItemMarcado;
use App\TrabalhoExterno\Repository\CandidaturaRepository;
use App\TrabalhoExterno\Repository\MarcacaoDoUsuarioRepository;
use App\TrabalhoExterno\Repository\TermoDeVoluntariadoRepository;
use App\TrabalhoExterno\Service\ApresentadorDeCandidatura;
use App\TrabalhoExterno\Service\ApresentadorDeCurriculo;
use App\TrabalhoExterno\Service\Contexto;
use App\TrabalhoExterno\Service\DetalheDoCurriculo;
use App\TrabalhoExterno\Service\LocalizadorDeCurriculo;
use App\TrabalhoExterno\Service\PoliticaDoCurriculo;

/**
 * A aba "Currículos" (interno) ou "Meu currículo" (externo) de uma vez (spec VS 18.10.26 §4.5): o
 * banco de talentos para a Central, menos os que cada um excluiu da própria lista; o currículo do
 * próprio usuário para o externo, com "Minhas candidaturas" (`minhas`, l.221 a 224 e 1154: as que ele
 * não excluiu da lista, da mais recente para a mais antiga). Consulta: não grava nada.
 */
final class ListarCurriculosUseCase
{
    public function __construct(
        private readonly LocalizadorDeCurriculo $localizador,
        private readonly PoliticaDoCurriculo $politica,
        private readonly ApresentadorDeCurriculo $apresentador,
        private readonly MarcacaoDoUsuarioRepository $marcacoes,
        private readonly CandidaturaRepository $candidaturas,
        private readonly ApresentadorDeCandidatura $apresentadorDeCandidatura,
        private readonly DetalheDoCurriculo $detalhe,
        private readonly TermoDeVoluntariadoRepository $termos,
    ) {
    }

    /** @return array<string, mixed> */
    public function executar(Contexto $ctx): array
    {
        $agora = new \DateTimeImmutable();
        $meu = $this->localizador->meu($ctx);

        return [
            'interno' => $this->politica->ehInterno($ctx),
            'podeGerir' => $this->politica->podeGerir($ctx),
            'ehMaster' => $this->politica->ehMaster($ctx),
            'termoLigado' => $ctx->configuracao->termoLigado(),
            'meu' => $meu !== null ? $this->detalhe->detalhar($ctx, $meu, comMarca: false) : null,
            'minhas' => $meu !== null ? $this->minhas($ctx, $meu, $agora) : [],
            'curriculos' => $this->cartoes($ctx, $agora),
            'agora' => $agora->format(\DATE_ATOM),
            'listas' => [
                'areas' => DadosDaVaga::AREAS,
                'cargos' => DadosDaVaga::CARGOS,
                'modalidades' => DadosDaVaga::MODALIDADES,
                'ufs' => DadosDaSolicitacao::UFS,
            ],
        ];
    }

    /** @return list<array<string, mixed>> */
    private function cartoes(Contexto $ctx, \DateTimeImmutable $agora): array
    {
        $curriculos = $this->localizador->visiveis($ctx);
        $ids = array_map(static fn (Curriculo $c): int => (int) $c->getId(), $curriculos);
        $marcas = $ctx->previa ? [] : $this->marcacoes->doUsuario($ctx->usuario, TipoItemMarcado::Curriculo, $ids);
        $contagem = $this->candidaturas->contarPorCurriculo($ids);
        $comTermo = $ctx->configuracao->termoLigado();
        $termos = $comTermo ? $this->termos->maisRecentesDe($ids) : [];
        $cartoes = [];
        foreach ($curriculos as $cv) {
            $marca = $marcas[(int) $cv->getId()] ?? null;
            if ($marca?->excluida() !== true) {
                $cartoes[] = $this->apresentador->cartao($cv, $marca, $agora, $contagem[(int) $cv->getId()] ?? 0, $termos[(int) $cv->getId()] ?? null, $comTermo);
            }
        }

        return $cartoes;
    }

    /** @return list<array<string, mixed>> */
    private function minhas(Contexto $ctx, Curriculo $meu, \DateTimeImmutable $agora): array
    {
        $candidaturas = $this->candidaturas->doCurriculo($meu);
        $marcas = $ctx->previa ? [] : $this->marcacoes->doUsuario($ctx->usuario, TipoItemMarcado::Candidatura, array_map(static fn (Candidatura $c): int => (int) $c->getId(), $candidaturas));
        $linhas = [];
        foreach ($candidaturas as $c) {
            $marca = $marcas[(int) $c->getId()] ?? null;
            if ($marca?->excluida() !== true) {
                $linhas[] = $this->apresentadorDeCandidatura->daMinhaLista($c, $marca, $agora);
            }
        }

        return $linhas;
    }
}
