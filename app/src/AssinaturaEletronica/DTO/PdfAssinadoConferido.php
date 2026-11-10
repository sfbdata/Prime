<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\DTO;

use App\AssinaturaEletronica\Service\ResultadoDoPdfAssinado;

/**
 * O que a tela pública mostra do PDF assinado que passou na pré-conferência (etapa 5, bloco 5C; `aSigTxt`, dc:466):
 * quantas assinaturas digitais o arquivo tem, o nome do arquivo e se as assinaturas anteriores foram preservadas. Os
 * nomes do JSON são os marcadores do texto do catálogo (`situacao_ok` e `situacao_ok_preservadas`: `{n}` e
 * `{arquivo}`).
 *
 * O nome vem do navegador e sai pela mesma regra da versão guardada ({@see PdfAssinadoEnviado::nomeSeguro()}).
 */
final readonly class PdfAssinadoConferido
{
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

        return new self($resultado->assinaturas(), PdfAssinadoEnviado::nomeSeguro($nomeDoArquivo), $resultado->preservadas());
    }

    /** @return array{n: int, arquivo: string, preservadas: bool} */
    public function paraJson(): array
    {
        return ['n' => $this->n, 'arquivo' => $this->arquivo, 'preservadas' => $this->preservadas];
    }
}
