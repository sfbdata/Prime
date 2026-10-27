<?php

declare(strict_types=1);

namespace App\Shared\Armazenamento;

/**
 * Traduz chave em caminho de disco — **exatamente o caminho que o sistema já usa hoje**.
 *
 * Esta classe é a razão de a E2 não precisar de migration. Todo o layout irregular do acervo fica
 * aqui dentro, e o contrato lá fora fica regular: sete categorias em diretório plano (Tarefa numa
 * subpasta fixa, `chat/`, do seu), duas em subpasta por tenant. O dia em que o R2 entrar, é um resolvedor
 * IRMÃO que traduz a mesma chave no layout de objeto — nenhum arquivo se move na E2, nenhum
 * registro muda (INV-1, INV-2).
 *
 * ## Depois da E2 este é o ÚNICO lugar que conhece os oito parâmetros
 *
 * `%uploads_dir%`, `%clientes_uploads_dir%`, `%chamados_uploads_dir%`,
 * `%justificativas_uploads_dir%`, `%fotos_perfil_dir%`, `%cobrancas_uploads_dir%`,
 * `%kanban_uploads_dir%` e — desde a E2.7 — `%tarefas_uploads_dir%`. Os sete primeiros eram
 * injetados em 33 arquivos de domínio no início da E2; a E2.3 tirou o diretório de cinco
 * controllers de download, e os demais saíram nas fatias seguintes. Nenhum domínio os recebe, e o
 * teste de arquitetura da E2.8 cobra isso.
 *
 * ## O escopo é ignorado em sete das nove categorias — e isso é deliberado
 *
 * O disco atual é plano nelas. A chave carrega o escopo mesmo assim, para que a E3/E4 tenham a
 * informação sem tocar no banco. O preço é o risco R1: tenant errado é **invisível** aqui. Por
 * isso a prova de isolamento não sai deste caminho — sai dos testes das fábricas de chave e do
 * dublê em memória, que materializa o escopo.
 */
final readonly class ResolvedorDeCaminhoLocal
{
    /**
     * Onde moram os anexos de mensagem de tarefa, dentro de `%tarefas_uploads_dir%` (E2.7). É a
     * subpasta que o `TarefaController` sempre usou; a coluna guarda
     * `/uploads/tarefas/chat/<nome>` e `ChavesDeTarefa` tira o prefixo. A outra subpasta que
     * existe em disco, `admin/`, não tem referência no banco e não é endereçável (DP-9).
     */
    private const SUBPASTA_DE_TAREFA = 'chat';

    public function __construct(
        private string $uploadsDir,
        private string $clientesUploadsDir,
        private string $chamadosUploadsDir,
        private string $justificativasUploadsDir,
        private string $fotosPerfilDir,
        private string $cobrancasUploadsDir,
        private string $kanbanUploadsDir,
        private string $tarefasUploadsDir,
        // Último e com padrão vazio: entrou com o Trabalho Externo, depois das outras raízes, e os
        // testes que montam o resolvedor à mão continuam valendo. Vazio NUNCA vira caminho
        // (ver `trabalhoExternoRaiz()`): falha alto em vez de gravar na raiz do disco.
        private string $trabalhoExternoUploadsDir = '',
        // As imagens das campanhas do Comercial (F3i), com o mesmo padrão vazio e a mesma recusa
        // de virar caminho (ver `comercialImagensRaiz()`).
        private string $comercialImagensDir = '',
        // As versões ajustadas dos documentos jurídicos do Master, com o mesmo padrão vazio e a mesma
        // recusa de virar caminho (ver `documentosJuridicosRaiz()`).
        private string $documentosJuridicosDir = '',
        // Os comprovantes de pagamento de recompensa (baixa manual do Master), com o mesmo padrão vazio e a mesma recusa
        // de virar caminho (ver `recompensaComprovantesRaiz()`).
        private string $recompensaComprovantesDir = '',
    ) {
    }

    /** Caminho absoluto do arquivo. É a concatenação que o código de hoje já faz. */
    public function caminhoDe(ChaveDeArquivo $chave): string
    {
        return $this->diretorioDe($chave->escopo, $chave->categoria) . '/' . $chave->nome;
    }

    /**
     * O diretório exclusivo de (escopo, categoria) e a raiz de onde ele desce — só para as duas
     * categorias que isolam o escritório em disco (D7, E2.5).
     *
     * É a MESMA conta que {@see caminhoDe()} faz para essas categorias (`diretorioDe()` passa por
     * aqui), então o prefixo apagado pela purga é, por construção, o diretório em que os arquivos
     * do escritório foram gravados. A prova de que o prefixo realmente pertence só ao escopo é do
     * backend, que olha o disco; aqui só se monta o endereço.
     *
     * @return array{raiz: string, prefixo: string}
     */
    public function prefixoDe(EscopoDeArquivo $escopo, CategoriaComIsolamentoFisico $categoria): array
    {
        $raiz = match ($categoria) {
            CategoriaComIsolamentoFisico::PASTA_IMAGEM_EDITOR => $this->raiz($this->uploadsDir),
            CategoriaComIsolamentoFisico::COBRANCA_DOCUMENTO  => $this->raiz($this->cobrancasUploadsDir),
        };

        return ['raiz' => $raiz, 'prefixo' => $raiz . '/' . $escopo->tenantIdObrigatorio()];
    }

    /**
     * As oito raízes configuradas, sem escopo.
     *
     * Existe para o backend recusar um prefixo que coincida com alguma delas ou as contenha — o
     * acidente de configuração em que `cobrancas/5` fosse, na verdade, o diretório de outra
     * categoria inteira. Não serve para montar caminho de arquivo.
     *
     * @return list<string>
     */
    public function raizesConfiguradas(): array
    {
        return array_map($this->raiz(...), [
            $this->uploadsDir,
            $this->clientesUploadsDir,
            $this->chamadosUploadsDir,
            $this->justificativasUploadsDir,
            $this->fotosPerfilDir,
            $this->cobrancasUploadsDir,
            $this->kanbanUploadsDir,
            $this->tarefasUploadsDir,
            ...($this->trabalhoExternoUploadsDir !== '' ? [$this->trabalhoExternoUploadsDir] : []),
            ...($this->comercialImagensDir !== '' ? [$this->comercialImagensDir] : []),
            ...($this->documentosJuridicosDir !== '' ? [$this->documentosJuridicosDir] : []),
            ...($this->recompensaComprovantesDir !== '' ? [$this->recompensaComprovantesDir] : []),
        ]);
    }

    /**
     * Diretório onde a categoria mora, para o escopo informado.
     *
     * Só `PASTA_IMAGEM_EDITOR` e `COBRANCA_DOCUMENTO` descem para a subpasta do tenant — são as
     * duas de {@see CategoriaComIsolamentoFisico}, e não é coincidência: é essa a definição.
     *
     * **Privado de propósito.** Chegou a ser público para um teste de arquitetura chamá-lo, e
     * isso era o contrário do que D7 pede: um método público do núcleo que aceita categoria
     * plana e devolve o diretório COMPARTILHADO entre escritórios, sem escopo aplicado. O teste
     * passou a comparar `caminhoDe()` de dois tenants, que prova a mesma coisa sem alargar a API.
     * A operação por prefixo usa {@see prefixoDe()}, que só aceita {@see CategoriaComIsolamentoFisico}.
     */
    private function diretorioDe(EscopoDeArquivo $escopo, CategoriaDeArquivo $categoria): string
    {
        $isolada = CategoriaComIsolamentoFisico::deCategoriaOuNull($categoria);
        if ($isolada !== null) {
            return $this->prefixoDe($escopo, $isolada)['prefixo'];
        }

        return match ($categoria) {
            CategoriaDeArquivo::PASTA_DOCUMENTO     => $this->raiz($this->uploadsDir),
            // Subpasta da mesma raiz: as fotos não se misturam com os documentos da pasta.
            CategoriaDeArquivo::PASTA_FOTO          => $this->raiz($this->uploadsDir) . '/fotos',
            CategoriaDeArquivo::CLIENTE_DOCUMENTO   => $this->raiz($this->clientesUploadsDir),
            // Subpasta da mesma raiz: as fotos não se misturam com os documentos do cadastro.
            CategoriaDeArquivo::CLIENTE_FOTO        => $this->raiz($this->clientesUploadsDir) . '/fotos',
            CategoriaDeArquivo::CHAMADO_ANEXO       => $this->raiz($this->chamadosUploadsDir),
            CategoriaDeArquivo::JUSTIFICATIVA_ANEXO => $this->raiz($this->justificativasUploadsDir),
            CategoriaDeArquivo::FOTO_PERFIL         => $this->raiz($this->fotosPerfilDir),
            CategoriaDeArquivo::KANBAN_ANEXO        => $this->raiz($this->kanbanUploadsDir),
            CategoriaDeArquivo::TAREFA_ANEXO        => $this->raiz($this->tarefasUploadsDir) . '/' . self::SUBPASTA_DE_TAREFA,
            CategoriaDeArquivo::TRABALHO_EXTERNO_DOCUMENTO => $this->trabalhoExternoRaiz(),
            // Subpasta da mesma raiz: os currículos não se misturam com os documentos das demandas.
            CategoriaDeArquivo::TRABALHO_EXTERNO_CURRICULO => $this->trabalhoExternoRaiz() . '/curriculos',
            CategoriaDeArquivo::COMERCIAL_IMAGEM => $this->comercialImagensRaiz(),
            CategoriaDeArquivo::INTELIGENCIA_DOCUMENTO_JURIDICO => $this->documentosJuridicosRaiz(),
            CategoriaDeArquivo::RECOMPENSA_COMPROVANTE => $this->recompensaComprovantesRaiz(),

            CategoriaDeArquivo::PASTA_IMAGEM_EDITOR,
            CategoriaDeArquivo::COBRANCA_DOCUMENTO  => throw new \LogicException('resolvidas por prefixoDe()'),
        };
    }

    private function trabalhoExternoRaiz(): string
    {
        if ($this->trabalhoExternoUploadsDir === '') {
            throw new \LogicException('Diretório dos documentos do Trabalho Externo não configurado (trabalho_externo_uploads_dir).');
        }

        return $this->raiz($this->trabalhoExternoUploadsDir);
    }

    private function comercialImagensRaiz(): string
    {
        if ($this->comercialImagensDir === '') {
            throw new \LogicException('Diretório das imagens das campanhas do Comercial não configurado (comercial_imagens_dir).');
        }

        return $this->raiz($this->comercialImagensDir);
    }

    private function documentosJuridicosRaiz(): string
    {
        if ($this->documentosJuridicosDir === '') {
            throw new \LogicException('Diretório dos documentos jurídicos do Master não configurado (documentos_juridicos_dir).');
        }

        return $this->raiz($this->documentosJuridicosDir);
    }

    private function recompensaComprovantesRaiz(): string
    {
        if ($this->recompensaComprovantesDir === '') {
            throw new \LogicException('Diretório dos comprovantes de recompensa não configurado (recompensa_comprovantes_dir).');
        }

        return $this->raiz($this->recompensaComprovantesDir);
    }

    private function raiz(string $diretorio): string
    {
        return rtrim($diretorio, '/');
    }
}
