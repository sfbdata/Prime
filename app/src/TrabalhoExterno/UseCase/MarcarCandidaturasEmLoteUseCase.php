<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\UseCase;

use App\TrabalhoExterno\Entity\MarcacaoDoUsuario;
use App\TrabalhoExterno\Enum\OperacaoDeMarcacao;
use App\TrabalhoExterno\Enum\TipoItemMarcado;
use App\TrabalhoExterno\Exception\AcaoNaoPermitida;
use App\TrabalhoExterno\Repository\CandidaturaRepository;
use App\TrabalhoExterno\Repository\MarcacaoDoUsuarioRepository;
use App\TrabalhoExterno\Service\Contexto;
use App\TrabalhoExterno\Service\LocalizadorDeCurriculo;

/**
 * Lote, estrela, importância e "visto" em "Minhas candidaturas" (`mcBulk`, desenho 06 l.221 e 1154),
 * como nas vagas: só as candidaturas do currículo de quem marca; id de outra pessoa é ignorado em
 * silêncio. Abrir uma candidatura a marca como vista (`lida`). Na prévia do Master fica negado.
 */
final class MarcarCandidaturasEmLoteUseCase
{
    public function __construct(
        private readonly LocalizadorDeCurriculo $curriculos,
        private readonly CandidaturaRepository $candidaturas,
        private readonly MarcacaoDoUsuarioRepository $marcacoes,
    ) {
    }

    /**
     * @param list<int> $pedidos ids das candidaturas
     *
     * @return array{aplicados: int, texto: string}
     */
    public function executar(Contexto $ctx, array $pedidos, OperacaoDeMarcacao $op): array
    {
        if ($ctx->previa) {
            throw new AcaoNaoPermitida('Na pré-visualização não é possível alterar nada.');
        }
        $meu = $this->curriculos->meu($ctx);
        $minhas = $meu !== null ? array_map(static fn ($c): int => (int) $c->getId(), $this->candidaturas->doCurriculo($meu)) : [];
        $ids = array_values(array_intersect($minhas, $pedidos));

        $existentes = $this->marcacoes->doUsuario($ctx->usuario, TipoItemMarcado::Candidatura, $ids);
        $agora = new \DateTimeImmutable();
        $marcas = [];
        foreach ($ids as $id) {
            $marcas[] = $marca = $existentes[$id] ?? new MarcacaoDoUsuario($ctx->usuario, TipoItemMarcado::Candidatura, $id);
            $marca->aplicar($op, $agora);
        }
        $this->marcacoes->salvarTodas($marcas);

        return ['aplicados' => count($ids), 'texto' => count($ids) . ' item(ns)' . $op->complementoDoItem()];
    }
}
