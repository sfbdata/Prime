<?php

declare(strict_types=1);

namespace App\Inteligencia\UseCase;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Inteligencia\Contexto\MontadorDeContextoDaPasta;
use App\Inteligencia\DTO\AnaliseOutput;
use App\Inteligencia\DTO\ContextoDaPasta;
use App\Inteligencia\DTO\MensagemDaConversaOutput;
use App\Inteligencia\DTO\PerguntaEnviadaOutput;
use App\Inteligencia\DTO\PerguntarSobreAPastaInput;
use App\Inteligencia\Entity\AnaliseDeInteligencia;
use App\Inteligencia\Entity\PerguntaDaConversa;
use App\Inteligencia\Enum\Agente;
use App\Inteligencia\Enum\TipoDeAnalise;
use App\Inteligencia\Exception\ContextoBloqueadoException;
use App\Inteligencia\Exception\FilaIndisponivelException;
use App\Inteligencia\Exception\InteligenciaIndisponivelException;
use App\Inteligencia\Exception\PastaNaoEncontradaException;
use App\Inteligencia\Prompt\PromptDaPergunta;
use App\Inteligencia\Repository\AnaliseDeInteligenciaRepository;
use App\Inteligencia\Repository\PerguntaDaConversaRepository;
use App\Inteligencia\Service\DisponibilidadeDeInteligencia;
use App\Inteligencia\Service\EnfileiradorDeAnalise;
use App\Inteligencia\Service\VisibilidadeDoFinanceiroDaPasta;
use App\Pasta\Entity\AbaDaPasta;
use App\Pasta\Entity\Pasta;
use App\Pasta\Repository\PastaRepository;
use App\Pasta\Service\VisibilidadeDasAbasDaPasta;
use App\Service\PermissionChecker;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * "Perguntar" do drawer da BlueJus IA (spec inteligencia-drawer-da-pasta, F3).
 *
 * Quem: usuário que vê a pasta E tem `modules.inteligencia.view`, num escritório com a IA ligada.
 * O quê: pergunta, em texto livre, sobre os dados REAIS desta pasta; a resposta chega pelo worker.
 * Pré-condições, na ordem (as mesmas do `SolicitarAnaliseDaPastaUseCase`): pasta do escritório (404),
 * permissão sobre a pasta (403), disponibilidade (409/429) e contexto não sigiloso (409). O texto já
 * chega conferido no input (1 a 2000 caracteres, senão 422). Pasta sem dados nas seções NÃO é
 * recusada: a pergunta pode ser respondida com "Não encontrei informação suficiente".
 * Uma pergunta em andamento por pessoa e pasta: a segunda devolve a que está em andamento, com
 * aviso, sem criar nem gastar o limitador (`$antesDeCriar` só roda quando uma nova vai ser criada).
 * Pós-condição: análise `pendente` de tipo `pergunta_livre`, agente Gestor (o contexto mais amplo),
 * com `contexto_resumo.financeiro` e `abasOcultas` decididos AQUI, com sessão (o worker obedece),
 * a pergunta em `inteligencia_pergunta` e 1 mensagem no `async`.
 *
 * @throws PastaNaoEncontradaException
 * @throws AccessDeniedException
 * @throws InteligenciaIndisponivelException
 * @throws ContextoBloqueadoException
 * @throws FilaIndisponivelException
 */
final class PerguntarSobreAPastaUseCase
{
    public const AGENTE = Agente::Gestor;
    public const AVISO_EM_ANDAMENTO = 'Já existe uma pergunta em andamento nesta pasta. Aguarde a resposta.';

    public function __construct(
        private readonly PastaRepository $pastas,
        private readonly PermissionChecker $permissionChecker,
        private readonly DisponibilidadeDeInteligencia $disponibilidade,
        private readonly VisibilidadeDoFinanceiroDaPasta $financeiro,
        private readonly MontadorDeContextoDaPasta $montador,
        private readonly AnaliseDeInteligenciaRepository $analises,
        private readonly PerguntaDaConversaRepository $perguntas,
        private readonly EnfileiradorDeAnalise $enfileirador,
        private readonly VisibilidadeDasAbasDaPasta $abas,
    ) {
    }

    public function executar(PerguntarSobreAPastaInput $input, User $user, Tenant $tenant, ?callable $antesDeCriar = null): PerguntaEnviadaOutput
    {
        $pasta = $this->carregarPastaLiberada($input->pastaId, $user, $tenant);
        $emAndamento = $this->perguntas->emAndamentoDaConversa($tenant, (int) $pasta->getId(), $user);
        if ($emAndamento !== null) {
            return self::saida($emAndamento, self::AVISO_EM_ANDAMENTO);
        }

        $contexto = $this->contextoDa($pasta, $user, $tenant);
        if ($antesDeCriar !== null) {
            $antesDeCriar();
        }

        $pergunta = $this->registrar($input->texto, $pasta, $contexto, $user, $tenant);
        $this->enfileirador->enfileirar($pergunta->getAnalise(), $tenant);

        return self::saida($pergunta);
    }

    /** A pasta do escritório que quem pergunta pode ver, com a IA disponível para ele. */
    private function carregarPastaLiberada(int $pastaId, User $user, Tenant $tenant): Pasta
    {
        $pasta = $this->pastas->findOneBy(['id' => $pastaId, 'tenant' => $tenant]);
        if ($pasta === null) {
            throw new PastaNaoEncontradaException($pastaId);
        }

        if (!$this->permissionChecker->canAccessResource($user, $tenant, 'pasta', (int) $pasta->getId(), 'view')) {
            throw new AccessDeniedException('Sem permissão para ver esta pasta.');
        }

        $disponibilidade = $this->disponibilidade->para($user, $tenant);
        if (!$disponibilidade->estaDisponivel()) {
            throw new InteligenciaIndisponivelException($disponibilidade);
        }

        return $pasta;
    }

    /**
     * O contexto do Gestor com as decisões tomadas aqui, com sessão: financeiro só se a pessoa pode
     * vê-lo e as abas ocultas para ela fora. Sigilo lança antes de qualquer leitura.
     */
    private function contextoDa(Pasta $pasta, User $user, Tenant $tenant): ContextoDaPasta
    {
        $incluirFinanceiro = $this->financeiro->podeVer($user, $tenant, $pasta);
        $ocultas = array_map(static fn (AbaDaPasta $a): string => $a->value, $this->abas->abasOcultasPara($pasta, $user));

        return $this->montador->para($tenant, $pasta, self::AGENTE, $incluirFinanceiro, $ocultas);
    }

    /** Análise `pendente` + pergunta, gravadas juntas (um flush). */
    private function registrar(string $texto, Pasta $pasta, ContextoDaPasta $contexto, User $user, Tenant $tenant): PerguntaDaConversa
    {
        $analise = new AnaliseDeInteligencia(
            tenant: $tenant,
            solicitante: $user,
            tipo: TipoDeAnalise::PerguntaLivre,
            alvoTipo: AnaliseDeInteligencia::ALVO_PASTA,
            alvoId: (int) $pasta->getId(),
            versaoDoPrompt: PromptDaPergunta::VERSAO,
            contextoHash: $contexto->hash,
            contextoResumo: $contexto->resumo(),
            agente: self::AGENTE,
        );
        $pergunta = new PerguntaDaConversa($tenant, (int) $pasta->getId(), $user, $texto, $analise);
        $this->analises->salvar($analise);
        $this->perguntas->salvar($pergunta, true);

        return $pergunta;
    }

    private static function saida(PerguntaDaConversa $pergunta, ?string $aviso = null): PerguntaEnviadaOutput
    {
        return new PerguntaEnviadaOutput(
            MensagemDaConversaOutput::fromEntity($pergunta),
            AnaliseOutput::fromEntity($pergunta->getAnalise(), $aviso),
        );
    }
}
