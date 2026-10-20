<?php

declare(strict_types=1);

namespace App\Pasta\Service;

use App\Pasta\Repository\LinkExternoDoPushRepository;

/**
 * Endereço legível do link externo do Push: `bluejus-<slug do cliente>-<8 aleatórios>` ([LNK] 17-19).
 *
 * O slug é o do desenho: sem acento, minúsculas, o que não for letra ou dígito vira hífen, até 4
 * palavras e 40 caracteres. Sem nome de cliente (ou com um nome que não sobra nada), o slug é
 * `processo-<7 primeiros dígitos do processo>`.
 *
 * Os 8 caracteres são o que protege o link (o slug é adivinhável): vêm de `random_bytes`, no
 * alfabeto sem 0/1/i/l/o (não se confundem ao ditar), por rejeição para não enviesar. São 31^8
 * (~8,5 * 10^11) combinações por slug. Colisão com endereço existente sorteia de novo.
 */
final class GeradorDeEnderecoDoLinkExterno
{
    public const ALFABETO = '23456789abcdefghjkmnpqrstuvwxyz';
    public const TAMANHO_ALEATORIO = 8;
    private const TENTATIVAS = 10;

    public function __construct(
        private readonly LinkExternoDoPushRepository $links,
    ) {
    }

    public function gerar(?string $nomeDoCliente, string $numeroDoProcesso): string
    {
        $prefixo = 'bluejus-' . self::identificacao($nomeDoCliente, $numeroDoProcesso) . '-';

        for ($i = 0; $i < self::TENTATIVAS; ++$i) {
            $endereco = $prefixo . self::aleatorio();
            if (!$this->links->enderecoEmUso($endereco)) {
                return $endereco;
            }
        }

        throw new \RuntimeException('Não foi possível gerar um endereço livre para o link externo.');
    }

    /** Código de acesso de 6 dígitos, enviado ao terceiro separado do link. */
    public function gerarCodigo(): string
    {
        return (string) random_int(100000, 999999);
    }

    public static function identificacao(?string $nomeDoCliente, string $numeroDoProcesso): string
    {
        $slug = self::slug((string) $nomeDoCliente);
        if ($slug !== '') {
            return $slug;
        }

        return 'processo-' . substr((string) preg_replace('/\D+/', '', $numeroDoProcesso), 0, 7);
    }

    public static function slug(string $texto): string
    {
        $decomposto = \Normalizer::normalize($texto, \Normalizer::FORM_D);
        $semAcento = (string) preg_replace('/\p{Mn}+/u', '', $decomposto === false ? $texto : $decomposto);
        $hifenizado = trim((string) preg_replace('/[^a-z0-9]+/', '-', mb_strtolower($semAcento)), '-');
        $palavras = array_slice(explode('-', $hifenizado), 0, 4);

        return trim(substr(implode('-', $palavras), 0, 40), '-');
    }

    public static function aleatorio(): string
    {
        $tamanhoDoAlfabeto = strlen(self::ALFABETO);
        $teto = intdiv(256, $tamanhoDoAlfabeto) * $tamanhoDoAlfabeto;
        $saida = '';

        while (strlen($saida) < self::TAMANHO_ALEATORIO) {
            foreach (str_split(random_bytes(16)) as $byte) {
                $valor = ord($byte);
                if ($valor < $teto && strlen($saida) < self::TAMANHO_ALEATORIO) {
                    $saida .= self::ALFABETO[$valor % $tamanhoDoAlfabeto];
                }
            }
        }

        return $saida;
    }
}
