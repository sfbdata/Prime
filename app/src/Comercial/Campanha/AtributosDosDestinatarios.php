<?php

declare(strict_types=1);

namespace App\Comercial\Campanha;

use App\Auth\Enum\StatusOab;
use App\Comercial\DTO\AtributosDoDestinatario;
use App\Comercial\Repository\CadastradosRepository;
use App\Contribua\Service\SituacaoDoEspaco;
use App\Master\Service\LocalizacaoDaPessoa;
use App\Profile\Enum\Atuacao;
use App\Profile\Enum\Genero;
use App\Profile\Repository\UserProfileRepository;

/**
 * Os atributos do filtro da campanha para uma lista de e-mails (spec comercial-e-emails.md §9, decisões do
 * coordenador de 21/10). Cruza cada endereço com as contas, também nas listas sem cadastro (D1): quem tem conta
 * ganha os dados dela; quem não tem fica sem nada. Três leituras em lote: vínculos válidos, Meu Perfil e o plano dos
 * escritórios (pelo Contribua, que é o dono do espaço).
 */
final class AtributosDosDestinatarios
{
    private const CARGO = [
        Atuacao::Advogado->value => 'adv', Atuacao::Estagiario->value => 'est', Atuacao::Controladoria->value => 'ctl',
        Atuacao::Financeiro->value => 'fin', Atuacao::Administrativo->value => 'adm', Atuacao::Outra->value => 'out',
    ];
    private const GENERO = [Genero::Feminino->value => 'F', Genero::Masculino->value => 'M'];
    /** O PostgreSQL aceita até 65535 parâmetros por consulta: os IN vão em blocos. */
    private const BLOCO = 10000;

    public function __construct(
        private readonly CadastradosRepository $cadastrados,
        private readonly UserProfileRepository $perfis,
        private readonly SituacaoDoEspaco $espaco,
    ) {
    }

    /**
     * @param list<string> $emails já em minúsculas
     *
     * @return array<string, AtributosDoDestinatario> e-mail => atributos, só de quem tem conta com vínculo válido
     */
    public function de(array $emails): array
    {
        $porEmail = [];
        foreach (array_chunk(array_values(array_unique($emails)), self::BLOCO) as $bloco) {
            foreach ($this->cadastrados->vinculosDe($bloco) as $v) {
                $porEmail[$v['email']][] = $v;
            }
        }
        if ($porEmail === []) {
            return [];
        }
        $perfis = [];
        foreach (array_chunk(array_values(array_unique(array_map(static fn (array $vs): int => $vs[0]['userId'], $porEmail))), self::BLOCO) as $ids) {
            $perfis += $this->perfis->atributosDe($ids);
        }
        $planos = $this->planosDosEscritorios($porEmail);

        return array_map(fn (array $vs): AtributosDoDestinatario => $this->montar($vs, $perfis[$vs[0]['userId']] ?? [], $planos), $porEmail);
    }

    /**
     * @param list<array{email: string, userId: int, tenantId: int, primario: bool, lotacao: string, ultimoAcesso: string, oabNumero: ?string, oabUf: ?string, oabStatus: ?string, estado: ?string}> $vinculos
     * @param array{genero?: ?string, atuacao?: ?string, dataNascimento?: ?\DateTimeImmutable, uf?: ?string}                                                                                            $perfil
     * @param array<int, string>                                                                                                                                                                           $planos tenantId => código do plano
     */
    private function montar(array $vinculos, array $perfil, array $planos): AtributosDoDestinatario
    {
        $primeiro = $vinculos[0];

        return new AtributosDoDestinatario(
            self::GENERO[$perfil['genero'] ?? ''] ?? null,
            $perfil['dataNascimento'] ?? null,
            self::uf($vinculos),
            array_filter($vinculos, static fn (array $v): bool => $v['primario']) !== [] ? 'prim' : 'sec',
            self::CARGO[$perfil['atuacao'] ?? ''] ?? null,
            self::oab($primeiro['oabNumero'], $primeiro['oabStatus']),
            array_values(array_unique(array_map(static fn (array $v): string => $planos[$v['tenantId']] ?? 'free', $vinculos))),
        );
    }

    /**
     * D2: a regra do mapa do Master ("Onde estamos atuando"): lotação "Cidade/UF", depois a OAB, depois o escritório.
     *
     * @param list<array{email: string, userId: int, tenantId: int, primario: bool, lotacao: string, ultimoAcesso: string, oabNumero: ?string, oabUf: ?string, oabStatus: ?string, estado: ?string}> $vinculos
     */
    private static function uf(array $vinculos): ?string
    {
        $estados = [];
        foreach ($vinculos as $v) {
            $estados[$v['tenantId']] = $v['estado'];
        }
        $local = LocalizacaoDaPessoa::localizar([
            'oabUf' => $vinculos[0]['oabUf'],
            'vinculos' => array_map(static fn (array $v): array => ['tenantId' => $v['tenantId'], 'lotacao' => $v['lotacao'], 'primario' => $v['primario'], 'ultimoAcesso' => $v['ultimoAcesso']], $vinculos),
        ], $estados);

        return $local['uf'] ?? null;
    }

    /** D6: com número, confirmada é `conf` e não verificada ou divergente é `pend`; sem número, `sem`. */
    private static function oab(?string $numero, ?string $status): string
    {
        if ($numero === null) {
            return 'sem';
        }

        return $status === StatusOab::Confirmada->value ? 'conf' : 'pend';
    }

    /**
     * D3: o plano de cada escritório válido, numa leitura só do Contribua (sem espaço gravado = Free).
     *
     * @param array<string, list<array{tenantId: int}>> $porEmail
     *
     * @return array<int, string>
     */
    private function planosDosEscritorios(array $porEmail): array
    {
        $tenants = [];
        foreach ($porEmail as $vinculos) {
            foreach ($vinculos as $v) {
                $tenants[$v['tenantId']] = 0;
            }
        }

        return array_map(static fn ($retrato): string => $retrato->planoCodigo, $this->espaco->retratarVarios($tenants));
    }
}
