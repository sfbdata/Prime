<?php

declare(strict_types=1);

namespace App\Master\UseCase;

use App\Master\Entity\SeccionalOab;
use App\Master\Repository\NotaDaCidadeOabRepository;
use App\Master\Repository\NotaDaSeccionalRepository;
use App\Master\Repository\SeccionalOabRepository;
use App\Master\Repository\SubsecaoOabRepository;

/**
 * As 27 seccionais da OAB para o bloco "Seccionais visíveis" do mapa, o modo OAB da tabela de UFs, a janela da OAB e
 * o marcador no iframe (specs docs/specs/master-seccionais-oab.md e master-oab-estados-que-faltam.md; desenho 05,
 * Perfis 1.2, VS 21.10.26, L116-127, L481-495 e L875-905).
 *
 * Quem dispara: o Master, ao abrir a Visão geral do painel (GET /master/dados/seccionais, ROLE_SUPER_ADMIN),
 * e o iframe do mapa do Brasil, depois de registrar uma divulgação.
 * Objetivo: ver, para cada seccional, a sede (endereço, telefone, site), quantas inscrições de usuários do BlueJus
 * há nela, se aparece no mapa, a marca da divulgação (status, cor e data), as subseções por cidade e as notas do
 * estado e das cidades, para a janela da OAB abrir sem outra ida ao servidor.
 * Pré-condições: a carga da migration gravou as 27 linhas (só UF e nome).
 * Fluxo principal: lê as seccionais em ordem de UF, a contagem de inscrições por UF, as subseções e as notas (uma
 * consulta de cada, nenhuma por seccional) e monta uma linha por seccional.
 * Fluxos alternativos: seccional sem inscrição conta 0; sem marca, `marca` é nula; sem subseção, a lista vem vazia;
 * sem nota do estado, `nota` é nula; sem nota de cidade, `notasCidades` vem vazio.
 * Pós-condições: nenhuma (só leitura).
 * Regras: "inscrições" conta cada inscrição (principal e suplementar) de quem tem vínculo ativo
 * ({@see SeccionalOabRepository::inscricoesPorUf()}); é o mesmo número da linha da tabela de UFs no modo OAB.
 */
final class ListarSeccionaisUseCase
{
    public function __construct(
        private readonly SeccionalOabRepository $seccionais,
        private readonly SubsecaoOabRepository $subsecoes,
        private readonly NotaDaSeccionalRepository $notas,
        private readonly NotaDaCidadeOabRepository $notasDasCidades,
    ) {
    }

    /**
     * @return list<array{uf: string, nome: string, endereco: ?string, telefone: ?string, site: ?string, inscricoes: int,
     *     visivel: bool, marca: ?array{status: string, rotulo: string, cor: string, data: ?string},
     *     subsecoes: list<array{id: int, cidade: ?string, nome: string, endereco: string, telefone: ?string}>,
     *     nota: ?array{texto: string, atualizadaEm: string},
     *     notasCidades: array<string, array{texto: string, atualizadaEm: string}>}>
     */
    public function executar(): array
    {
        $inscricoes = $this->seccionais->inscricoesPorUf();
        $subsecoes = $this->subsecoes->todasPorUf();
        $notas = $this->notas->todasPorUf();
        $notasDasCidades = $this->notasDasCidades->todasPorUf();

        return array_map(
            fn (SeccionalOab $s): array => self::linha($s, $inscricoes[$s->getUf()] ?? 0) + [
                'subsecoes'    => $subsecoes[$s->getUf()] ?? [],
                'nota'         => $notas[$s->getUf()] ?? null,
                'notasCidades' => $notasDasCidades[$s->getUf()] ?? [],
            ],
            $this->seccionais->todasEmOrdem(),
        );
    }

    /** @return array<string, mixed> */
    private static function linha(SeccionalOab $s, int $inscricoes): array
    {
        return [
            'uf'         => $s->getUf(),
            'nome'       => $s->getNome(),
            'endereco'   => $s->getEndereco(),
            'telefone'   => $s->getTelefone(),
            'site'       => $s->getSite(),
            'inscricoes' => $inscricoes,
            'visivel'    => $s->isVisivel(),
            'marca'      => self::marca($s),
        ];
    }

    /**
     * A marca só existe com status e cor (o ponto do chip aparece só assim, desenho L902).
     *
     * @return ?array{status: string, rotulo: string, cor: string, data: ?string}
     */
    private static function marca(SeccionalOab $s): ?array
    {
        $status = $s->getMarcaStatus();
        $cor = $s->getMarcaCor();
        if ($status === null || $cor === null) {
            return null;
        }

        return ['status' => $status->value, 'rotulo' => $status->rotulo(), 'cor' => $cor, 'data' => $s->getMarcaEm()?->format('Y-m-d')];
    }
}
