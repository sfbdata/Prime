<?php

declare(strict_types=1);

namespace App\Master\Service;

/**
 * Agregados do mapa "Onde estamos atuando" (desenho 05 - Perfis 1.0, `Component.geo()` e
 * `Component.tend()`). Devolve SÓ contagens por UF e cidade: nenhum identificador de pessoa sai daqui.
 *
 * Duas visões que nunca se misturam:
 *  - Pessoas: cada pessoa conta uma vez, no local dado por {@see LocalizacaoDaPessoa}. "Geral" conta
 *    todas; "Só primários" conta quem é primário em pelo menos um escritório;
 *  - Escritórios: cada escritório conta uma vez, em `Tenant.estado`/`Tenant.cidade`. Sem estado
 *    válido vai para "Sem endereço no cadastro".
 *
 * Seta de tendência por estado: entradas (`User.createdAt`) dos últimos 30 dias contra as dos 30
 * anteriores. Saídas não são medidas (remover colaborador apaga o vínculo, sem data de desativação).
 */
final class MapaDeAtuacao
{
    /**
     * @param list<array{criadoEm: string, primario: bool, oabUf: ?string, vinculos: list<array{tenantId: int, lotacao: string, primario: bool, ultimoAcesso: string}>}> $pessoas
     * @param list<array{id: int, cidade: ?string, estado: ?string}>                                                                                                     $escritorios
     *
     * @return array{pessoas: array{geral: array<string, mixed>, prim: array<string, mixed>}, escritorios: array<string, mixed>, exterior: int, paises: list<mixed>}
     */
    public static function montar(array $pessoas, array $escritorios, \DateTimeImmutable $hoje): array
    {
        $estadoPorEscritorio = [];
        foreach ($escritorios as $e) {
            $estadoPorEscritorio[$e['id']] = $e['estado'];
        }

        $locais = [];
        foreach ($pessoas as $i => $p) {
            $locais[$i] = LocalizacaoDaPessoa::localizar($p, $estadoPorEscritorio);
        }

        return [
            'pessoas' => [
                'geral' => self::contarPessoas($pessoas, $locais, false, $hoje),
                'prim'  => self::contarPessoas($pessoas, $locais, true, $hoje),
            ],
            'escritorios' => self::contarEscritorios($escritorios),
            // Escritório não tem país no cadastro: todo mundo está no Brasil até existir o dado.
            'exterior' => 0,
            'paises'   => [],
        ];
    }

    /**
     * @param list<array<string, mixed>>                                       $pessoas
     * @param array<int, array{uf: string, cidade: string, fonte: string}|null> $locais
     *
     * @return array{A: array<string, int>, C: array<string, array<string, int>>, tend: array<string, array{ag: int, an: int}>, semLoc: int, total: int}
     */
    private static function contarPessoas(array $pessoas, array $locais, bool $soPrimarios, \DateTimeImmutable $hoje): array
    {
        $porUf = [];
        $porCidade = [];
        $tendencia = [];
        $semLocal = 0;
        $total = 0;

        foreach ($pessoas as $i => $p) {
            if ($soPrimarios && !$p['primario']) {
                continue;
            }

            $total++;
            $local = $locais[$i];
            if ($local === null) {
                $semLocal++;
                continue;
            }

            $uf = $local['uf'];
            $porUf[$uf] = ($porUf[$uf] ?? 0) + 1;
            if ($local['cidade'] !== '') {
                $porCidade[$uf][$local['cidade']] = ($porCidade[$uf][$local['cidade']] ?? 0) + 1;
            }

            $tendencia[$uf] ??= ['ag' => 0, 'an' => 0];
            $dias = self::diasDesde($p['criadoEm'], $hoje);
            if ($dias !== null && $dias <= 30) {
                $tendencia[$uf]['ag']++;
            } elseif ($dias !== null && $dias <= 60) {
                $tendencia[$uf]['an']++;
            }
        }

        return ['A' => $porUf, 'C' => $porCidade, 'tend' => $tendencia, 'semLoc' => $semLocal, 'total' => $total];
    }

    /**
     * @param list<array{id: int, cidade: ?string, estado: ?string}> $escritorios
     *
     * @return array{A: array<string, int>, C: array<string, array<string, int>>, semEnd: int}
     */
    private static function contarEscritorios(array $escritorios): array
    {
        $porUf = [];
        $porCidade = [];
        $semEndereco = 0;

        foreach ($escritorios as $e) {
            $uf = LocalizacaoDaPessoa::uf($e['estado']);
            if ($uf === null) {
                $semEndereco++;
                continue;
            }

            $porUf[$uf] = ($porUf[$uf] ?? 0) + 1;
            $cidade = LocalizacaoDaPessoa::nomeDeLugar($e['cidade']);
            if ($cidade !== '') {
                $porCidade[$uf][$cidade] = ($porCidade[$uf][$cidade] ?? 0) + 1;
            }
        }

        return ['A' => $porUf, 'C' => $porCidade, 'semEnd' => $semEndereco];
    }

    private static function diasDesde(string $data, \DateTimeImmutable $hoje): ?float
    {
        try {
            $quando = new \DateTimeImmutable($data);
        } catch (\Exception) {
            return null;
        }

        return ($hoje->getTimestamp() - $quando->getTimestamp()) / 86400;
    }
}
