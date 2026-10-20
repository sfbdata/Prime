<?php

declare(strict_types=1);

namespace App\Pasta\Service;

/**
 * Os textos do envio da Análise IA ao cliente (desenho `mailCorpo` e `mailIAVals`, dc L.5236-5247):
 * o assunto e a mensagem padrão do modal, e o HTML do miolo do e-mail a partir do texto revisado.
 * Puro, sem dependência: a tela e o UseCase usam o mesmo texto.
 */
final class TextoDoEnvioAoCliente
{
    /** Rótulo de cada tipo de ponto no corpo (dc L.5237 `PT`); tipo fora da lista vira "Ponto". */
    private const ROTULOS = ['prazo' => 'Prazo', 'atencao' => 'Atenção', 'providencia' => 'Próximo passo', 'info' => 'Informação'];

    public static function assunto(string $numeroDoProcesso): string
    {
        return trim('Atualização do seu processo ' . $numeroDoProcesso);
    }

    /**
     * A mensagem padrão do modal (dc L.5238-5239), com o primeiro nome do cliente.
     *
     * @param list<array{tipo: string, texto: string}> $pontos
     */
    public static function corpo(?string $nomeDoCliente, string $numeroDoProcesso, string $resumo, array $pontos): string
    {
        $nome = self::primeiroNome($nomeDoCliente);
        $blocos = [
            'Olá' . ($nome !== '' ? ', ' . $nome : '') . '.',
            'Segue uma atualização sobre o seu processo ' . $numeroDoProcesso . '.',
            $resumo,
            implode("\n", array_map(static fn (array $p): string => (self::ROTULOS[$p['tipo']] ?? 'Ponto') . ': ' . $p['texto'], $pontos)),
            'Qualquer dúvida, estamos à disposição.',
            'Este resumo foi revisado pelo escritório e não substitui a orientação do seu advogado.',
        ];

        return implode("\n\n", array_values(array_filter($blocos, static fn (string $b): bool => trim($b) !== '')));
    }

    /** O miolo do e-mail: o texto escapado, um parágrafo por bloco e quebra de linha dentro dele. */
    public static function html(string $texto): string
    {
        $texto = str_replace(["\r\n", "\r"], "\n", trim($texto));
        $paragrafos = preg_split('/\n{2,}/', $texto) ?: [];

        return implode('', array_map(
            static fn (string $p): string => '<p style="margin:0 0 14px;">' . nl2br(htmlspecialchars($p, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), false) . '</p>',
            $paragrafos,
        ));
    }

    /** "Maria" de "MARIA DA SILVA" (dc L.5237-5238: primeira palavra, só a inicial maiúscula). */
    public static function primeiroNome(?string $nome): string
    {
        $primeiro = explode(' ', trim((string) $nome))[0];

        return $primeiro === '' ? '' : mb_strtoupper(mb_substr($primeiro, 0, 1)) . mb_strtolower(mb_substr($primeiro, 1));
    }

    /** "Maria da" de "Maria da Silva": os dois primeiros nomes do vazio do cartão (dc L.5260). */
    public static function doisPrimeirosNomes(?string $nome): string
    {
        $partes = preg_split('/\s+/', trim((string) $nome)) ?: [];

        return implode(' ', \array_slice(array_filter($partes, static fn (string $p): bool => $p !== ''), 0, 2));
    }
}
