<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\UseCase;

use App\TrabalhoExterno\Entity\LinkDeVagas;
use App\TrabalhoExterno\Enum\SituacaoVaga;
use App\TrabalhoExterno\Exception\AcaoNaoPermitida;
use App\TrabalhoExterno\Exception\DadosInvalidos;
use App\TrabalhoExterno\Repository\LinkDeVagasRepository;
use App\TrabalhoExterno\Service\Contexto;
use App\TrabalhoExterno\Service\LocalizadorDeVaga;
use App\TrabalhoExterno\Service\PoliticaDaVaga;

/**
 * "Compartilhar vagas" e "Compartilhar esta vaga" (`tmShare`, spec VS 18.10.26 §4.2): gera o link
 * público com as vagas ABERTAS escolhidas. Quem: a Central, e o escritório nas vagas dele.
 * Código que o usuário não vê, ou vaga que não está aberta, fica de fora; sem nenhuma, 422.
 */
final class CompartilharVagasUseCase
{
    public function __construct(
        private readonly LocalizadorDeVaga $localizador,
        private readonly PoliticaDaVaga $politica,
        private readonly LinkDeVagasRepository $links,
    ) {
    }

    /**
     * @param list<string> $codigos
     *
     * @return array{token: string, vagas: int}
     */
    public function executar(Contexto $ctx, array $codigos): array
    {
        if ($ctx->previa) {
            throw new AcaoNaoPermitida('Na pré-visualização não é possível alterar nada.');
        }
        $agora = new \DateTimeImmutable();
        $pedidos = array_flip($codigos);
        $ids = [];
        foreach ($this->localizador->visiveis($ctx, $agora) as $v) {
            if (isset($pedidos[$v->getCodigo()])
                && $v->situacaoEm($agora) === SituacaoVaga::Aberta
                && ($this->politica->ehDaCentral($ctx) || $this->politica->ehDono($ctx, $v))) {
                $ids[] = (int) $v->getId();
            }
        }
        if ($ids === []) {
            throw new DadosInvalidos('Escolha ao menos uma vaga aberta para compartilhar.');
        }

        $nome = $this->politica->ehDaCentral($ctx) ? 'Central BlueJus' : (string) $ctx->tenant?->getName();
        [$link, $token] = LinkDeVagas::gerar($ids, $ctx->usuario, $nome);
        $this->links->salvar($link, flush: true);

        return ['token' => $token, 'vagas' => count($ids)];
    }
}
