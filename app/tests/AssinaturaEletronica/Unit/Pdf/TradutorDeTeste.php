<?php

declare(strict_types=1);

namespace App\Tests\AssinaturaEletronica\Unit\Pdf;

use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Tradutor dos testes dos textos do PDF: anota cada chave pedida do bloco `pdf:` (sem o prefixo), com os parâmetros.
 *
 * - Chave em `trocas`: o texto trocado, no mesmo formato ICU do catálogo.
 * - Com o tradutor de verdade: a tradução dele.
 * - Sem ele (eco): "[chave nome=valor ...]", que mostra de onde cada texto saiu.
 */
final class TradutorDeTeste implements TranslatorInterface
{
    public const PREFIXO = 'assinatura_eletronica_tela.js.pdf.';

    /** @var array<string, array<string, mixed>> */
    public array $pedidos = [];

    /** @param array<string, string> $trocas chave do bloco `pdf:` => texto ICU */
    public function __construct(
        private readonly ?TranslatorInterface $real = null,
        private readonly array $trocas = [],
    ) {
    }

    public function trans(string $id, array $parameters = [], ?string $domain = null, ?string $locale = null): string
    {
        $chave = str_starts_with($id, self::PREFIXO) ? substr($id, \strlen(self::PREFIXO)) : $id;
        $this->pedidos[$chave] = $parameters;

        if (isset($this->trocas[$chave])) {
            return (string) \MessageFormatter::formatMessage('pt_BR', $this->trocas[$chave], $parameters);
        }
        if ($this->real !== null) {
            return $this->real->trans($id, $parameters, $domain, $locale);
        }

        $partes = [$chave];
        foreach ($parameters as $nome => $valor) {
            $partes[] = $nome . '=' . $valor;
        }

        return '[' . implode(' ', $partes) . ']';
    }

    public function getLocale(): string
    {
        return 'pt_BR';
    }
}
