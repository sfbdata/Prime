<?php

declare(strict_types=1);

namespace App\Cliente\Service;

use App\Cliente\Entity\ClienteDocumento;

/**
 * Ponto único dos textos do bloco "Inteligência cadastral" (aba Documentos do cadastro; desenho 02, l.740 a 791).
 *
 * TODOS "a confirmar" com o dono (D1 da spec docs/specs/cliente-inteligencia-cadastral.md): o desenho chama de IA o
 * que é regra, e as regras 1 e 7 de `app/src/Inteligencia/CLAUDE.md` proíbem o rótulo sem o modelo. Por isso nenhum
 * texto daqui fala em IA, e o subtítulo não afirma "cadastro em dia com os documentos" (seria uma leitura que ainda não
 * existe). Nenhum texto leva CPF ou CNPJ: só o nome e a origem.
 */
final class RotulosDaInteligenciaCadastral
{
    public const TITULO = 'Inteligência cadastral';
    public const ICONE = 'bi bi-stars';
    public const ENCONTRADOS = 'Encontrados no menu Documentos';
    public const VINCULAR = 'Vincular ao cadastro';
    public const VINCULAR_ERRO = 'Não foi possível vincular o documento.';
    public const SALVAR_ANTES = 'Salve as alterações antes de vincular o documento.';

    public const ICONE_HOMONIMO = 'bi bi-people';
    public const ICONE_MESMO_DOCUMENTO = 'bi bi-intersect';

    public const ORIGEM_OUTRO_CADASTRO = 'outro cadastro de cliente';
    public const ORIGEM_COBRANCAS = 'Cobranças';

    /** Categoria do documento da pasta => como a linha do "Encontrados" a chama. */
    public const CATEGORIAS = [
        ClienteDocumento::CATEGORIA_IDENTIFICACAO          => 'Documento de identificação',
        ClienteDocumento::CATEGORIA_COMPROVANTE_RESIDENCIA => 'Comprovante de residência',
    ];

    private function __construct()
    {
    }

    public static function subtitulo(int $documentos, int $pastas): string
    {
        return sprintf('%d documento(s) deste cliente em %d pasta(s)', $documentos, $pastas);
    }

    public static function nota(bool $pj): string
    {
        return sprintf(
            'Nome indica uma possibilidade; %s e outros dados confirmam a identidade. Nada muda no cadastro sem validação, e cada mudança fica no Histórico.',
            self::sigla($pj),
        );
    }

    /** @return array{negrito: string, texto: string} */
    public static function homonimo(int $quantos, bool $pj): array
    {
        return [
            'negrito' => 'Possível homônimo.',
            'texto'   => sprintf(
                '%d outro(s) cadastro(s) com %s e %s diferente. Documentos só com o nome não atualizam este cadastro.',
                $quantos,
                $pj ? 'a mesma razão social' : 'o mesmo nome',
                self::sigla($pj),
            ),
        ];
    }

    /**
     * @param string|null $nome o nome do outro registro; null quando quem vê não pode abrir o outro cadastro (o nome
     *                          dele não aparece, só a origem)
     *
     * @return array{negrito: string, texto: string}
     */
    public static function mesmoDocumento(?string $nome, string $origem, bool $pj): array
    {
        $onde = $nome !== null ? sprintf('"%s" em %s.', $nome, $origem) : sprintf('Há %s com outro nome.', $origem);

        return [
            'negrito' => sprintf('Mesmo %s com outro nome.', self::sigla($pj)),
            'texto'   => $onde . ' Pode ser abreviação ou cadastro duplicado; confira antes de consolidar.',
        ];
    }

    /**
     * Os textos fixos do bloco que a tela desenha (o JS não tem texto próprio).
     *
     * @return array{icone: string, encontrados: string, vincular: string, vincularErro: string, salvarAntes: string}
     */
    public static function daTela(): array
    {
        return [
            'icone'        => self::ICONE,
            'encontrados'  => self::ENCONTRADOS,
            'vincular'     => self::VINCULAR,
            'vincularErro' => self::VINCULAR_ERRO,
            'salvarAntes'  => self::SALVAR_ANTES,
        ];
    }

    public static function categoria(string $categoria): string
    {
        return self::CATEGORIAS[$categoria] ?? 'Documento';
    }

    private static function sigla(bool $pj): string
    {
        return $pj ? 'CNPJ' : 'CPF';
    }
}
