<?php

declare(strict_types=1);

namespace App\Pasta\Armazenamento;

use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\PastaDocumento;
use App\Shared\Armazenamento\CategoriaDeArquivo;
use App\Shared\Armazenamento\ChaveDeArquivo;
use App\Shared\Armazenamento\EscopoDeArquivo;
use App\Shared\Armazenamento\Exception\ChaveDeArquivoInvalida;

/**
 * Traduz o que o domínio Pasta persiste em {@see ChaveDeArquivo} — o único lugar do domínio que
 * sabe qual categoria cada arquivo tem e de onde sai o escopo (E2.2).
 *
 * ## A regra que esta classe existe para cumprir (R1 da spec)
 *
 * O escopo sai da **entidade persistida**, nunca de um parâmetro. Um `$tenant` passado ao lado
 * da entidade pode descasar dela em silêncio — e no disco local de hoje o descasamento é
 * invisível (sete das nove categorias são planas), só virando 404 ou vazamento quando o backend
 * remoto entrar. Por isso `documento()` recebe **só** a entidade, e o teste da fábrica afirma
 * isso por reflexão.
 *
 * ## O nome vai byte a byte (D8)
 *
 * `caminho_arquivo` entra na chave exatamente como está no banco: sem `trim()`, sem normalizar
 * Unicode, sem tirar ponto final. Quem recusa (e nunca corrige) os cinco casos impossíveis é o
 * construtor de `ChaveDeArquivo`; esta classe não filtra nada.
 *
 * ## As duas formas sem entidade — e por que elas são exceção, não atalho
 *
 *  - `documentoPorNome()` serve a quem lê **projeção escalar** já filtrada pelo tenant
 *    (`chavesDePecasHtmlDoTenant`, a linha crua do reconciliador do Drive). Ali não existe
 *    entidade na mão, e o tenant informado É o da linha persistida — a consulta o fixou.
 *  - `imagemDoEditor()` serve à imagem de peça, que **não tem linha no banco**: vive só dentro
 *    do HTML e é endereçada pelo tenant da sessão mais o nome da URL.
 *
 * Nenhuma das duas deve ser usada onde a entidade existe.
 */
final class ChavesDePasta
{
    private function __construct()
    {
    }

    public static function documento(PastaDocumento $documento): ChaveDeArquivo
    {
        return new ChaveDeArquivo(
            self::escopoDe($documento->getTenant(), 'documento de pasta'),
            CategoriaDeArquivo::PASTA_DOCUMENTO,
            $documento->getCaminhoArquivo(),
        );
    }

    /**
     * Para projeção escalar de `pasta_documento` já filtrada por tenant. Ver docblock da classe.
     */
    public static function documentoPorNome(int $tenantId, string $caminhoArquivo): ChaveDeArquivo
    {
        return new ChaveDeArquivo(
            EscopoDeArquivo::deTenant($tenantId),
            CategoriaDeArquivo::PASTA_DOCUMENTO,
            $caminhoArquivo,
        );
    }

    /**
     * Imagem embutida numa peça: sem linha no banco, endereçada pelo tenant da sessão + nome.
     */
    public static function imagemDoEditor(Tenant $tenant, string $nome): ChaveDeArquivo
    {
        return new ChaveDeArquivo(
            self::escopoDe($tenant, 'imagem do editor'),
            CategoriaDeArquivo::PASTA_IMAGEM_EDITOR,
            $nome,
        );
    }

    private static function escopoDe(?Tenant $tenant, string $oQue): EscopoDeArquivo
    {
        $id = $tenant?->getId();

        if ($id === null) {
            throw new ChaveDeArquivoInvalida(sprintf(
                'Não dá para montar a chave de armazenamento de %s sem o escritório dono.',
                $oQue,
            ));
        }

        return EscopoDeArquivo::deTenant($id);
    }
}
