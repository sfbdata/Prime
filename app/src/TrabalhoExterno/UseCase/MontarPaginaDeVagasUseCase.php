<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\UseCase;

use App\TrabalhoExterno\DTO\DadosDaSolicitacao;
use App\TrabalhoExterno\DTO\DadosDaVaga;
use App\TrabalhoExterno\Entity\Vaga;
use App\TrabalhoExterno\Enum\SituacaoVaga;
use App\TrabalhoExterno\Repository\LinkDeVagasRepository;
use App\TrabalhoExterno\Repository\VagaRepository;

/**
 * A página pública de vagas (`Vagas Publicas.dc.html`, spec VS 18.10.26 §4.2), sem login. Só sai
 * o que o desenho diz que a página mostra: cargo, título, local, modalidade, contratação, áreas e
 * quantidade. Remuneração, escritório, contato e requisitos NÃO saem daqui. Token desconhecido é a
 * página sem vagas, igual à de um link sem nenhuma aberta, para não confirmar que o link existiu.
 */
final class MontarPaginaDeVagasUseCase
{
    public function __construct(
        private readonly LinkDeVagasRepository $links,
        private readonly VagaRepository $vagas,
    ) {
    }

    /** @return array<string, mixed> */
    public function executar(?string $token): array
    {
        $agora = new \DateTimeImmutable();
        $link = $token !== null ? $this->links->doToken($token) : null;
        $abertas = $link !== null ? $this->ordenar($this->vagas->abertasEntre($link->getVagas()), $link->getVagas()) : [];
        $abertas = array_values(array_filter($abertas, static fn (Vaga $v): bool => $v->situacaoEm($agora) === SituacaoVaga::Aberta));

        $publicas = array_map(self::publica(...), $abertas);

        return [
            'comLink' => $token !== null,
            'indicadoPor' => $link?->getIndicadoPor() ?? '',
            'geradoEm' => $link?->getCriadoEm()->format('d/m/Y') ?? '',
            'vagas' => $publicas,
            // Os números do topo e o texto do selo (`renderVals` do desenho).
            'total' => array_sum(array_column($publicas, 'qtd')),
            'cargos' => array_values(array_unique(array_filter(array_column($publicas, 'cargo')))),
            'ufsDasVagas' => array_values(array_unique(array_filter(array_column($publicas, 'uf')))),
            // As áreas das vagas do link já vêm marcadas no formulário de alertas (`componentDidMount`).
            'areasSugeridas' => array_slice(array_values(array_unique(array_merge([], ...array_column($publicas, 'areas')))), 0, 3),
            'areas' => DadosDaVaga::AREAS,
            'ufs' => DadosDaSolicitacao::UFS,
        ];
    }

    /** @return array{titulo: string, cargo: string, qtd: int, local: string, contratacao: string, areas: list<string>, uf: string} */
    private static function publica(Vaga $v): array
    {
        $cidadeUf = implode('/', array_filter([$v->getCidade(), $v->getUf()]));

        return [
            'titulo' => $v->getTitulo(),
            'cargo' => $v->getCargo(),
            'qtd' => $v->isOferta() ? $v->getQtd() : 1,
            'local' => implode(' · ', array_filter([$v->getModalidade(), $cidadeUf !== '' ? $cidadeUf : 'Brasil'])),
            'contratacao' => $v->getContratacao() !== '' ? $v->getContratacao() : 'Contratação a combinar',
            'areas' => $v->getAreas(),
            'uf' => $v->getUf(),
        ];
    }

    /**
     * Na ordem em que foram escolhidas no compartilhamento.
     *
     * @param list<Vaga> $vagas
     * @param list<int>  $ordem
     *
     * @return list<Vaga>
     */
    private function ordenar(array $vagas, array $ordem): array
    {
        $posicao = array_flip($ordem);
        usort($vagas, static fn (Vaga $a, Vaga $b): int => ($posicao[(int) $a->getId()] ?? 0) <=> ($posicao[(int) $b->getId()] ?? 0));

        return $vagas;
    }
}
