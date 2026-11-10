<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Pdf;

/**
 * Tudo o que o `pdf(d, modo)` do desenho lê (dc:392-427), já resolvido por quem chama: o montador não abre banco
 * nem armazenamento.
 *
 * - `codigo` nulo = rascunho: a folha imprime "RASCUNHO" no rodapé, na marca d'água e no selo.
 * - `hash` é o SHA-256 em hexadecimal (o do envio; no rascunho, o provisório): semente do fundo e texto do rodapé.
 * - `blocos` no formato de `DocumentoParaAssinar::getBlocos()` ({@see \App\AssinaturaEletronica\Service\ValidadorDeBlocos}):
 *   `t`, `s`, `h`, `l`, `p`, `d`, `n` com `x` e `full`; `a` com `i` (índice em `signatarios`) e `r`; `pg` com `n`.
 * - `logo` e `rubrica` em bytes (PNG, JPEG ou GIF); logo nula ou ilegível cai na marca "BlueJus" em texto.
 * - `urlDeValidacao` é o endereço absoluto que vai no QR (E3-14), já com o código.
 * - `pdfEnviado` são os bytes do PDF do documento por upload (as páginas dos blocos `pg`).
 * - `paginasDepois` são as páginas depois do conteúdo (o manifesto). Folha branca que também é rascunho (sem
 *   `codigo`) não leva manifesto (`if (!(d.limpa && !d.cod))`, dc:417): nesse caso quem monta a folha passa
 *   {@see SemPaginasDepoisDoConteudo}. Só folha branca, ou só rascunho, leva o manifesto.
 */
final readonly class FolhaParaMontar
{
    /**
     * @param list<array<string, int|string>> $blocos
     * @param list<SignatarioDaFolha>          $signatarios
     */
    public function __construct(
        public ModoDaFolha $modo,
        public ?string $codigo,
        public string $hash,
        public string $nome,
        public array $blocos,
        public LayoutDaFolha $layout,
        public ?string $lateral,
        public bool $folhaBranca,
        public ?string $logo,
        public EscritorioDaFolha $escritorio,
        public array $signatarios,
        public bool $concluido,
        public \DateTimeImmutable $emitidoEm,
        public string $urlDeValidacao,
        public ?string $pdfEnviado = null,
        public PaginasDepoisDoConteudo $paginasDepois = new SemPaginasDepoisDoConteudo(),
    ) {
        if (preg_match('/^[0-9a-f]{64}$/', $hash) !== 1) {
            throw new \InvalidArgumentException('O hash da folha precisa ser o SHA-256 em hexadecimal minúsculo.');
        }
    }

    /** `cod = d.cod || 'RASCUNHO'` (dc:392). */
    public function codigoImpresso(): string
    {
        return $this->codigo ?? TextosDaFolha::RASCUNHO;
    }

    public function duasColunas(): bool
    {
        return $this->layout === LayoutDaFolha::DuasColunas;
    }

    public function signatario(int $indice): ?SignatarioDaFolha
    {
        return $this->signatarios[$indice] ?? null;
    }
}
