<?php

declare(strict_types=1);

namespace App\Shared\Service;

/**
 * Confere os segredos de produção antes do go-live (auditoria 4.11): o APP_SECRET assina o HMAC do código de troca
 * de senha, a referência de recarga, o link rastreado, o descadastro e o lembrar-me; as chaves de cifra guardam os
 * tokens do sync e o certificado do banco do Contribua. Na Assinatura de documentos, o segredo do link e do código, a
 * chave que cifra a selfie da Reforçada, o segredo do código de validação do selo e o token do validador de PDF
 * (este, vazio, é aviso e não problema). Nenhum problema nem aviso devolvido traz o valor conferido.
 */
final class ConferenteDeSegredos
{
    public const TAMANHO_MINIMO_DO_APP_SECRET = 32;

    /** A mesma conta do `ChaveDaAssinatura::configurada()` e do `ChaveDoSelo::configurada()` (o Shared não depende do domínio). */
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

    /**
     * O segredo do código de validação do selo da Assinatura (etapa 3), com a regra de quem o usa (`ChaveDoSelo`): ao
     * menos 32 caracteres sem contar espaço e quebra de linha das pontas. Nunca igual ao do modelo, ao
     * `ASSINATURA_SEGREDO` nem ao `APP_SECRET` (segredo próprio).
     *
     * @param string $modelo conteúdo do .env.prod.example, só para comparar (nunca é devolvido)
     *
     * @return list<string> os problemas achados, sem os valores; vazio quando está tudo certo
     */
    public function conferirSelo(
        #[\SensitiveParameter] string $segredoDoSelo,
        #[\SensitiveParameter] string $segredoDaAssinatura,
        #[\SensitiveParameter] string $appSecret,
        string $modelo,
    ): array {
        if (\strlen(trim($segredoDoSelo)) < self::TAMANHO_MINIMO_DO_SEGREDO_DA_ASSINATURA) {
            return [sprintf('ASSINATURA_SELO_SEGREDO ausente ou com menos de %d caracteres: o envio de documentos fica recusado. Siga docs/runbooks/assinatura-selo-segredo-em-producao.md.', self::TAMANHO_MINIMO_DO_SEGREDO_DA_ASSINATURA)];
        }
        if (self::igualAoModelo($segredoDoSelo, self::valoresDoModelo($modelo)['ASSINATURA_SELO_SEGREDO'] ?? '')) {
            return ['ASSINATURA_SELO_SEGREDO é o mesmo do .env.prod.example (público no repositório). Gere outro.'];
        }
        if (hash_equals($segredoDaAssinatura, $segredoDoSelo) || hash_equals($appSecret, $segredoDoSelo)) {
            return ['ASSINATURA_SELO_SEGREDO repete o ASSINATURA_SEGREDO ou o APP_SECRET: precisa ser próprio. Gere outro.'];
        }

        return [];
    }

    /**
     * O token do validador de PDF da Assinatura (etapa 6): vai no cabeçalho `Authorization: Bearer` do php e do worker
     * e fica no ambiente do contêiner `validador_pdf`. Vazio é um estado válido (E6-4: o validador recusa toda chamada,
     * e os níveis gov.br e ICP-Brasil e o selo do PDF final ficam desligados): não é problema, é aviso
     * (`avisosDoValidador()`). Preenchido, precisa de ao menos 32 caracteres, só os do token Bearer (RFC 6750: letras,
     * números e `-._~+/`, com `=` só no fim) e nada antes nem depois, porque o cabeçalho não leva espaço nem quebra de
     * linha. Nunca igual ao do modelo nem a nenhum dos outros segredos que este comando confere (`APP_SECRET`,
     * `SYNC_CRYPTO_KEY`, `CONTRIBUA_CRYPTO_KEY`, `ASSINATURA_SEGREDO`, `ASSINATURA_SELFIE_CHAVE`,
     * `ASSINATURA_SELO_SEGREDO`): o contêiner do validador não recebe os outros segredos do BlueJus.
     *
     * @param array<string, string> $outrosSegredos NOME => valor dos outros segredos, só para comparar (nunca é devolvido)
     * @param string                $modelo         conteúdo do .env.prod.example, só para comparar (nunca é devolvido)
     *
     * @return list<string> os problemas achados, sem os valores; vazio quando está tudo certo ou o token está vazio
     */
    public function conferirValidador(
        #[\SensitiveParameter] string $tokenDoValidador,
        #[\SensitiveParameter] array $outrosSegredos,
        string $modelo,
    ): array {
        if (trim($tokenDoValidador) === '') {
            return [];
        }
        if (preg_match('#^[A-Za-z0-9\-._~+/]+=*\z#', $tokenDoValidador) !== 1) {
            return ['ASSINATURA_VALIDADOR_TOKEN fora do formato: só letras, números e -._~+/ (com = só no fim), sem espaço nem quebra de linha. Gere outro com: openssl rand -hex 32'];
        }
        if (\strlen($tokenDoValidador) < self::TAMANHO_MINIMO_DO_SEGREDO_DA_ASSINATURA) {
            return [sprintf('ASSINATURA_VALIDADOR_TOKEN com menos de %d caracteres. Gere outro com: openssl rand -hex 32', self::TAMANHO_MINIMO_DO_SEGREDO_DA_ASSINATURA)];
        }
        if (self::igualAoModelo($tokenDoValidador, self::valoresDoModelo($modelo)['ASSINATURA_VALIDADOR_TOKEN'] ?? '')) {
            return ['ASSINATURA_VALIDADOR_TOKEN é o mesmo do .env.prod.example (público no repositório). Gere outro.'];
        }
        foreach ($outrosSegredos as $nome => $outro) {
            if (hash_equals($outro, $tokenDoValidador)) {
                return [sprintf('ASSINATURA_VALIDADOR_TOKEN repete o valor de %s: precisa ser próprio. Gere outro com: openssl rand -hex 32', $nome)];
            }
        }

        return [];
    }

    /**
     * O que não recusa os segredos, mas o operador precisa saber: o token do validador vazio (E6-4) deixa os níveis
     * gov.br e ICP-Brasil e o selo do PDF final desligados. Não muda o código de saída do comando.
     *
     * @return list<string> os avisos, sem valores; vazio quando não há o que avisar
     */
    public function avisosDoValidador(#[\SensitiveParameter] string $tokenDoValidador): array
    {
        if (trim($tokenDoValidador) !== '') {
            return [];
        }

        return ['ASSINATURA_VALIDADOR_TOKEN não está definido: o validador de PDF recusa toda chamada, e os níveis gov.br e ICP-Brasil e o selo do PDF final ficam desligados. Para ligar, siga docs/runbooks/assinatura-validador-em-producao.md.'];
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
