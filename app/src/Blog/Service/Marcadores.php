<?php

declare(strict_types=1);

namespace App\Blog\Service;

use Symfony\Component\String\Slugger\AsciiSlugger;

/**
 * Marcadores do artigo (spec painel-artigos-blogs, seção 11): etiquetas livres, até 8 por artigo e 40 caracteres cada.
 * O slug sai do texto (sem acento, minúsculas, hífen) e não é gravado. Dois marcadores com o mesmo slug contam como um:
 * fica a primeira grafia. O mesmo contrato vale no zod do repositório dos sites.
 */
final class Marcadores
{
    public const MAXIMO = 8;
    public const TAMANHO_MAXIMO = 40;

    public static function slug(string $marcador): string
    {
        return strtolower((string) (new AsciiSlugger('pt'))->slug(trim($marcador)));
    }

    /**
     * Limpa o que veio do editor: tira espaço das pontas e duplo no meio, descarta vazio e repetido (pelo slug).
     * Não corta nem recusa nada: quem confere os limites é {@see self::erros()}.
     *
     * @param array<mixed> $marcadores
     *
     * @return list<string>
     */
    public static function normalizar(array $marcadores): array
    {
        $saida = [];
        $vistos = [];
        foreach ($marcadores as $marcador) {
            if (!\is_string($marcador)) {
                continue;
            }
            $texto = trim((string) preg_replace('/\s+/u', ' ', $marcador));
            $slug = self::slug($texto);
            if ($texto === '' || isset($vistos[$slug])) {
                continue;
            }
            $vistos[$slug] = true;
            $saida[] = $texto;
        }

        return $saida;
    }

    /**
     * @param list<string> $marcadores já normalizados
     *
     * @return list<string> mensagens em português, uma por problema
     */
    public static function erros(array $marcadores): array
    {
        $erros = [];
        if (\count($marcadores) > self::MAXIMO) {
            $erros[] = sprintf('Use até %d marcadores.', self::MAXIMO);
        }
        foreach ($marcadores as $marcador) {
            if (mb_strlen($marcador) > self::TAMANHO_MAXIMO) {
                $erros[] = sprintf('O marcador "%s" passa de %d caracteres.', $marcador, self::TAMANHO_MAXIMO);
            } elseif (self::slug($marcador) === '') {
                $erros[] = sprintf('O marcador "%s" precisa ter letras ou números.', $marcador);
            }
        }

        return $erros;
    }
}
