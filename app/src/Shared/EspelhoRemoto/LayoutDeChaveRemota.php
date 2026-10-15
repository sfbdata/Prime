<?php

declare(strict_types=1);

namespace App\Shared\EspelhoRemoto;

use App\Shared\Armazenamento\CategoriaDeArquivo;
use App\Shared\Armazenamento\ChaveDeArquivo;
use App\Shared\Armazenamento\Exception\ChaveDeArquivoInvalida;

/**
 * A chave do objeto no R2 para uma {@see ChaveDeArquivo} — função **pura** (spec P2 §2, DP-1).
 *
 * ```
 * core/t/{tenant}/uploads/{caminho-original}    escritório
 * core/g/uploads/{caminho-original}             global (só a foto de perfil)
 * ```
 *
 * `{caminho-original}` é **exatamente** o caminho relativo do arquivo no volume `uploads_prod`
 * (o que o `ResolvedorDeCaminhoLocal` monta abaixo de `public/uploads/`). Por isso o tenant aparece
 * duas vezes nas duas categorias que já isolam o escritório em disco — `pastas/{t}/` e
 * `cobrancas/{t}/`: o espelho fica conferível linha a linha contra o inventário da P0.
 * `MapaDeChaveRemotaTest` prova a igualdade categoria a categoria.
 *
 * ## O nome vai byte a byte (DP-8)
 *
 * Sem `trim()`, sem normalização Unicode, sem escapar `／`, sem tocar no ponto final nem nas
 * maiúsculas. A única transformação é a de transporte (percent-encoding da URL), que é do cliente
 * S3. O `‛／` que o rclone gravou na P0 é justamente o que esta classe NÃO faz.
 *
 * ## O tenant vem do escopo da chave (R1)
 *
 * Nunca do request, nunca do caminho. No disco local o tenant errado é invisível; aqui ele vira
 * outro prefixo — por isso escopo e categoria têm de concordar, e a discordância é recusada.
 */
final class LayoutDeChaveRemota
{
    /** O prefixo do BlueJus Core no bucket. Outros sistemas BlueJus terão o seu. */
    public const PREFIXO = 'core/';

    /** Teto do S3 para a chave do objeto, em bytes. */
    public const TAMANHO_MAXIMO_EM_BYTES = 1024;

    private function __construct()
    {
    }

    /**
     * @throws ChaveDeArquivoInvalida quando escopo e categoria não concordam ou a chave passaria do teto
     */
    public static function chaveDe(ChaveDeArquivo $chave): string
    {
        $categoria = $chave->categoria;

        if ($categoria === CategoriaDeArquivo::FOTO_PERFIL) {
            if (!$chave->escopo->ehGlobal()) {
                throw new ChaveDeArquivoInvalida('A foto de perfil é global (D1); escopo de escritório não tem chave remota.');
            }

            return self::limitada(self::PREFIXO . 'g/uploads/perfil/' . $chave->nome);
        }

        $tenant = $chave->escopo->tenantIdOuNull();
        if ($tenant === null) {
            throw new ChaveDeArquivoInvalida(sprintf(
                'A categoria %s pertence a um escritório; escopo global não tem chave remota.',
                $categoria->value,
            ));
        }

        return self::limitada(sprintf(
            '%st/%d/uploads/%s/%s',
            self::PREFIXO,
            $tenant,
            self::diretorioOriginal($categoria, $tenant),
            $chave->nome,
        ));
    }

    /** O diretório abaixo de `public/uploads/` onde a categoria mora hoje no volume. */
    private static function diretorioOriginal(CategoriaDeArquivo $categoria, int $tenant): string
    {
        return match ($categoria) {
            CategoriaDeArquivo::PASTA_DOCUMENTO     => 'pastas',
            CategoriaDeArquivo::PASTA_IMAGEM_EDITOR => 'pastas/' . $tenant,
            CategoriaDeArquivo::CLIENTE_DOCUMENTO   => 'clientes',
            CategoriaDeArquivo::CHAMADO_ANEXO       => 'chamados',
            CategoriaDeArquivo::JUSTIFICATIVA_ANEXO => 'justificativas',
            CategoriaDeArquivo::COBRANCA_DOCUMENTO  => 'cobrancas/' . $tenant,
            CategoriaDeArquivo::KANBAN_ANEXO        => 'kanban',
            CategoriaDeArquivo::TAREFA_ANEXO        => 'tarefas/chat',
            CategoriaDeArquivo::FOTO_PERFIL         => throw new \LogicException('global, tratada antes'),
        };
    }

    private static function limitada(string $chaveRemota): string
    {
        if (\strlen($chaveRemota) > self::TAMANHO_MAXIMO_EM_BYTES) {
            throw new ChaveDeArquivoInvalida(sprintf(
                'A chave remota teria %d bytes; o teto do S3 é %d.',
                \strlen($chaveRemota),
                self::TAMANHO_MAXIMO_EM_BYTES,
            ));
        }

        return $chaveRemota;
    }
}
