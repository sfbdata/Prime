<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\UseCase;

use App\TrabalhoExterno\Entity\LinkDeVagas;
use App\TrabalhoExterno\Exception\AcaoNaoPermitida;
use App\TrabalhoExterno\Exception\DadosInvalidos;
use App\TrabalhoExterno\Repository\LinkDeVagasRepository;
use App\TrabalhoExterno\Service\Contexto;
use App\TrabalhoExterno\Service\LocalizadorDeVaga;
use App\TrabalhoExterno\Service\PoliticaDaVaga;

/**
 * "Divulgar vaga(s)" (`abrirShare`, desenho 06 l.1076): gera o link público com as vagas ABERTAS
 * escolhidas. Quem: qualquer um que vê a vaga aberta (o menu do externo também tem "Compartilhar
 * vaga", l.1001). Código que o usuário não vê, ou vaga que não está aberta, fica de fora; sem nenhuma,
 * 422 com o texto do desenho. "Indicadas por" leva o primeiro nome de quem gerou (`linkDe`, l.1074).
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
            if (isset($pedidos[$v->getCodigo()]) && $this->politica->podeCompartilhar($ctx, $v, $agora)) {
                $ids[] = (int) $v->getId();
            }
        }
        if ($ids === []) {
            throw new DadosInvalidos('Só vagas abertas podem ser divulgadas.');
        }

        $nome = explode(' ', trim($ctx->autor()->nome))[0];
        [$link, $token] = LinkDeVagas::gerar($ids, $ctx->usuario, $nome);
        $this->links->salvar($link, flush: true);

        return ['token' => $token, 'vagas' => count($ids)];
    }
}
