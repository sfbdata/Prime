<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Service;

use App\AssinaturaEletronica\Armazenamento\ChavesDeAssinaturaEletronica;
use App\AssinaturaEletronica\Entity\DocumentoParaAssinar;
use App\AssinaturaEletronica\Entity\Signatario;
use App\Shared\Armazenamento\ArmazenamentoDeArquivos;
use App\Shared\Armazenamento\ChaveDeArquivo;
use App\Shared\Armazenamento\Sha256DeArquivo;

/**
 * O `hash_enviado` do documento (contrato da etapa 2, seção 4.2): sha256 do JSON canônico (chaves ordenadas, sem
 * espaços, UTF-8) de tudo o que foi enviado para assinar, como o desenho calcula o hash do conteúdo e não de um PDF:
 *
 *     {formato: 1, nome, blocos, layout, lateral, folhaBranca, emOrdem, mensagem, prazoDias,
 *      logo: {tipo, sha256|null}, arquivo: {sha256, paginas}|null,
 *      signatarios: [{ordem, nome, email, cpf, papel, nivel}]}
 *
 * O sha256 da logo é o da logo própria do documento (as outras não são arquivo dele); o do arquivo, o do PDF enviado.
 * O PDF para assinar e o PDF final são da etapa 3.
 */
final readonly class ConteudoCanonicoDoDocumento
{
    public const FORMATO = 1;

    public function __construct(
        private ArmazenamentoDeArquivos $armazenamento,
    ) {
    }

    public function hash(DocumentoParaAssinar $documento): string
    {
        return hash('sha256', self::json(
            $documento,
            $this->sha256(ChavesDeAssinaturaEletronica::logo($documento)),
            $this->sha256(ChavesDeAssinaturaEletronica::arquivo($documento)),
        ));
    }

    /** O JSON canônico, com os hashes dos arquivos já lidos (o teste usa valores fixos). */
    public static function json(DocumentoParaAssinar $documento, ?string $sha256DaLogo, ?string $sha256DoArquivo): string
    {
        $conteudo = [
            'formato' => self::FORMATO,
            'nome' => $documento->getNome(),
            'blocos' => $documento->getBlocos(),
            'layout' => $documento->getLayout(),
            'lateral' => $documento->getLateral(),
            'folhaBranca' => $documento->isFolhaBranca(),
            'emOrdem' => $documento->isEmOrdem(),
            'mensagem' => $documento->getMensagem(),
            'prazoDias' => $documento->getPrazoDias(),
            'logo' => ['tipo' => $documento->getLogoTipo()->value, 'sha256' => $sha256DaLogo],
            'arquivo' => $sha256DoArquivo !== null ? ['sha256' => $sha256DoArquivo, 'paginas' => $documento->getArquivoEnviadoPaginas()] : null,
            'signatarios' => array_map(static fn (Signatario $s): array => [
                'ordem' => $s->getOrdem(),
                'nome' => $s->getNome(),
                'email' => $s->getEmail(),
                'cpf' => $s->getCpf(),
                'papel' => $s->getPapel(),
                'nivel' => $s->getNivel()->value,
            ], $documento->getSignatarios()),
        ];

        return json_encode(self::ordenar($conteudo), \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR);
    }

    private function sha256(?ChaveDeArquivo $chave): ?string
    {
        return $chave !== null ? Sha256DeArquivo::deChave($this->armazenamento, $chave) : null;
    }

    /**
     * @param array<mixed> $valor
     *
     * @return array<mixed>
     */
    private static function ordenar(array $valor): array
    {
        if (!array_is_list($valor)) {
            ksort($valor, \SORT_STRING);
        }

        foreach ($valor as $chave => $item) {
            if (\is_array($item)) {
                $valor[$chave] = self::ordenar($item);
            }
        }

        return $valor;
    }
}
