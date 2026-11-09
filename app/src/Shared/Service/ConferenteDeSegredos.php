<?php

declare(strict_types=1);

namespace App\Shared\Service;

/**
 * Confere os segredos de produção antes do go-live (auditoria 4.11): o APP_SECRET assina o HMAC do código de troca
 * de senha, a referência de recarga, o link rastreado, o descadastro e o lembrar-me; as chaves de cifra guardam os
 * tokens do sync e o certificado do banco do Contribua. Na Assinatura de documentos, o segredo do link e do código e a
 * chave que cifra a selfie da Reforçada. Nenhum problema devolvido traz o valor conferido.
 */
final class ConferenteDeSegredos
{
    public const TAMANHO_MINIMO_DO_APP_SECRET = 32;

    /** A mesma conta do `ChaveDaAssinatura::configurada()` (o Shared não depende do domínio). */
    public const TAMANHO_MINIMO_DO_SEGREDO_DA_ASSINATURA = 32;

    /**
     * @param string $modelo conteúdo do .env.prod.example, só para comparar (nunca é devolvido)
     *
     * @return list<string> os problemas achados, em texto para o operador; vazio quando está tudo certo
     */
    public function conferir(string $appSecret, string $chaveDoSync, string $chaveDoContribua, string $modelo): array
    {
        $doModelo = self::valoresDoModelo($modelo);
        $problemas = [];

        if (mb_strlen($appSecret) < self::TAMANHO_MINIMO_DO_APP_SECRET) {
            $problemas[] = sprintf('APP_SECRET tem menos de %d caracteres. Gere outro com: php -r \'echo bin2hex(random_bytes(32));\'', self::TAMANHO_MINIMO_DO_APP_SECRET);
        } elseif (self::igualAoModelo($appSecret, $doModelo['APP_SECRET'] ?? '')) {
            $problemas[] = 'APP_SECRET é o mesmo do .env.prod.example (público no repositório). Gere outro.';
        }

        foreach (['SYNC_CRYPTO_KEY' => $chaveDoSync, 'CONTRIBUA_CRYPTO_KEY' => $chaveDoContribua] as $nome => $chave) {
            $problema = self::problemaDaChaveDeCifra($nome, $chave, $doModelo[$nome] ?? '');
            if ($problema !== null) {
                $problemas[] = $problema;
            }
        }

        return $problemas;
    }

    /**
     * Os segredos da Assinatura de documentos, com as mesmas regras de quem os usa: o `ASSINATURA_SEGREDO` com ao
     * menos 32 caracteres sem contar espaço e quebra de linha das pontas (`ChaveDaAssinatura`); a
     * `ASSINATURA_SELFIE_CHAVE` com 64 hexadecimais e nada antes nem depois (`CofreDaSelfie`). Nenhum dos dois igual ao
     * do modelo.
     *
     * @param string $modelo conteúdo do .env.prod.example, só para comparar (nunca é devolvido)
     *
     * @return list<string> os problemas achados, sem os valores; vazio quando está tudo certo
     */
    public function conferirAssinatura(
        #[\SensitiveParameter] string $segredo,
        #[\SensitiveParameter] string $chaveDaSelfie,
        string $modelo,
    ): array {
        $doModelo = self::valoresDoModelo($modelo);
        $problemas = [];

        if (\strlen(trim($segredo)) < self::TAMANHO_MINIMO_DO_SEGREDO_DA_ASSINATURA) {
            $problemas[] = sprintf('ASSINATURA_SEGREDO ausente ou com menos de %d caracteres. Siga docs/runbooks/assinatura-segredo-em-producao.md.', self::TAMANHO_MINIMO_DO_SEGREDO_DA_ASSINATURA);
        } elseif (self::igualAoModelo($segredo, $doModelo['ASSINATURA_SEGREDO'] ?? '')) {
            $problemas[] = 'ASSINATURA_SEGREDO é o mesmo do .env.prod.example (público no repositório). Gere outro.';
        }

        if ($chaveDaSelfie === '') {
            $problemas[] = 'ASSINATURA_SELFIE_CHAVE não está definida: a assinatura Reforçada fica recusada. Siga docs/runbooks/assinatura-selfie-chave-em-producao.md.';
        } elseif (preg_match('/^[0-9a-fA-F]{64}\z/', $chaveDaSelfie) !== 1) {
            $problemas[] = 'ASSINATURA_SELFIE_CHAVE fora do formato: precisa de 64 caracteres hexadecimais, sem espaço nem quebra de linha.';
        } elseif (self::igualAoModelo($chaveDaSelfie, $doModelo['ASSINATURA_SELFIE_CHAVE'] ?? '')) {
            $problemas[] = 'ASSINATURA_SELFIE_CHAVE é a mesma do .env.prod.example (pública no repositório). Gere outra.';
        }

        return $problemas;
    }

    private static function problemaDaChaveDeCifra(string $nome, string $chave, string $doModelo): ?string
    {
        // Mesma decodificação do CifradorDeSegredo e do CofreDeCertificado: base64 estrito de 32 bytes.
        $bytes = base64_decode($chave, true);
        if ($bytes === false || strlen($bytes) !== \SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            return sprintf('%s não decodifica para %d bytes (base64). Gere outra com: php -r \'echo base64_encode(random_bytes(32));\'', $nome, \SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
        }

        if (self::igualAoModelo($chave, $doModelo)) {
            return sprintf('%s é a mesma do .env.prod.example (pública no repositório). Gere outra.', $nome);
        }

        return null;
    }

    private static function igualAoModelo(string $valor, string $doModelo): bool
    {
        return $doModelo !== '' && hash_equals($doModelo, $valor);
    }

    /** @return array<string, string> NOME => valor, sem aspas em volta */
    private static function valoresDoModelo(string $modelo): array
    {
        $valores = [];
        foreach (preg_split('/\R/', $modelo) ?: [] as $linha) {
            if (preg_match('/^\s*(?:export\s+)?([A-Z0-9_]+)\s*=(.*)$/', $linha, $m) !== 1) {
                continue;
            }
            $valor = trim($m[2]);
            if (strlen($valor) >= 2 && ($valor[0] === '"' || $valor[0] === "'") && $valor[-1] === $valor[0]) {
                $valor = substr($valor, 1, -1);
            }
            $valores[$m[1]] ??= $valor;
        }

        return $valores;
    }
}
