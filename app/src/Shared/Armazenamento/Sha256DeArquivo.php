<?php

declare(strict_types=1);

namespace App\Shared\Armazenamento;

use App\Shared\Armazenamento\Exception\ArquivoNaoEncontrado;
use App\Shared\Armazenamento\Exception\FalhaDeArmazenamento;
use Psr\Log\LoggerInterface;

/**
 * SHA-256 de um conteúdo, sempre em streaming (INV-4): `hash_init` + `hash_update_stream` leem
 * em blocos e nunca carregam o arquivo inteiro em memória — vale para o vídeo de 98 MB do acervo
 * tanto quanto para o PDF de 2 KB.
 *
 * Quatro origens, porque quatro acontecem:
 *  - `deArquivoLocal` — o upload ANTES de ir para o storage (o caminho temporário do PHP);
 *  - `deChave`        — o que ESTÁ no storage, pelo verbo `abrir()`: é o hash do arquivo final
 *                       depois de uma compressão, e o do acervo legado no preenchimento em lote;
 *  - `deStream`       — um recurso já aberto (quem abriu, fecha);
 *  - `deTexto`        — conteúdo que já está em memória (o HTML de uma peça).
 *
 * O resultado é hex minúsculo de 64 caracteres, o mesmo formato de `hash('sha256', …)` — e o
 * único que `PastaDocumento::setSha256()` aceita.
 *
 * Nada aqui decide o que fazer com o hash: não compara, não consulta banco, não conhece tenant.
 */
final class Sha256DeArquivo
{
    private const ALGORITMO = 'sha256';

    private function __construct()
    {
    }

    /**
     * Lê o recurso da posição atual até o fim. Não fecha nem rebobina: o recurso é de quem chamou.
     *
     * @param resource $recurso aberto para leitura
     *
     * @throws FalhaDeArmazenamento se não for um recurso aberto
     */
    public static function deStream(mixed $recurso): string
    {
        if (!is_resource($recurso)) {
            throw new FalhaDeArmazenamento('Calcular o hash exige um recurso aberto para leitura.');
        }

        $contexto = hash_init(self::ALGORITMO);
        hash_update_stream($contexto, $recurso);

        return hash_final($contexto);
    }

    /**
     * @throws FalhaDeArmazenamento se o arquivo não puder ser aberto ou lido
     */
    public static function deArquivoLocal(string $caminho): string
    {
        $recurso = @fopen($caminho, 'rb');
        if ($recurso === false) {
            throw new FalhaDeArmazenamento(sprintf('Não foi possível abrir %s para calcular o hash.', $caminho));
        }

        try {
            return self::deStream($recurso);
        } finally {
            fclose($recurso);
        }
    }

    /**
     * O hash do que ESTÁ no storage, pela chave — a única forma de saber o conteúdo depois de uma
     * compressão (que regrava a mesma chave com outro binário) e de medir o acervo legado.
     *
     * @throws ArquivoNaoEncontrado  quando não há arquivo na chave
     * @throws FalhaDeArmazenamento quando o storage não consegue abrir ou a leitura falha
     */
    public static function deChave(ArmazenamentoDeArquivos $armazenamento, ChaveDeArquivo $chave): string
    {
        $recurso = $armazenamento->abrir($chave);

        try {
            return self::deStream($recurso);
        } finally {
            if (is_resource($recurso)) {
                fclose($recurso);
            }
        }
    }

    /**
     * O hash do que ficou no storage DEPOIS de uma compressão, quando ele é opcional: o arquivo já
     * está gravado e o documento precisa ser registrado mesmo que esta leitura a mais falhe — senão
     * um upload que funcionava vira 500 com arquivo órfão. Na falha devolve `null` (o comando
     * `app:documentos:calcular-hash` preenche depois) e deixa um warning no log.
     */
    public static function deChaveOuNulo(
        ArmazenamentoDeArquivos $armazenamento,
        ChaveDeArquivo $chave,
        LoggerInterface $logger,
    ): ?string {
        try {
            return self::deChave($armazenamento, $chave);
        } catch (\Exception $e) {
            $logger->warning('Hash do arquivo comprimido não calculado; o documento fica sem sha256 até o app:documentos:calcular-hash.', [
                'chave' => $chave->comoTexto(),
                'erro'  => $e->getMessage(),
            ]);

            return null;
        }
    }

    public static function deTexto(string $conteudo): string
    {
        return hash(self::ALGORITMO, $conteudo);
    }
}
