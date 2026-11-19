<?php

declare(strict_types=1);

namespace App\Tarefa\UseCase;

use App\Entity\Auth\User;
use App\Entity\Tarefa\Tarefa;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\EventListener\PastaSomenteLeituraListener;
use App\Pasta\Exception\PastaDeOutroEscritorioException;
use App\Pasta\Exception\PastaExcluidaException;
use App\Pasta\Exception\SemPermissaoParaVerPastaException;
use App\Pasta\Service\AcessoAPasta;
use App\Service\NotificacaoService;
use App\Tarefa\DTO\CriarMetaInput;
use App\Tarefa\DTO\MetaCriadaOutput;
use App\Tarefa\Enum\EtapaDaPasta;
use App\Tarefa\Exception\AnaliseDoPrazoRecusadaException;
use App\Tarefa\Exception\MetaNovaInvalidaException;
use App\Tarefa\Exception\SemAcessoAoModuloTarefasException;
use App\Tarefa\Service\AcessoAoModuloTarefas;
use App\Tarefa\Service\GravacaoDeMetaNova;
use App\Tenant\Service\ColaboradoresAtribuiveis;

/**
 * Cria a meta da pasta: o "Nova meta" do modal da pasta (rota `tarefa_criar_para_pasta`) e a ação `criar_meta` do
 * conector de I.A. (spec mcp-remoto-oauth, PR 10). Saiu do `TarefaController::criarParaPasta` para os dois caminhos
 * gravarem pela mesma regra.
 *
 * Quem cria precisa do módulo Tarefas e de VER a pasta (a meta é conteúdo da pasta; C6-SEC-03). A pasta tem de ser
 * do escritório pela POSSE dela, nunca pelo vínculo de quem a criou. Título, descrição, prazo, ao menos um
 * responsável colega do escritório e a etapa são obrigatórios (D-META, desenho 02). A análise do prazo, quando
 * veio, não grava estimativa. A meta sai numerada no escritório e os responsáveis são avisados.
 */
final class CriarMetaUseCase
{
    public function __construct(
        private readonly AcessoAoModuloTarefas $moduloTarefas,
        private readonly AcessoAPasta $acessoAPasta,
        private readonly ColaboradoresAtribuiveis $atribuiveis,
        private readonly RegistrarAnaliseDoPrazoNaMetaUseCase $registrarAnalise,
        private readonly GravacaoDeMetaNova $gravarMeta,
        private readonly NotificacaoService $notificacoes,
    ) {
    }

    /**
     * @throws SemAcessoAoModuloTarefasException
     * @throws PastaDeOutroEscritorioException
     * @throws SemPermissaoParaVerPastaException
     * @throws PastaExcluidaException
     * @throws MetaNovaInvalidaException
     * @throws AnaliseDoPrazoRecusadaException
     */
    public function executar(Pasta $pasta, User $quem, Tenant $tenant, CriarMetaInput $input, \DateTimeImmutable $hoje): MetaCriadaOutput
    {
        $this->exigirAcesso($pasta, $quem, $tenant);
        [$prazo, $etapa, $responsaveis] = $this->exigirCamposValidos($input, $tenant);

        $meta = $this->montar($input, $pasta, $tenant, $quem, $prazo, $etapa, $responsaveis);
        $this->registrarAnaliseDoPrazo($meta, $input, $hoje);
        $this->gravarMeta->executar($meta);
        $this->notificacoes->notificarTarefaCriada($meta);

        return MetaCriadaOutput::de($meta);
    }

    private function exigirAcesso(Pasta $pasta, User $quem, Tenant $tenant): void
    {
        if (!$this->moduloTarefas->pode($quem, $tenant)) {
            throw new SemAcessoAoModuloTarefasException('Sem acesso ao módulo Tarefas.');
        }
        $escritorioDaPasta = $pasta->getTenant()?->getId();
        if ($escritorioDaPasta === null || $escritorioDaPasta !== $tenant->getId()) {
            throw new PastaDeOutroEscritorioException('Pasta não encontrada.');
        }
        if (!$this->acessoAPasta->podeVer($quem, $pasta)) {
            throw new SemPermissaoParaVerPastaException('Sem permissão para acessar esta pasta.');
        }
        // A tela já recusa antes (PastaSomenteLeituraListener); o conector chega aqui sem passar por ele.
        if ($pasta->estaExcluida()) {
            throw new PastaExcluidaException(PastaSomenteLeituraListener::MENSAGEM);
        }
    }

    /**
     * @return array{\DateTimeImmutable, EtapaDaPasta, list<User>}
     */
    private function exigirCamposValidos(CriarMetaInput $input, Tenant $tenant): array
    {
        $this->exigirTituloEDescricao($input);

        $responsaveis = $this->atribuiveis->resolverVarios($input->responsaveis, $tenant);
        if ($input->prazo === null) {
            throw new MetaNovaInvalidaException('Informe um prazo válido para a meta.');
        }
        if ($responsaveis === []) {
            throw new MetaNovaInvalidaException('Escolha ao menos um responsável do escritório para a meta.');
        }
        if ($input->etapa === null) {
            throw new MetaNovaInvalidaException('Escolha a caixa / etapa da meta.');
        }

        return [$input->prazo, $input->etapa, $responsaveis];
    }

    /** O título cabe na coluna (o mesmo teto do "Renomear"); sem isso o conector estouraria no flush, não numa recusa. */
    private function exigirTituloEDescricao(CriarMetaInput $input): void
    {
        if (trim($input->titulo) === '' || trim($input->descricao) === '') {
            throw new MetaNovaInvalidaException('Título e descrição são obrigatórios.');
        }
        if (mb_strlen(trim($input->titulo)) > RenomearMetaUseCase::TAMANHO_MAXIMO) {
            throw new MetaNovaInvalidaException(sprintf('O nome da meta pode ter até %d caracteres.', RenomearMetaUseCase::TAMANHO_MAXIMO));
        }
    }

    /**
     * A meta nova, ainda sem gravar (a gravação, com o número, é do `GravacaoDeMetaNova`).
     *
     * @param list<User> $responsaveis
     */
    private function montar(CriarMetaInput $input, Pasta $pasta, Tenant $tenant, User $quem, \DateTimeImmutable $prazo, EtapaDaPasta $etapa, array $responsaveis): Tarefa
    {
        $meta = new Tarefa();
        $meta->setTitulo(trim($input->titulo));
        $meta->setDescricao(trim($input->descricao));
        $meta->setStatus(Tarefa::STATUS_PENDENTE);
        $meta->setPasta($pasta);
        $meta->setTenant($tenant);
        $meta->setCriadoPor($quem);
        $meta->setPrazo($prazo);
        $meta->setEtapa($etapa);
        foreach ($responsaveis as $responsavel) {
            $meta->addResponsavel($responsavel);
        }

        return $meta;
    }

    /** Análise do prazo (desenho 02): sem ela a meta nasce como sempre nasceu; estimado ou a conferir não grava. */
    private function registrarAnaliseDoPrazo(Tarefa $meta, CriarMetaInput $input, \DateTimeImmutable $hoje): void
    {
        if ($input->analise === null) {
            return;
        }

        try {
            $this->registrarAnalise->executar($meta, $input->analise, $hoje);
        } catch (\DomainException $recusa) {
            throw new AnaliseDoPrazoRecusadaException($recusa->getMessage(), 0, $recusa);
        }
    }
}
