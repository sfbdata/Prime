<?php

declare(strict_types=1);

namespace App\Pasta\Service;

use App\Pasta\DTO\SugestoesDeLimpezaOutput;

/**
 * Sugestões de limpeza da aba Documentos (DOC-62/63/64/66) — as regras de `expLimpeza` do
 * desenho (02 - EXPEDIENTES 1.2.3, dc L4686-4697), avaliadas sobre os arquivos de UMA pasta.
 *
 * NÃO É IA: são quatro regras fixas sobre dados que o servidor já guarda (sha256, tamanho,
 * páginas, nome, origem). Serviço puro: recebe arrays simples, não consulta nada.
 *
 * POR NÍVEL, como o desenho (`expLimpeza(itens)` recebe os itens do nível aberto, dc L4712):
 * "idêntico" e "nome parecido" só comparam arquivos da MESMA seção (`secaoId`; NULL = raiz).
 * Dois arquivos iguais em seções diferentes não são marcados. A faixa só aparece na raiz
 * (dc `cam.length ? { sug: [] } : LP`, L4945), então `grupos` traz só os arquivos da raiz; o
 * selo de cada linha usa `regraDe`, que vale em qualquer nível.
 *
 * Cada arquivo cai em no máximo UMA regra, nesta precedência (a do desenho):
 *
 *  1. Idêntico: mesmo sha256 no mesmo nível. Fica o mais antigo (`carregadoEm`; empate pelo
 *     menor id); os demais são sugeridos. sha256 NULL (acervo ainda sem o hash) NÃO conta —
 *     selo sem lastro é nada. Arquivo de 0 byte não entra no grupo (todo vazio tem o mesmo hash).
 *  2. Vazio: 0 byte — MENOS quando o arquivo veio do Drive (`doDrive`). Em produção há 114
 *     documentos de 0 byte, todos com `drive_file_id` e mime `application/x-empty`: são o rastro
 *     do defeito de download do Drive (DT-8) — o conteúdo real está no Drive, e sugerir excluir
 *     apagaria a única referência a ele no sistema. Esses não recebem sugestão nenhuma.
 *  3. Cópia do processo: o nome casa `processo|autos|integra|completo|NNNNNNN-NN` (sem acento,
 *     então "íntegra" também) E tem MAIS de 100 páginas ou MAIS de 50 MB. Páginas NULL (não é
 *     PDF ou não foi contado) vale só o critério dos MB.
 *  4. Muito grande: MAIS de 100 MB.
 *
 * "Nome parecido" (≥ 0,85, {@see SimilaridadeDeNomes}) é só sinal: nunca vira sugestão de excluir.
 */
final class SugestoesDeLimpeza
{
    public const SIMILARIDADE_MINIMA = 0.85;
    public const PAGINAS_DA_COPIA    = 100;
    public const BYTES_DA_COPIA      = 50 * 1024 * 1024;
    public const BYTES_MUITO_GRANDE  = 100 * 1024 * 1024;

    /** dc L4693: `processo|autos|integra|íntegra|completo|\d{7}-\d{2}`, sobre o nome normalizado. */
    private const NOME_DE_PROCESSO = '/processo|autos|integra|completo|\d{7}-\d{2}/';

    private const REGRAS = [
        SugestoesDeLimpezaOutput::IDENTICO,
        SugestoesDeLimpezaOutput::VAZIO,
        SugestoesDeLimpezaOutput::COPIA_PROCESSO,
        SugestoesDeLimpezaOutput::MUITO_GRANDE,
    ];

    /**
     * @param list<array{id: int, secaoId: ?int, nome: string, tamanho: int, sha256: ?string, paginas: ?int, carregadoEm: string, doDrive: bool}> $arquivos
     */
    public static function avaliar(array $arquivos): SugestoesDeLimpezaOutput
    {
        if ($arquivos === []) {
            return SugestoesDeLimpezaOutput::nada();
        }

        $identicoA = [];
        $regraDe   = [];

        $porHash = [];
        foreach ($arquivos as $a) {
            if ($a['sha256'] !== null && $a['sha256'] !== '' && $a['tamanho'] > 0) {
                $porHash[($a['secaoId'] ?? 0) . '|' . $a['sha256']][] = $a;
            }
        }
        foreach ($porHash as $grupo) {
            if (\count($grupo) < 2) {
                continue;
            }
            usort($grupo, static fn (array $x, array $y): int => [$x['carregadoEm'], $x['id']] <=> [$y['carregadoEm'], $y['id']]);
            $fica = $grupo[0]['id'];
            $identicoA[$fica] = $grupo[1]['id'];
            foreach (\array_slice($grupo, 1) as $copia) {
                $identicoA[$copia['id']] = $fica;
                $regraDe[$copia['id']]   = SugestoesDeLimpezaOutput::IDENTICO;
            }
        }

        foreach ($arquivos as $a) {
            if (isset($regraDe[$a['id']])) {
                continue;
            }
            $regra = self::regraSemHash($a);
            if ($regra !== null) {
                $regraDe[$a['id']] = $regra;
            }
        }

        $grupos = [];
        foreach (self::REGRAS as $regra) {
            $ids   = [];
            $bytes = 0;
            foreach ($arquivos as $a) {
                if ($a['secaoId'] === null && ($regraDe[$a['id']] ?? null) === $regra) {
                    $ids[]  = $a['id'];
                    $bytes += $a['tamanho'];
                }
            }
            if ($ids !== []) {
                $grupos[] = ['regra' => $regra, 'ids' => $ids, 'bytes' => $bytes, 'rotulo' => self::rotulo($regra, \count($ids))];
            }
        }

        ksort($regraDe);

        return new SugestoesDeLimpezaOutput($identicoA, self::nomesParecidos($arquivos), $grupos, $regraDe);
    }

    /**
     * O pedaço da faixa "Ganhe espaço" de cada regra (dc L4946: "2 cópias idênticas, 1 vazio,
     * 1 cópia do processo, 1 muito grande").
     */
    public static function rotulo(string $regra, int $n): string
    {
        return match ($regra) {
            SugestoesDeLimpezaOutput::IDENTICO       => $n . ($n === 1 ? ' cópia idêntica' : ' cópias idênticas'),
            SugestoesDeLimpezaOutput::VAZIO          => $n . ($n === 1 ? ' vazio' : ' vazios'),
            SugestoesDeLimpezaOutput::COPIA_PROCESSO => $n . ($n === 1 ? ' cópia do processo' : ' cópias do processo'),
            SugestoesDeLimpezaOutput::MUITO_GRANDE   => $n . ($n === 1 ? ' muito grande' : ' muito grandes'),
            default                                  => throw new \InvalidArgumentException('Regra de limpeza desconhecida: ' . $regra),
        };
    }

    /** @param array{id: int, secaoId: ?int, nome: string, tamanho: int, sha256: ?string, paginas: ?int, carregadoEm: string, doDrive: bool} $a */
    private static function regraSemHash(array $a): ?string
    {
        if ($a['tamanho'] === 0) {
            // Vindo do Drive, 0 byte é o rastro do DT-8, não um arquivo vazio: nada a sugerir.
            return $a['doDrive'] ? null : SugestoesDeLimpezaOutput::VAZIO;
        }

        if (preg_match(self::NOME_DE_PROCESSO, SimilaridadeDeNomes::normalizar($a['nome'])) === 1
            && (($a['paginas'] !== null && $a['paginas'] > self::PAGINAS_DA_COPIA) || $a['tamanho'] > self::BYTES_DA_COPIA)
        ) {
            return SugestoesDeLimpezaOutput::COPIA_PROCESSO;
        }

        if ($a['tamanho'] > self::BYTES_MUITO_GRANDE) {
            return SugestoesDeLimpezaOutput::MUITO_GRANDE;
        }

        return null;
    }

    /**
     * Para cada arquivo, o de nome mais parecido NO MESMO NÍVEL (maior similaridade; empate pelo
     * menor id).
     *
     * @param list<array{id: int, secaoId: ?int, nome: string, tamanho: int, sha256: ?string, paginas: ?int, carregadoEm: string, doDrive: bool}> $arquivos
     *
     * @return array<int, array{id: int, percentual: int}>
     */
    private static function nomesParecidos(array $arquivos): array
    {
        $nomesPorNivel = [];
        foreach ($arquivos as $a) {
            $nomesPorNivel[$a['secaoId'] ?? 0][$a['id']] = $a['nome'];
        }

        $melhor = [];
        foreach ($nomesPorNivel as $nomes) {
            foreach (SimilaridadeDeNomes::paresParecidos($nomes, self::SIMILARIDADE_MINIMA) as $par) {
                foreach ([[$par['a'], $par['b']], [$par['b'], $par['a']]] as [$de, $para]) {
                    $atual = $melhor[$de] ?? null;
                    if ($atual === null || $par['similaridade'] > $atual['s'] || ($par['similaridade'] === $atual['s'] && $para < $atual['id'])) {
                        $melhor[$de] = ['id' => $para, 's' => $par['similaridade']];
                    }
                }
            }
        }

        $saida = [];
        foreach ($melhor as $id => $m) {
            $saida[$id] = ['id' => $m['id'], 'percentual' => (int) round($m['s'] * 100)];
        }
        ksort($saida);

        return $saida;
    }
}
