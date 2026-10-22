<?php

declare(strict_types=1);

namespace App\Pasta\DTO;

use App\Processo\Entity\Processo;

/**
 * Um item da lista "Processo já cadastrado" da janela "Vincular processo" (desenho 02, N:299-302 e
 * `procCatalogo` N:4513-4518): o número, a linha "classe · ação · tribunal" e a ORIGEM do processo no
 * escritório. A origem é estruturada (`pasta`, `apensado` ou `push`, com o número da pasta); o texto da
 * tela ("Pasta N", "Apensado · pasta N", "Push processual") sai do catálogo no JS. Sem permissão de ver a pasta
 * de origem, `pasta` vem nulo e a tela diz "Outra pasta".
 */
final readonly class ProcessoParaVincularOutput
{
    public const ORIGEM_PASTA = 'pasta';
    public const ORIGEM_APENSADO = 'apensado';
    public const ORIGEM_PUSH = 'push';

    public function __construct(
        public int $id,
        public string $numero,
        public string $classe,
        public string $acao,
        public string $tribunal,
        public string $origem,
        public ?string $pasta,
    ) {
    }

    public static function de(Processo $processo, string $origem, ?string $pasta): self
    {
        return new self(
            (int) $processo->getId(),
            $processo->getNumeroProcesso(),
            trim($processo->getClasseProcessual()),
            trim($processo->getAssuntoProcessual()),
            trim($processo->getSiglaTribunal()),
            $origem,
            $pasta,
        );
    }

    /**
     * `text` fica para quem lê o rótulo pronto (número · classe · tribunal), como o Select2 de antes.
     *
     * @return array{id: int, text: string, numero: string, classe: string, acao: string, tribunal: string, origem: array{tipo: string, pasta: ?string}}
     */
    public function paraJson(): array
    {
        return [
            'id'       => $this->id,
            'text'     => implode(' · ', array_filter([$this->numero, $this->classe !== '' ? $this->classe : $this->acao, $this->tribunal])),
            'numero'   => $this->numero,
            'classe'   => $this->classe,
            'acao'     => $this->acao,
            'tribunal' => $this->tribunal,
            'origem'   => ['tipo' => $this->origem, 'pasta' => $this->pasta],
        ];
    }
}
