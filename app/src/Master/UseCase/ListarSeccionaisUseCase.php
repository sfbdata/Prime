<?php

declare(strict_types=1);

namespace App\Master\UseCase;

use App\Master\Entity\SeccionalOab;
use App\Master\Enum\TipoDeDivulgacao;
use App\Master\Repository\DivulgacaoNaSeccionalRepository;
use App\Master\Repository\NotaDaSeccionalRepository;
use App\Master\Repository\SeccionalOabRepository;
use App\Master\Repository\SubsecaoOabRepository;

/**
 * As 27 seccionais da OAB para o bloco "Seccionais visíveis" do mapa e para o marcador no iframe
 * (spec docs/specs/master-seccionais-oab.md; desenho 05, Perfis 1.2, VS 21.10.26, L116-127).
 *
 * Quem dispara: o Master, ao abrir a Visão geral do painel (GET /master/dados/seccionais, ROLE_SUPER_ADMIN),
 * e o iframe do mapa do Brasil, depois de registrar uma divulgação.
 * Objetivo: ver, para cada seccional, a sede (endereço, telefone, site), quantas pessoas do BlueJus estão
 * inscritas nela e o que já foi feito ali (a última divulgação dá a cor do ponto do chip). A janela da OAB dos
 * estados que faltam (spec master-oab-estados-que-faltam) lê daqui também as subseções e a nota, para abrir sem
 * outra ida ao servidor.
 * Pré-condições: a carga da migration gravou as 27 linhas (só UF e nome).
 * Fluxo principal: lê as seccionais em ordem de UF, a contagem de inscritos por UF, as 5 últimas
 * divulgações, as subseções e as notas (uma consulta de cada, nenhuma por seccional), e monta uma linha por seccional.
 * Fluxos alternativos: seccional sem inscrito conta 0; sem divulgação, `ultima` é nula e a lista vem vazia;
 * sem subseção, a lista vem vazia; sem nota, `nota` é nula.
 * Pós-condições: nenhuma (só leitura).
 * Regras: "N usuários" conta inscrição principal e suplementar, uma vez por pessoa, só de quem tem vínculo
 * ativo ({@see SeccionalOabRepository::usuariosPorUf()}); não é a contagem da tabela de UFs.
 */
final class ListarSeccionaisUseCase
{
    public const ULTIMAS = 5;

    public function __construct(
        private readonly SeccionalOabRepository $seccionais,
        private readonly DivulgacaoNaSeccionalRepository $divulgacoes,
        private readonly SubsecaoOabRepository $subsecoes,
        private readonly NotaDaSeccionalRepository $notas,
    ) {
    }

    /**
     * @return list<array{uf: string, nome: string, endereco: ?string, telefone: ?string, site: ?string, usuarios: int,
     *     ultima: ?array{tipo: string, rotulo: string, data: string},
     *     ultimas: list<array{tipo: string, rotulo: string, data: string, autor: ?string, nota: ?string}>,
     *     subsecoes: list<array{id: int, nome: string, endereco: string, telefone: ?string}>,
     *     nota: ?array{texto: string, atualizadaEm: string, autor: ?string}}>
     */
    public function executar(): array
    {
        $usuarios = $this->seccionais->usuariosPorUf();
        $ultimas = $this->divulgacoes->ultimasPorUf(self::ULTIMAS);
        $subsecoes = $this->subsecoes->todasPorUf();
        $notas = $this->notas->todasPorUf();

        return array_map(
            fn (SeccionalOab $s): array => $this->linha($s, $usuarios[$s->getUf()] ?? 0, $ultimas[$s->getUf()] ?? [])
                + ['subsecoes' => $subsecoes[$s->getUf()] ?? [], 'nota' => $notas[$s->getUf()] ?? null],
            $this->seccionais->todasEmOrdem(),
        );
    }

    /**
     * @param list<array{tipo: string, data: string, nota: ?string, autor: ?string}> $divulgacoes
     *
     * @return array<string, mixed>
     */
    private function linha(SeccionalOab $s, int $usuarios, array $divulgacoes): array
    {
        $lista = array_map(self::divulgacao(...), array_slice($divulgacoes, 0, self::ULTIMAS));
        $ultima = $lista[0] ?? null;

        return [
            'uf'       => $s->getUf(),
            'nome'     => $s->getNome(),
            'endereco' => $s->getEndereco(),
            'telefone' => $s->getTelefone(),
            'site'     => $s->getSite(),
            'usuarios' => $usuarios,
            'ultima'   => $ultima === null ? null : ['tipo' => $ultima['tipo'], 'rotulo' => $ultima['rotulo'], 'data' => $ultima['data']],
            'ultimas'  => $lista,
        ];
    }

    /**
     * @param array{tipo: string, data: string, nota: ?string, autor: ?string} $d
     *
     * @return array{tipo: string, rotulo: string, data: string, autor: ?string, nota: ?string}
     */
    private static function divulgacao(array $d): array
    {
        return [
            'tipo'   => $d['tipo'],
            'rotulo' => TipoDeDivulgacao::tryFrom($d['tipo'])?->rotulo() ?? $d['tipo'],
            'data'   => $d['data'],
            'autor'  => $d['autor'],
            'nota'   => $d['nota'],
        ];
    }
}
