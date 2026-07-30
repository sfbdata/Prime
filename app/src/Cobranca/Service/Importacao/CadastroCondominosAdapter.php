<?php

declare(strict_types=1);

namespace App\Cobranca\Service\Importacao;

use App\Cobranca\Enum\TipoVinculo;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Adapter ESPECÍFICO da fonte "Dados cadastrais dos condôminos" da contábil L.G — irmão do
 * `TopLifeInadimplenciaAdapter`, e como ele NÃO é um importador universal: conhece este layout e o
 * traduz para os conceitos do domínio (`CondominoImportavel`).
 * Spec: `docs/specs/cobranca-importar-cadastro-condominos.md`.
 *
 * Layout medido em 2026-07-29: uma aba; L1–L6 institucional/título/total; L8 cabeçalho; L9+ dados;
 * 3 linhas de rodapé (Filtros / endereço da contábil / Emissão). Colunas: A Unidade · B Nome ·
 * C Sacado (papel) · D CPF/CNPJ · E Fração ideal · F Endereço · G E-mail · H Telefone.
 *
 * Regras (§5 da spec):
 * - O PAPEL vem da coluna C. `Proprietário` → Proprietario; `Pessoa relacionada` → **Outro** (a fonte
 *   não afirma co-propriedade, e inventar isso gravaria palpite indistinguível de fato).
 * - Linha sem papel reconhecido é IGNORADA (é rodapé, não dado malformado).
 * - Linha com papel mas sem nome é REJEITADA com motivo.
 * - Telefone: uma célula pode ter vários (quebra de linha). O lixo literal `null () null` é descartado;
 *   se a célula só tinha lixo, a linha entra como rejeitada de contato, mas a pessoa ainda é importada.
 * - Endereço: string única separada por ` - `, parseada DE TRÁS PARA FRENTE (7 segmentos com a unidade
 *   no complemento, 6 sem ela). Contar da esquerda quebraria nas 13 linhas curtas.
 */
final class CadastroCondominosAdapter
{
    private const COL_UNIDADE = 0;
    private const COL_NOME = 1;
    private const COL_PAPEL = 2;
    private const COL_DOCUMENTO = 3;
    private const COL_ENDERECO = 5;
    private const COL_EMAIL = 6;
    private const COL_TELEFONE = 7;

    /** Papéis reconhecidos na coluna "Sacado" (comparados sem acento e em minúsculas). */
    private const PAPEIS = [
        'proprietario' => TipoVinculo::Proprietario,
        'pessoa relacionada' => TipoVinculo::Outro,
    ];

    /** Lixo literal da fonte: telefone ausente vem como "null () null". */
    private const TELEFONE_LIXO = '/^null\s*\(\s*\)\s*null$/i';

    public function ler(string $caminhoArquivo): ResultadoLeituraCadastro
    {
        $reader = IOFactory::createReaderForFile($caminhoArquivo);
        $reader->setReadDataOnly(true);
        $linhas = $reader->load($caminhoArquivo)->getActiveSheet()->toArray(null, true, false, false);

        $importaveis = [];
        $rejeitadas = [];
        $ignoradas = 0;

        foreach ($linhas as $linha) {
            $papel = $this->papel((string) ($linha[self::COL_PAPEL] ?? ''));
            if ($papel === null) {
                ++$ignoradas; // cabeçalho, institucional, rodapé: não é dado

                continue;
            }

            $unidade = trim((string) ($linha[self::COL_UNIDADE] ?? ''));
            $nome = trim((string) ($linha[self::COL_NOME] ?? ''));
            if ($unidade === '' || $nome === '') {
                $rejeitadas[] = new LinhaRejeitada(
                    $unidade !== '' ? $unidade : '(sem unidade)',
                    $unidade === '' ? 'Linha sem unidade.' : 'Linha sem nome da pessoa.',
                    ['unidade' => $unidade, 'nome' => $nome],
                );

                continue;
            }

            [$cpf, $cnpj, $motivoDocumento] = $this->documento((string) ($linha[self::COL_DOCUMENTO] ?? ''));
            if ($motivoDocumento !== null) {
                $rejeitadas[] = new LinhaRejeitada($unidade, $motivoDocumento, ['documento' => trim((string) ($linha[self::COL_DOCUMENTO] ?? ''))]);
            }

            [$telefones, $motivoTelefone] = $this->telefones((string) ($linha[self::COL_TELEFONE] ?? ''));
            if ($motivoTelefone !== null) {
                $rejeitadas[] = new LinhaRejeitada($unidade, $motivoTelefone, ['telefone' => trim((string) ($linha[self::COL_TELEFONE] ?? ''))]);
            }

            $importaveis[] = new CondominoImportavel(
                unidade: $this->separarUnidade($unidade),
                nome: $nome,
                tipoVinculo: $papel,
                cpf: $cpf,
                cnpj: $cnpj,
                email: $this->email((string) ($linha[self::COL_EMAIL] ?? '')),
                telefones: $telefones,
                endereco: $this->endereco((string) ($linha[self::COL_ENDERECO] ?? '')),
            );
        }

        return new ResultadoLeituraCadastro($importaveis, $rejeitadas, $ignoradas);
    }

    private function papel(string $valor): ?TipoVinculo
    {
        $chave = mb_strtolower(trim($valor));
        $chave = strtr($chave, ['á' => 'a', 'â' => 'a', 'ã' => 'a', 'é' => 'e', 'ê' => 'e', 'í' => 'i', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ú' => 'u', 'ç' => 'c']);

        return self::PAPEIS[$chave] ?? null;
    }

    /**
     * Mesma regra do adapter de inadimplência: "NOME (associada)" → "NOME". É o que faz as duas fontes
     * convergirem para o mesmo `ObjetoCobranca` (medido: 119/119 das unidades cobradas casam).
     */
    private function separarUnidade(string $unidade): string
    {
        if (preg_match('/^(.*?)\s*\((.*)\)\s*$/', $unidade, $m) === 1) {
            return trim($m[1]);
        }

        return trim($unidade);
    }

    /**
     * @return array{0: ?string, 1: ?string, 2: ?string} [cpf, cnpj, motivo de rejeição do documento]
     */
    private function documento(string $valor): array
    {
        $digitos = preg_replace('/\D/', '', trim($valor)) ?? '';
        if ($digitos === '') {
            return [null, null, null]; // documento opcional (SPEC §7) — não é erro
        }

        if (strlen($digitos) === 11) {
            return [$digitos, null, null];
        }

        if (strlen($digitos) === 14) {
            return [null, $digitos, null];
        }

        // Não descarta a pessoa: só o documento. Sem documento ela é criada e o
        // SugerirPessoasDuplicadasUseCase aponta possível duplicidade depois.
        return [null, null, sprintf('CPF/CNPJ com %d dígitos (esperado 11 ou 14) — pessoa importada sem documento.', strlen($digitos))];
    }

    /**
     * @return array{0: list<string>, 1: ?string} [telefones, motivo de rejeição]
     */
    private function telefones(string $valor): array
    {
        $valor = trim($valor);
        if ($valor === '') {
            return [[], null];
        }

        $numeros = [];
        $lixo = 0;
        foreach (preg_split('/\R/', $valor) ?: [] as $bruto) {
            $bruto = trim($bruto);
            if ($bruto === '') {
                continue;
            }

            if (preg_match(self::TELEFONE_LIXO, $bruto) === 1 || preg_replace('/\D/', '', $bruto) === '') {
                ++$lixo;

                continue;
            }

            $numeros[] = $bruto;
        }

        // Reporta SEMPRE que houve descarte, mesmo quando sobrou telefone bom na mesma célula. Medido no
        // dado real: são 3 linhas de lixo em 2 células — a terceira convive com um número válido, e
        // reportar só as células 100% lixo a descartaria em silêncio.
        $motivo = null;
        if ($lixo > 0) {
            $motivo = $numeros === []
                ? 'Telefone inválido na fonte ("null () null") — pessoa importada sem telefone.'
                : sprintf('%d telefone(s) inválido(s) na fonte descartado(s); os %d válidos foram importados.', $lixo, count($numeros));
        }

        return [$numeros, $motivo];
    }

    private function email(string $valor): ?string
    {
        $valor = trim($valor);

        return ($valor === '' || filter_var($valor, FILTER_VALIDATE_EMAIL) === false) ? null : $valor;
    }

    /**
     * Parse DE TRÁS PARA FRENTE — a cauda (bairro, cidade, UF, CEP) é fixa; o que sobra entre o número e
     * o bairro é complemento. Contar segmentos da esquerda quebraria nas 13 linhas sem complemento.
     *
     * @return array<string, string>
     */
    private function endereco(string $valor): array
    {
        $partes = array_values(array_filter(array_map('trim', explode(' - ', trim($valor))), static fn (string $p): bool => $p !== ''));
        if (count($partes) < 6) {
            return []; // fora do formato conhecido: não inventa estrutura
        }

        $cep = preg_replace('/\D/', '', array_pop($partes)) ?? '';
        $uf = array_pop($partes);
        $cidade = array_pop($partes);
        $bairro = array_pop($partes);
        $logradouro = array_shift($partes) ?? '';
        $numero = array_shift($partes) ?? '';
        $complemento = implode(' - ', $partes);

        $endereco = [
            'logradouro' => $logradouro,
            'numero' => $numero,
            'bairro' => $bairro,
            'cidade' => $cidade,
            'uf' => mb_strtoupper($uf),
            'cep' => $cep,
        ];
        if ($complemento !== '') {
            $endereco['complemento'] = $complemento;
        }

        return $endereco;
    }
}
