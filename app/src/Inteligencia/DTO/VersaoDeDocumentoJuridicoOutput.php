<?php

declare(strict_types=1);

namespace App\Inteligencia\DTO;

use App\Inteligencia\Entity\VersaoDeDocumentoJuridico;
use App\Inteligencia\Enum\DocumentoJuridico;

/** Uma versão ajustada enviada: o nome original, quem enviou, quando e a partir de qual questão (título do catálogo). */
final readonly class VersaoDeDocumentoJuridicoOutput
{
    public function __construct(
        public int $id,
        public DocumentoJuridico $documento,
        public ?string $questao,
        public ?string $questaoTitulo,
        public string $nomeOriginal,
        public int $tamanhoBytes,
        public string $enviadaPor,
        public \DateTimeImmutable $enviadaEm,
    ) {
    }

    public static function daLinha(VersaoDeDocumentoJuridico $v, ?string $tituloDaQuestao): self
    {
        return new self(
            (int) $v->getId(),
            $v->getDocumento(),
            $v->getQuestao(),
            $tituloDaQuestao,
            $v->getNomeOriginal(),
            $v->getTamanhoBytes(),
            $v->getEnviadaPorNome(),
            $v->getEnviadaEm(),
        );
    }
}
