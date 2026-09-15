<?php

declare(strict_types=1);

namespace App\Shared\Armazenamento;

/**
 * As categorias cujo layout em disco isola o escritório FISICAMENTE, em subpasta própria.
 *
 * É a barreira estrutural de D7. Operação por prefixo (listar/apagar tudo de um escopo) só é
 * segura quando o prefixo resolvido pertence exclusivamente àquele escopo — e isso hoje só vale
 * para duas categorias:
 *
 *  - `PASTA_IMAGEM_EDITOR` → `uploads/pastas/<tenantId>/`
 *  - `COBRANCA_DOCUMENTO`  → `uploads/cobrancas/<tenantId>/`
 *
 * Nas outras sete, o diretório é **compartilhado por todos os escritórios**
 * (`uploads/clientes`, `uploads/justificativas`, …). Um "apagar o prefixo do tenant 5" ali
 * destruiria o acervo alheio. Isolamento lógico **não se infere de diretório compartilhado**.
 *
 * Por que um enum separado em vez de um `bool` em `CategoriaDeArquivo`: com a flag, a assinatura
 * de `excluirPrefixo()` aceitaria qualquer categoria e dependeria de o chamador lembrar de checar.
 * Com o tipo, a categoria plana **não é representável** no parâmetro — o erro deixa de ser
 * possível em vez de ser detectado.
 *
 * ⚠️ `PASTA_DOCUMENTO` divide a MESMA raiz de `PASTA_IMAGEM_EDITOR` (`uploads/pastas/`): as
 * imagens do editor moram em subpastas dela. Por isso o adapter, além de exigir este tipo, ainda
 * confere que o prefixo resolvido não é a raiz compartilhada antes de apagar qualquer coisa.
 */
enum CategoriaComIsolamentoFisico: string
{
    case PASTA_IMAGEM_EDITOR = 'pasta_imagem_editor';
    case COBRANCA_DOCUMENTO  = 'cobranca_documento';

    public function paraCategoria(): CategoriaDeArquivo
    {
        return match ($this) {
            self::PASTA_IMAGEM_EDITOR => CategoriaDeArquivo::PASTA_IMAGEM_EDITOR,
            self::COBRANCA_DOCUMENTO  => CategoriaDeArquivo::COBRANCA_DOCUMENTO,
        };
    }

    /**
     * Conversão de mão única e explícita: devolve null para as sete categorias planas.
     *
     * Não existe `deCategoria()` que lance — quem precisa disto está descrevendo o layout, não
     * apagando arquivo. Quem apaga recebe este tipo pronto e nunca converte.
     */
    public static function deCategoriaOuNull(CategoriaDeArquivo $categoria): ?self
    {
        return self::tryFrom($categoria->value);
    }
}
