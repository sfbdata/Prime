<?php

declare(strict_types=1);

namespace App\Master\UseCase;

use App\Master\Fontes\ArquivoNaoReconhecido;
use App\Master\Fontes\FontesPublicas;
use App\Master\Fontes\LeitorDeCsvDeFontes;

/**
 * Recebe o CSV que o Master envia pelo menu ⋮ do mapa: a tabela de dados públicos (população e
 * advogados) ou o Quadro da Advocacia. Excel e PDF ainda não são lidos no servidor: só CSV no
 * formato do modelo baixado pelo próprio menu.
 */
final class ImportarArquivoDeFontesUseCase
{
    public const TAMANHO_MAXIMO = 2_000_000;

    public function __construct(
        private readonly LeitorDeCsvDeFontes $leitor,
        private readonly FontesPublicas $fontes,
    ) {
    }

    /**
     * @return array{estados: int, municipios: int}
     *
     * @throws ArquivoNaoReconhecido
     */
    public function tabela(string $conteudo, string $nomeArquivo): array
    {
        self::validar($conteudo);
        $tabela = $this->leitor->tabela($conteudo);
        $this->fontes->gravarTabelaEnviada(['uf' => $tabela['uf'], 'mun' => $tabela['mun']], $nomeArquivo);

        return ['estados' => $tabela['estados'], 'municipios' => $tabela['municipios']];
    }

    /**
     * @return array{data: string, seccionais: int}
     *
     * @throws ArquivoNaoReconhecido
     */
    public function quadroOab(string $conteudo, string $nomeArquivo): array
    {
        self::validar($conteudo);

        return $this->fontes->gravarQuadroOabEnviado($this->leitor->quadroOab($conteudo), $nomeArquivo);
    }

    private static function validar(string $conteudo): void
    {
        if ($conteudo === '' || strlen($conteudo) > self::TAMANHO_MAXIMO) {
            throw new ArquivoNaoReconhecido('Arquivo vazio ou grande demais. Use o modelo baixado pelo menu.');
        }

        if (!mb_check_encoding($conteudo, 'UTF-8')) {
            throw new ArquivoNaoReconhecido('O arquivo precisa estar em UTF-8, como o modelo baixado pelo menu.');
        }
    }
}
