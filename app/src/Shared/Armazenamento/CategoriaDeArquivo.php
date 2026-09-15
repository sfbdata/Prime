<?php

declare(strict_types=1);

namespace App\Shared\Armazenamento;

/**
 * As nove categorias de arquivo persistente que o sistema realmente tem hoje (E2, §3.1).
 *
 * É um enum FECHADO de propósito: categoria nova obriga a acrescentar uma linha no
 * `ResolvedorDeCaminhoLocal` e outra no `MapaDeChaveParaCaminhoLocalTest`. Sem isso, um arquivo
 * novo nasceria sem lugar definido em disco e o defeito só apareceria em produção.
 *
 * **Isolamento físico não mora aqui.** Só duas categorias têm subpasta por tenant no disco atual,
 * e a lista delas é o enum SEPARADO `CategoriaComIsolamentoFisico` — é ele, e não este, que as
 * operações por prefixo aceitam (D7). A razão de serem dois tipos e não uma flag neste: uma flag
 * seria consultável mas esquecível; um tipo separado torna a categoria plana **irrepresentável**
 * na assinatura de quem apaga por prefixo.
 */
enum CategoriaDeArquivo: string
{
    case PASTA_DOCUMENTO      = 'pasta_documento';
    case PASTA_IMAGEM_EDITOR  = 'pasta_imagem_editor';
    case CLIENTE_DOCUMENTO    = 'cliente_documento';
    case CHAMADO_ANEXO        = 'chamado_anexo';
    case JUSTIFICATIVA_ANEXO  = 'justificativa_anexo';
    case FOTO_PERFIL          = 'foto_perfil';
    case COBRANCA_DOCUMENTO   = 'cobranca_documento';
    case KANBAN_ANEXO         = 'kanban_anexo';
    case TAREFA_ANEXO         = 'tarefa_anexo';

    /**
     * Existe para leitura e diagnóstico — NÃO é o guarda de D7.
     *
     * Quem apaga por prefixo recebe `CategoriaComIsolamentoFisico`, então nem chega a precisar
     * perguntar isto. Este método serve a quem só quer descrever o layout (documentação, teste do
     * mapa, mensagem de erro).
     */
    public function temIsolamentoFisicoNoDiscoLocal(): bool
    {
        return CategoriaComIsolamentoFisico::deCategoriaOuNull($this) !== null;
    }
}
