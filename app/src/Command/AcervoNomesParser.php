<?php

declare(strict_types=1);

namespace App\Command;

final class AcervoNomesParser
{
    private const KEYWORDS_SUSPEITAS = [
        'CONTRATO', 'RESCISÃO', 'RESCISAO', 'AÇÃO', 'ACAO',
        'VISTORIA', 'EDITAL', 'NOTIFICAÇÃO', 'NOTIFICACAO',
        'PROPOSTA', 'CARTA', 'TERMO', 'DEFESA',
    ];

    /** @return array{alta: list<array<string,string>>, revisao: list<array<string,string>>, pendencias: list<array<string,string>>} */
    public function parsear(string $conteudo, int $limite = 0): array
    {
        $linhas = $this->carregarLinhas($conteudo, $limite);

        $pendencias = [];
        $pool       = [];

        foreach ($linhas as $original) {
            $linha = $this->normalizar($original);

            $motivo = $this->filtrarPendenciaInicial($linha);
            if ($motivo !== null) {
                $pendencias[] = $this->pendencia($original, $motivo);
                continue;
            }

            $pool[] = ['original' => $original, 'normalizada' => $linha];
        }

        [$pool, $pendenciasRep] = $this->segregarRepetidos($pool);
        $pendencias = array_merge($pendencias, $pendenciasRep);

        $this->ordenarPendencias($pendencias);

        $alta   = [];
        $revisao = [];

        foreach ($pool as $item) {
            $parsed = $this->extrairCampos($item['normalizada']);

            if ($this->eRevisao($parsed['cliente'])) {
                $parsed['motivo_revisao'] = $this->motivoRevisao($parsed['cliente']);
                $revisao[] = array_merge($parsed, ['linha_original' => $item['original']]);
            } else {
                $alta[] = array_merge($parsed, ['linha_original' => $item['original']]);
            }
        }

        return ['alta' => $alta, 'revisao' => $revisao, 'pendencias' => $pendencias];
    }

    /** @return list<string> */
    private function carregarLinhas(string $conteudo, int $limite): array
    {
        $conteudo = mb_convert_encoding($conteudo, 'UTF-8', 'UTF-8');
        $todas    = explode("\n", $conteudo);

        $linhas = [];
        foreach ($todas as $linha) {
            $linha = trim($linha);
            if ($linha === '') {
                continue;
            }
            $linhas[] = $linha;
        }

        if ($limite > 0) {
            $linhas = array_slice($linhas, 0, $limite);
        }

        return $linhas;
    }

    private function normalizar(string $linha): string
    {
        $linha = trim($linha);
        $linha = rtrim($linha, '/');
        $linha = trim($linha);

        // Normaliza separador do NUP grudado ou com espaços irregulares:
        // "1128-CLIENTE", "026- CLIENTE", "1071 -CLIENTE" → "NUP - CLIENTE"
        $linha = (string) preg_replace('/^(\d+)\s*-\s*/', '$1 - ', $linha);

        // Fullwidth slash → slash normal
        $linha = str_replace('／', '/', $linha);

        // En-dash com espaços → " - "
        $linha = str_replace(' – ', ' - ', $linha);
        $linha = str_replace('–', '-', $linha);

        // Colapsa espaços duplos
        $linha = (string) preg_replace('/  +/', ' ', $linha);

        return trim($linha);
    }

    private function filtrarPendenciaInicial(string $linha): ?string
    {
        // Filtro 1: PASTA EQUIPE
        if ($linha === 'A - PASTA EQUIPE') {
            return 'pasta_equipe';
        }

        // Filtro 2: linhas com seta → em qualquer posição
        if (str_contains($linha, '→')) {
            return 'linha_removida';
        }

        // Filtro 3: NUP duplo — dois segmentos numéricos iniciais
        $partes = explode(' - ', $linha);
        if (
            count($partes) >= 3
            && ctype_digit(trim($partes[0]))
            && ctype_digit(trim($partes[1]))
        ) {
            return 'nup_duplo';
        }

        // Filtro 4: pasta vazia — cliente é "VAZIA" ou começa com "PASTA VAZIA"
        if (count($partes) >= 2 && ctype_digit(trim($partes[0]))) {
            $clienteRaw = strtoupper(trim($partes[1]));
            if (
                $clienteRaw === 'VAZIA'
                || str_starts_with($clienteRaw, 'PASTA VAZIA')
                || str_starts_with($clienteRaw, '(PASTA VAZIA)')
            ) {
                return 'pasta_vazia';
            }
        }

        return null;
    }

    /**
     * @param  list<array{original: string, normalizada: string}> $pool
     * @return array{list<array{original: string, normalizada: string}>, list<array<string,string>>}
     */
    private function segregarRepetidos(array $pool): array
    {
        $contagem = [];
        foreach ($pool as $item) {
            $nup = $this->extrairNupSimples($item['normalizada']);
            if ($nup !== null) {
                $contagem[$nup] = ($contagem[$nup] ?? 0) + 1;
            }
        }

        $repetidos  = array_filter($contagem, static fn (int $c): bool => $c > 1);
        $novoPool   = [];
        $pendencias = [];

        foreach ($pool as $item) {
            $nup = $this->extrairNupSimples($item['normalizada']);
            if ($nup !== null && isset($repetidos[$nup])) {
                $pendencias[] = $this->pendencia($item['original'], 'nup_repetido', $nup);
            } else {
                $novoPool[] = $item;
            }
        }

        return [$novoPool, $pendencias];
    }

    private function extrairNupSimples(string $linha): ?string
    {
        $partes = explode(' - ', $linha, 2);
        $seg    = trim($partes[0]);

        return ctype_digit($seg) ? $seg : null;
    }

    /** @return array{nup: string, cliente: string, parte_contraria: string, acao: string, motivo_revisao: string} */
    private function extrairCampos(string $linha): array
    {
        $partes = explode(' - ', $linha, 2);
        $nup    = trim($partes[0]);
        $resto  = isset($partes[1]) ? trim($partes[1]) : '';

        $cliente       = '';
        $parteContraria = '';
        $acao          = '';

        // Detecta litígio: " X " ou " x " com espaços dos dois lados
        if (preg_match('/^(.*?) [Xx] (.*)$/', $resto, $m)) {
            $cliente = trim($m[1]);
            $apósX   = trim($m[2]);

            // Dentro do lado direito, primeiro " - " separa contraparte de ação
            $partesDireita = explode(' - ', $apósX, 2);
            $parteContraria = trim($partesDireita[0]);
            $acao           = isset($partesDireita[1]) ? trim($partesDireita[1]) : '';
        } else {
            $partesSemX = explode(' - ', $resto, 2);
            $cliente    = trim($partesSemX[0]);
            $acao       = isset($partesSemX[1]) ? trim($partesSemX[1]) : '';
        }

        return [
            'nup'            => $nup,
            'cliente'        => $cliente,
            'parte_contraria' => $parteContraria,
            'acao'           => $acao,
            'motivo_revisao' => '',
        ];
    }

    private function eRevisao(string $cliente): bool
    {
        if (trim($cliente) === '') {
            return true;
        }

        $upper = strtoupper(trim($cliente));
        foreach (self::KEYWORDS_SUSPEITAS as $kw) {
            if (str_starts_with($upper, $kw)) {
                return true;
            }
        }

        return false;
    }

    private function motivoRevisao(string $cliente): string
    {
        if (trim($cliente) === '') {
            return 'cliente_vazio';
        }

        return 'cliente_parece_tipo_documento';
    }

    /** @return array<string, string> */
    private function pendencia(string $original, string $motivo, string $nup = ''): array
    {
        if ($nup === '') {
            $partes = explode(' - ', $original, 2);
            $seg    = trim($partes[0]);
            $nup    = ctype_digit($seg) ? $seg : '';
        }

        return [
            'nup'            => $nup,
            'motivo'         => $motivo,
            'linha_original' => $original,
        ];
    }

    /** @param list<array<string,string>> $pendencias */
    private function ordenarPendencias(array &$pendencias): void
    {
        usort($pendencias, static function (array $a, array $b): int {
            $na = is_numeric($a['nup']) ? (int) $a['nup'] : PHP_INT_MAX;
            $nb = is_numeric($b['nup']) ? (int) $b['nup'] : PHP_INT_MAX;

            return $na <=> $nb;
        });
    }
}
