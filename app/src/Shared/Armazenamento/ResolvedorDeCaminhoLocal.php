<?php

declare(strict_types=1);

namespace App\Shared\Armazenamento;

use App\Shared\Armazenamento\Exception\FalhaDeArmazenamento;

/**
 * Traduz chave em caminho de disco — **exatamente o caminho que o sistema já usa hoje**.
 *
 * Esta classe é a razão de a E2 não precisar de migration. Todo o layout irregular do acervo fica
 * aqui dentro, e o contrato lá fora fica regular: sete categorias em diretório plano, duas em
 * subpasta por tenant, uma sem parâmetro próprio. O dia em que o R2 entrar, é um resolvedor
 * IRMÃO que traduz a mesma chave no layout de objeto — nenhum arquivo se move na E2, nenhum
 * registro muda (INV-1, INV-2).
 *
 * ## Depois da E2 este é o ÚNICO lugar que conhece os sete parâmetros
 *
 * `%uploads_dir%`, `%clientes_uploads_dir%`, `%chamados_uploads_dir%`,
 * `%justificativas_uploads_dir%`, `%fotos_perfil_dir%`, `%cobrancas_uploads_dir%` e
 * `%kanban_uploads_dir%` eram injetados em 33 arquivos de domínio no início da E2; a E2.3 tirou o
 * diretório de cinco controllers de download, e os demais saem nas fatias seguintes. Ao fim da E2
 * nenhum domínio os recebe, e o teste de arquitetura da E2.8 cobra isso.
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
    public function __construct(
        private string $uploadsDir,
        private string $clientesUploadsDir,
        private string $chamadosUploadsDir,
        private string $justificativasUploadsDir,
        private string $fotosPerfilDir,
        private string $cobrancasUploadsDir,
        private string $kanbanUploadsDir,
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
     * As sete raízes configuradas, sem escopo.
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
            CategoriaDeArquivo::CLIENTE_DOCUMENTO   => $this->raiz($this->clientesUploadsDir),
            CategoriaDeArquivo::CHAMADO_ANEXO       => $this->raiz($this->chamadosUploadsDir),
            CategoriaDeArquivo::JUSTIFICATIVA_ANEXO => $this->raiz($this->justificativasUploadsDir),
            CategoriaDeArquivo::FOTO_PERFIL         => $this->raiz($this->fotosPerfilDir),
            CategoriaDeArquivo::KANBAN_ANEXO        => $this->raiz($this->kanbanUploadsDir),

            CategoriaDeArquivo::PASTA_IMAGEM_EDITOR,
            CategoriaDeArquivo::COBRANCA_DOCUMENTO  => throw new \LogicException('resolvidas por prefixoDe()'),

            CategoriaDeArquivo::TAREFA_ANEXO        => throw $this->tarefaAindaNaoResolvivel(),
        };
    }

    /**
     * Anexo de tarefa não é resolvível nesta fatia, e falhar alto é a resposta certa.
     *
     * A coluna `tarefa_mensagem.arquivo_anexo` guarda um **caminho público**
     * (`/uploads/tarefas/<sub>/<arquivo>`), não um nome — e um valor com `/` é recusado por
     * `ChaveDeArquivo`, como D5 exige. Relaxar a chave para acomodar o legado está proibido: a
     * fronteira de storage vale mais que a conveniência de uma categoria.
     *
     * A categoria continua no enum de propósito. Tirá-la esconderia o problema; deixá-la
     * lançando mantém o buraco visível até a E2.7 decidir entre adapter seguro ou normalização
     * de dados na E3. Enquanto isso, `CaminhoDeAnexoDeTarefa` segue guardando esse fluxo, com a
     * allowlist e o confinamento por `realpath()` que a E1 lhe deu.
     */
    private function tarefaAindaNaoResolvivel(): FalhaDeArmazenamento
    {
        return new FalhaDeArmazenamento(
            'TAREFA_ANEXO ainda não é endereçável por chave: a coluna guarda caminho público '
            . '(/uploads/tarefas/<sub>/<arquivo>), que ChaveDeArquivo recusa por conter "/". '
            . 'A decisão está reservada à fatia E2.7 (D5); até lá o fluxo continua em '
            . 'CaminhoDeAnexoDeTarefa.',
        );
    }

    private function raiz(string $diretorio): string
    {
        return rtrim($diretorio, '/');
    }
}
