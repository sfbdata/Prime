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
 * Seta de tendência por estado (desenho 05 de 18/10, `Component.tend()`; spec perfis-1.2-planos-e-limites §5, C6):
 * SALDO de entradas menos saídas, nos últimos 30 dias (`ag`) e nos 30 anteriores (`an`). As bordas são as mesmas
 * para os dois lados ({@see janela()}): até 30 dias é `ag`; acima de 30 e até 60 é `an`.
 *  - entrada: `User.createdAt` de quem está contado no mapa;
 *  - saída: linha de `saida_da_equipe` ({@see \App\Master\Repository\PainelMasterRepository::saidasRecentes()}),
 *    com estas regras (aprovadas pela coordenação em 21/10/2026):
 *    (a) quem saiu de um escritório e continua ATIVO em outro (está em `$pessoas`) não conta como saída;
 *    (b) escritório na quarentena fica fora, como nas entradas (o recorte é do repositório);
 *    (c) UF pela mesma {@see LocalizacaoDaPessoa}: OAB, depois o estado do escritório de onde saiu (a lotação do
 *        vínculo removido não existe mais). Sem usuário (`user_id` nulo), vale a UF do escritório. Sem UF, a saída
 *        é ignorada;
 *    (d) "Só primários" conta só `era_primario = true`;
 *    (e) a mesma pessoa conta uma saída só por modo (a mais recente), como a entrada conta uma vez: quem é removido
 *        de dois escritórios deixou a plataforma uma vez. Linhas sem usuário não têm como ser agrupadas e contam
 *        uma a uma.
 */
final class MapaDeAtuacao
{
    /**
     * @param list<array{id?: int, criadoEm: string, primario: bool, oabUf: ?string, vinculos: list<array{tenantId: int, lotacao: string, primario: bool, ultimoAcesso: string}>}> $pessoas
     * @param list<array{id: int, cidade: ?string, estado: ?string}>                                                                                                              $escritorios
     * @param list<array{saiuEm: string, eraPrimario: bool, tenantId: int, userId: ?int, oabUf: ?string, estado: ?string}>                                                      $saidas      as dos últimos 60 dias
     *
     * @return array{pessoas: array{geral: array<string, mixed>, prim: array<string, mixed>}, escritorios: array<string, mixed>, exterior: int, paises: list<mixed>}
     */
    public static function montar(array $pessoas, array $escritorios, \DateTimeImmutable $hoje, array $saidas = []): array
    {
        $estadoPorEscritorio = [];
        foreach ($escritorios as $e) {
            $estadoPorEscritorio[$e['id']] = $e['estado'];
        }

        $locais = [];
        foreach ($pessoas as $i => $p) {
            $locais[$i] = LocalizacaoDaPessoa::localizar($p, $estadoPorEscritorio);
        }

        $ativos = [];
        foreach ($pessoas as $p) {
            if (isset($p['id'])) {
                $ativos[$p['id']] = true;
            }
        }

        return [
            'pessoas' => [
                'geral' => self::contarPessoas($pessoas, $locais, self::saidasValidas($saidas, $ativos, false), false, $hoje),
                'prim'  => self::contarPessoas($pessoas, $locais, self::saidasValidas($saidas, $ativos, true), true, $hoje),
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
     * @param list<array{uf: string, saiuEm: string}>                           $saidas  já filtradas e localizadas ({@see saidasValidas()})
     *
     * @return array{A: array<string, int>, C: array<string, array<string, int>>, tend: array<string, array{ag: int, an: int}>, semLoc: int, total: int}
     */
    private static function contarPessoas(array $pessoas, array $locais, array $saidas, bool $soPrimarios, \DateTimeImmutable $hoje): array
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
            $janela = self::janela(self::diasDesde($p['criadoEm'], $hoje));
            if ($janela !== null) {
                $tendencia[$uf][$janela]++;
            }
        }

        return ['A' => $porUf, 'C' => $porCidade, 'tend' => self::descontarSaidas($tendencia, $saidas, $hoje), 'semLoc' => $semLocal, 'total' => $total];
    }

    /**
     * Tira de cada UF as saídas da janela delas (o saldo da seta pode ficar negativo).
     *
     * @param array<string, array{ag: int, an: int}>  $tendencia entradas por UF
     * @param list<array{uf: string, saiuEm: string}> $saidas    já filtradas e localizadas ({@see saidasValidas()})
     *
     * @return array<string, array{ag: int, an: int}>
     */
    private static function descontarSaidas(array $tendencia, array $saidas, \DateTimeImmutable $hoje): array
    {
        foreach ($saidas as $s) {
            $janela = self::janela(self::diasDesde($s['saiuEm'], $hoje));
            if ($janela === null) {
                continue;
            }

            $tendencia[$s['uf']] ??= ['ag' => 0, 'an' => 0];
            $tendencia[$s['uf']][$janela]--;
        }

        return $tendencia;
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

    /**
     * Saídas que contam no modo, localizadas (regras (a), (c), (d) e (e) do docblock da classe).
     *
     * @param list<array{saiuEm: string, eraPrimario: bool, tenantId: int, userId: ?int, oabUf: ?string, estado: ?string}> $saidas
     * @param array<int, true>                                                                                             $ativos userId de quem está contado no mapa
     *
     * @return list<array{uf: string, saiuEm: string}>
     */
    private static function saidasValidas(array $saidas, array $ativos, bool $soPrimarios): array
    {
        $porPessoa = [];
        $semUsuario = [];
        foreach ($saidas as $s) {
            if ($soPrimarios && !$s['eraPrimario']) {
                continue;
            }

            $userId = $s['userId'];
            if ($userId === null) {
                $semUsuario[] = $s;
                continue;
            }

            if (isset($ativos[$userId])) {
                continue;
            }

            if (!isset($porPessoa[$userId]) || strcmp($s['saiuEm'], $porPessoa[$userId]['saiuEm']) > 0) {
                $porPessoa[$userId] = $s;
            }
        }

        $validas = [];
        foreach ([...array_values($porPessoa), ...$semUsuario] as $s) {
            $local = LocalizacaoDaPessoa::localizar(
                [
                    'oabUf'    => $s['oabUf'],
                    'vinculos' => [['tenantId' => $s['tenantId'], 'lotacao' => '', 'primario' => $s['eraPrimario'], 'ultimoAcesso' => '']],
                ],
                [$s['tenantId'] => $s['estado']],
            );
            if ($local !== null) {
                $validas[] = ['uf' => $local['uf'], 'saiuEm' => $s['saiuEm']];
            }
        }

        return $validas;
    }

    /**
     * Janela da seta, IGUAL para entradas e saídas: até 30 dias é `ag`; acima de 30 e até 60 é `an`; fora disso
     * (ou data ilegível), nenhuma.
     *
     * @return 'ag'|'an'|null
     */
    private static function janela(?float $dias): ?string
    {
        return match (true) {
            $dias === null => null,
            $dias <= 30 => 'ag',
            $dias <= 60 => 'an',
            default => null,
        };
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
