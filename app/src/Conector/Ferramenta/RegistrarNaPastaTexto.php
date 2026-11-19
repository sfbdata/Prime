<?php

declare(strict_types=1);

namespace App\Conector\Ferramenta;

use App\Entity\Auth\User;
use App\Pasta\Service\MencoesDoRegistro;

/**
 * O texto da `registrar_na_pasta` nos dois sentidos (`docs/specs/mcp-remoto-oauth.md`, 6.6):
 *  - {@see paraHtml()}: o texto puro do assistente vira o HTML do editor da tela, um parágrafo por linha, escapado (um
 *    "valor < 100" não vira marcação nem some no sanitizador). Marcação de menção escrita no texto (`@[Nome](user:ID)`)
 *    vira texto comum: menção só entra pelos ids de `mencoes`, já conferidos como colegas ativos, no começo do texto;
 *  - {@see textoPlano()}: o HTML gravado vira texto puro, com as quebras de parágrafo e cada menção como "@Nome", antes de
 *    passar pela {@see \App\ChatIa\Service\ProtecaoDoTextoDoChat} (6.2, regra 6).
 */
final class RegistrarNaPastaTexto
{
    private const ESCAPE = \ENT_QUOTES | \ENT_HTML5 | \ENT_SUBSTITUTE;

    /**
     * @param list<User> $mencionados colegas ativos do escritório, já conferidos
     */
    public static function paraHtml(string $texto, array $mencionados): string
    {
        $texto = self::semMarcacaoDeMencao(str_replace(["\r\n", "\r"], "\n", trim($texto)));
        $linhas = explode("\n", $texto);
        $marcas = array_map(
            static fn (User $colega): string => '@[' . MencoesDoRegistro::rotulo((string) $colega->getFullName()) . '](user:' . (int) $colega->getId() . ')',
            $mencionados,
        );
        if ($marcas !== []) {
            $linhas[0] = trim(implode(' ', $marcas) . ' ' . $linhas[0]);
        }

        return implode('', array_map(
            static fn (string $linha): string => trim($linha) === '' ? '<p><br></p>' : '<p>' . htmlspecialchars($linha, self::ESCAPE, 'UTF-8') . '</p>',
            $linhas,
        ));
    }

    /**
     * O texto como a pessoa leria no resumo da proposta: o que o assistente mandou, com as menções como "@Nome" no
     * começo (onde o {@see paraHtml()} as põe).
     *
     * @param list<string> $nomes os nomes dos mencionados, já protegidos por quem chama
     */
    public static function paraOResumo(string $texto, array $nomes): string
    {
        $marcas = array_map(static fn (string $nome): string => '@' . MencoesDoRegistro::rotulo($nome), $nomes);
        $texto = self::semMarcacaoDeMencao(str_replace(["\r\n", "\r"], "\n", trim($texto)));

        return $marcas === [] ? $texto : implode(' ', $marcas) . ' ' . $texto;
    }

    /** HTML do editor (ou texto do legado) para texto puro, com as quebras de parágrafo e as menções como "@Nome". */
    public static function textoPlano(string $html): string
    {
        $texto = (string) preg_replace('/<\s*(br|\/p|\/div|\/li|\/tr|\/h[1-6])\b[^>]*>/i', "\n", $html);
        $texto = html_entity_decode(strip_tags($texto), \ENT_QUOTES | \ENT_HTML5, 'UTF-8');

        return trim(self::semMarcacaoDeMencao($texto));
    }

    /** O tamanho do texto como o UseCase o conta (o visível, sem as pontas). */
    public static function tamanho(string $texto): int
    {
        return mb_strlen(trim($texto));
    }

    private static function semMarcacaoDeMencao(string $texto): string
    {
        return (string) preg_replace_callback(MencoesDoRegistro::PADRAO, static fn (array $m): string => '@' . $m[1], $texto);
    }
}
