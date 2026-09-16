<?php

declare(strict_types=1);

namespace App\Pasta\Armazenamento;

use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\PastaDocumento;
use App\Shared\Armazenamento\CategoriaDeArquivo;
use App\Shared\Armazenamento\ChaveDeArquivo;
use App\Shared\Armazenamento\EscopoDeArquivo;
use App\Shared\Armazenamento\Exception\ChaveDeArquivoInvalida;
use App\Shared\Armazenamento\NovoArquivo;

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
 *
 * ## Arquivo novo (E2.4A)
 *
 * Arquivo que ainda não existe não tem nome — quem cunha é o storage (D8). `novoDocumento()`
 * recebe o **próprio documento** que vai guardar a chave, já com o escritório atribuído, e tira o
 * escopo dele — pelo mesmo getter que `documento()` usa na leitura. Gravação e leitura ficam
 * simétricas por construção: não depende de o escritório da sessão coincidir com o da pasta (só
 * o TenantFilter garante isso). `novaImagemDoEditor()` segue a mesma exceção de
 * `imagemDoEditor()`: sem linha no banco, o escopo é o tenant da sessão — o mesmo que a rota de
 * leitura usa.
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

    /**
     * `$documento` ainda não tem nome nem precisa estar persistido; basta o escritório. Chame
     * ANTES de gravar e só persista depois.
     */
    public static function novoDocumento(PastaDocumento $documento, string $extensao): NovoArquivo
    {
        return new NovoArquivo(
            self::escopoDe($documento->getTenant(), 'documento novo de pasta'),
            CategoriaDeArquivo::PASTA_DOCUMENTO,
            $extensao,
        );
    }

    /**
     * Imagem nova do editor: mesmo endereçamento de {@see imagemDoEditor()}, para a leitura achar.
     */
    public static function novaImagemDoEditor(Tenant $tenant, string $extensao): NovoArquivo
    {
        return new NovoArquivo(
            self::escopoDe($tenant, 'imagem nova do editor'),
            CategoriaDeArquivo::PASTA_IMAGEM_EDITOR,
            $extensao,
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
