<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\UseCase;

use App\TrabalhoExterno\DTO\DadosDaSolicitacao;
use App\TrabalhoExterno\DTO\DadosDaVaga;
use App\TrabalhoExterno\Entity\Curriculo;
use App\TrabalhoExterno\Enum\TipoItemMarcado;
use App\TrabalhoExterno\Repository\MarcacaoDoUsuarioRepository;
use App\TrabalhoExterno\Service\ApresentadorDeCurriculo;
use App\TrabalhoExterno\Service\Contexto;
use App\TrabalhoExterno\Service\LocalizadorDeCurriculo;
use App\TrabalhoExterno\Service\PoliticaDoCurriculo;

/**
 * A aba "Currículos" (interno) ou "Meu currículo" (externo) de uma vez (spec VS 18.10.26 §4.5): o
 * banco de talentos para a Central, menos os que cada um excluiu da própria lista; o currículo do
 * próprio usuário para o externo. Consulta: não grava nada.
 */
final class ListarCurriculosUseCase
{
    public function __construct(
        private readonly LocalizadorDeCurriculo $localizador,
        private readonly PoliticaDoCurriculo $politica,
        private readonly ApresentadorDeCurriculo $apresentador,
        private readonly MarcacaoDoUsuarioRepository $marcacoes,
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
            'meu' => $meu !== null ? $this->apresentador->detalhe($ctx, $meu, null, $agora) : null,
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
        $marcas = $ctx->previa ? [] : $this->marcacoes->doUsuario($ctx->usuario, TipoItemMarcado::Curriculo, array_map(static fn (Curriculo $c): int => (int) $c->getId(), $curriculos));
        $cartoes = [];
        foreach ($curriculos as $cv) {
            $marca = $marcas[(int) $cv->getId()] ?? null;
            if ($marca?->excluida() !== true) {
                $cartoes[] = $this->apresentador->cartao($cv, $marca, $agora);
            }
        }

        return $cartoes;
    }
}
