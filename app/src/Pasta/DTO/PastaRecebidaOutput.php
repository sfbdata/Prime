<?php

declare(strict_types=1);

namespace App\Pasta\DTO;

/**
 * A pasta recebida de outra conta, como a página reduzida e a janela L11 a mostram (spec acesso-restrito-e-compartilhar
 * §4.5.1). Só dados: nada leva a outra tela da conta de origem (sem cliente, processo, cobrança, push, marcadores,
 * responsável nem vizinhas). As listas da aba oculta chegam vazias e com `abaX = false`.
 *
 * `andamentos`: `{autor, em, texto (HTML do editor rico, já sanitizado), sistema (bool), resposta (bool)}`;
 * `documentos`: `{id, nome, em, tamanho}`; `metas`: `{titulo, prazo, situacao}`.
 */
final readonly class PastaRecebidaOutput
{
    /**
     * @param list<array{autor: string, em: \DateTimeImmutable, texto: string, sistema: bool, resposta: bool}> $andamentos
     * @param list<array{id: int, nome: string, em: \DateTimeImmutable, tamanho: int}>                        $documentos
     * @param list<array{titulo: string, prazo: ?\DateTimeImmutable, situacao: string}>                       $metas
     */
    public function __construct(
        public int $compartilhamentoId,
        public string $nup,
        public string $cliente,
        public string $acao,
        public string $fase,
        public string $de,
        public string $conta,
        public bool $movimentar,
        public bool $enviarDocumentos,
        public bool $baixarDocumentos,
        public bool $soLeitura,
        public bool $abaAndamentos,
        public bool $abaDocumentos,
        public bool $abaMetas,
        public array $andamentos,
        public array $documentos,
        public array $metas,
    ) {
    }

    /** A primeira aba visível (a página abre nela), ou `null` sem nenhuma. */
    public function primeiraAba(): ?string
    {
        return match (true) {
            $this->abaAndamentos => 'andamentos',
            $this->abaDocumentos => 'documentos',
            $this->abaMetas      => 'metas',
            default              => null,
        };
    }
}
