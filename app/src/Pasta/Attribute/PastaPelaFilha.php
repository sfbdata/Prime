<?php

declare(strict_types=1);

namespace App\Pasta\Attribute;

/**
 * Declara, na action, de onde vem a pasta quando a rota recebe só o id (`int`) de uma FILHA dela —
 * documento, seção — e não a entidade.
 *
 * Existe por causa do `PastaSomenteLeituraListener`: ele enxerga a pasta nos argumentos já
 * resolvidos (`Pasta` ou objeto com `getPasta()`); um `int` cru passava por ele invisível, e a
 * escrita entrava na pasta excluída (lápide). Com este atributo o listener carrega a filha pelo id
 * da rota, escopada ao escritório da sessão, e aplica a mesma recusa das demais rotas.
 *
 * Por que não trocar a assinatura para a entidade (`PastaSecao $secao`)? Mudaria a resposta de
 * "não encontrado" (o JSON 404 da action viraria a página 404 do resolver) e, no documento, o
 * `LixeiraFilter` passaria a recusar no resolver — contrato que a tela já consome.
 *
 * O teste de arquitetura `PastaSomenteLeituraRotasArquiteturaTest` exige que toda rota de escrita
 * `pasta_*` receba a pasta (direta ou filha), declare este atributo, ou esteja numa das listas de
 * exceção do listener.
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
final class PastaPelaFilha
{
    /**
     * @param class-string $entidade  entidade filha, com `getPasta()` e associação `tenant`
     * @param string       $argumento nome da variável da rota que traz o id da filha
     */
    public function __construct(
        public readonly string $entidade,
        public readonly string $argumento,
    ) {
    }
}
