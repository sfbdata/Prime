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
    // A foto do cabeçalho da pasta sem cliente cadastrado. Plana, numa subpasta da raiz dos documentos de pasta; o
    // escopo é o escritório dono da pasta (`ChavesDePasta::foto`), e a purga a leva pelos registros de `pasta`.
    case PASTA_FOTO           = 'pasta_foto';
    case CLIENTE_DOCUMENTO    = 'cliente_documento';
    // A foto do cliente (cabeçalho da pasta). Plana, numa subpasta da raiz dos documentos de cliente; o escopo é o
    // escritório dono do cliente (`ChavesDeCliente::foto`), e a purga a leva pelos registros de `cliente`.
    case CLIENTE_FOTO         = 'cliente_foto';
    case CHAMADO_ANEXO        = 'chamado_anexo';
    case JUSTIFICATIVA_ANEXO  = 'justificativa_anexo';
    case FOTO_PERFIL          = 'foto_perfil';
    case COBRANCA_DOCUMENTO   = 'cobranca_documento';
    case KANBAN_ANEXO         = 'kanban_anexo';
    case TAREFA_ANEXO         = 'tarefa_anexo';
    // Documentos de demanda do Trabalho Externo. Plana como o Kanban: o escopo é o escritório
    // solicitante, dono da demanda (`ChavesDeTrabalhoExterno`).
    case TRABALHO_EXTERNO_DOCUMENTO = 'trabalho_externo_documento';
    // Arquivos do currículo do Trabalho Externo (foto, carteira da OAB, comprovante, currículo).
    // Escopo GLOBAL, como a foto de perfil: o currículo é do usuário, não de um escritório, e a
    // purga de escritório não o leva (`ChavesDeTrabalhoExterno::arquivoDoCurriculo`).
    case TRABALHO_EXTERNO_CURRICULO = 'trabalho_externo_curriculo';
    // A imagem da campanha do modo simples do Comercial (spec comercial-e-emails.md §12, F3i). Escopo
    // GLOBAL: é da plataforma (só o Master envia), não de um escritório, e sai por endereço público
    // (`/e/i/{token}.{ext}`) até vencer (`ChavesDeComercial`).
    case COMERCIAL_IMAGEM = 'comercial_imagem';
    // As versões ajustadas dos documentos jurídicos (Política de Privacidade, Termos de Uso, aceite da IA) que o
    // Master envia no bloco "Questões jurídicas" da aba "Configurações da I.A.". Escopo GLOBAL: são documentos da
    // plataforma, não de um escritório, e a purga de escritório não os leva (`ChavesDeInteligencia`).
    case INTELIGENCIA_DOCUMENTO_JURIDICO = 'inteligencia_documento_juridico';
    // O comprovante de um pagamento de recompensa registrado na baixa manual do Master (spec perfis-1.2-vs-25-10, F4).
    // Escopo GLOBAL: é da plataforma, não de um escritório, e a purga de escritório não o leva (`ChavesDeRecompensa`).
    case RECOMPENSA_COMPROVANTE = 'recompensa_comprovante';

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
