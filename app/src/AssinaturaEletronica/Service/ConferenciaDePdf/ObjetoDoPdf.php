<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Service\ConferenciaDePdf;

/**
 * Interna do motor: um objeto do PDF lido para a análise do acréscimo (M27). `dicionario` é null quando o objeto não é
 * um dicionário (número, vetor, texto); aí `conteudo` traz o valor, se deu para ler. Os valores vêm normalizados
 * (`LeitorDeSintaxeDoPdf`). Em fluxo, os dados ficam entre `inicioDosDados` e `fimDosDados` do arquivo; objeto de
 * dentro de um /ObjStm não é fluxo. `depoisDoObjeto` é a posição depois do `endobj` (zero dentro de um /ObjStm).
 */
final readonly class ObjetoDoPdf
{
    /**
     * @param array<string, string>|null $dicionario
     */
    public function __construct(
        public int $numero,
        public int $geracao,
        public ?array $dicionario,
        public bool $fluxo = false,
        public int $inicioDosDados = 0,
        public int $fimDosDados = 0,
        public ?string $conteudo = null,
        public int $depoisDoObjeto = 0,
    ) {
    }

    public function valor(string $chave): ?string
    {
        return $this->dicionario[$chave] ?? null;
    }

    public function tipo(): ?string
    {
        return $this->valor('/Type');
    }
}
