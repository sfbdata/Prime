<?php

declare(strict_types=1);

namespace App\Processo\DTO;

use Symfony\Component\HttpFoundation\Request;

/**
 * Corpo do cadastro de processo novo pela janela "Vincular processo" da pasta (desenho 02, `procSalvarVinc`):
 * os campos crus, como o formulário os manda. Quem valida e interpreta é o `CadastrarProcessoPelaPastaUseCase`.
 * Campo mandado como lista (ou lista mandada como texto) vira vazio, em vez de 400 ou de erro de tipo.
 */
final readonly class CadastroDoProcessoInput
{
    private const CAMPOS = ['numeroProcesso', 'orgaoJulgador', 'classeProcessual', 'assuntoProcessual', 'situacaoProcesso', 'instancia', 'dataDistribuicao', 'dataBaixa'];
    private const CAMPOS_DA_PARTE = ['tipo', 'nome', 'documento', 'papel'];
    private const CAMPOS_DA_MOVIMENTACAO = ['dataMovimentacao', 'descricao', 'tipo', 'orgao'];

    /**
     * @param list<array<string, string>> $partes        cada linha: tipo, nome, documento, papel
     * @param list<array<string, string>> $movimentacoes cada linha: dataMovimentacao, descricao, tipo, orgao
     */
    public function __construct(
        public string $numeroProcesso,
        public string $orgaoJulgador = '',
        public string $classeProcessual = '',
        public string $assuntoProcessual = '',
        public string $situacaoProcesso = '',
        public string $instancia = '',
        public string $dataDistribuicao = '',
        public string $dataBaixa = '',
        public array $partes = [],
        public array $movimentacoes = [],
    ) {
    }

    public static function fromRequest(Request $request): self
    {
        $corpo = $request->request->all();
        $campos = self::textos($corpo, self::CAMPOS);

        return new self(
            $campos['numeroProcesso'],
            $campos['orgaoJulgador'],
            $campos['classeProcessual'],
            $campos['assuntoProcessual'],
            $campos['situacaoProcesso'],
            $campos['instancia'],
            $campos['dataDistribuicao'],
            $campos['dataBaixa'],
            self::linhas($corpo['partes'] ?? null, self::CAMPOS_DA_PARTE),
            self::linhas($corpo['movimentacoes'] ?? null, self::CAMPOS_DA_MOVIMENTACAO),
        );
    }

    /** O número só com os dígitos (como o processo é gravado e buscado). */
    public function numeroSoDigitos(): string
    {
        return preg_replace('/\D+/', '', $this->numeroProcesso) ?? '';
    }

    /**
     * @param array<mixed>  $corpo
     * @param list<string>  $chaves
     *
     * @return array<string, string>
     */
    private static function textos(array $corpo, array $chaves): array
    {
        $textos = [];
        foreach ($chaves as $chave) {
            $valor = $corpo[$chave] ?? null;
            $textos[$chave] = is_string($valor) ? trim($valor) : '';
        }

        return $textos;
    }

    /**
     * @param list<string> $chaves
     *
     * @return list<array<string, string>>
     */
    private static function linhas(mixed $lista, array $chaves): array
    {
        if (!is_array($lista)) {
            return [];
        }

        $linhas = [];
        foreach ($lista as $linha) {
            if (is_array($linha)) {
                $linhas[] = self::textos($linha, $chaves);
            }
        }

        return $linhas;
    }
}
