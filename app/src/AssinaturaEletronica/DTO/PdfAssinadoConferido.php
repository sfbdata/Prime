<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\DTO;

use App\AssinaturaEletronica\Service\ResultadoDoPdfAssinado;
use App\Shared\Http\NomeSeguroDeArquivo;

/**
 * O que a tela pública mostra do PDF assinado que passou na pré-conferência (etapa 5, bloco 5C; `aSigTxt`, dc:466):
 * quantas assinaturas digitais o arquivo tem, o nome do arquivo e se as assinaturas anteriores foram preservadas. Os
 * nomes do JSON são os marcadores do texto do catálogo (`situacao_ok` e `situacao_ok_preservadas`: `{n}` e
 * `{arquivo}`).
 *
 * O nome vem do navegador: sai em UTF-8 válido, sem barra nem caractere de controle e com até 255 caracteres (o teto
 * do nome da versão guardada).
 */
final readonly class PdfAssinadoConferido
{
    public const NOME_MAXIMO = 255;

    public function __construct(
        public int $n,
        public string $arquivo,
        public bool $preservadas,
    ) {
    }

    /** @throws \LogicException resultado recusado */
    public static function de(ResultadoDoPdfAssinado $resultado, string $nomeDoArquivo): self
    {
        if (!$resultado->aceitou()) {
            throw new \LogicException('Só o PDF assinado aceito vai para a tela como conferido.');
        }
        $nome = NomeSeguroDeArquivo::paraDownload(mb_substr(mb_scrub($nomeDoArquivo, 'UTF-8'), 0, self::NOME_MAXIMO));

        return new self($resultado->assinaturas(), $nome, $resultado->preservadas());
    }

    /** @return array{n: int, arquivo: string, preservadas: bool} */
    public function paraJson(): array
    {
        return ['n' => $this->n, 'arquivo' => $this->arquivo, 'preservadas' => $this->preservadas];
    }
}
