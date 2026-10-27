<?php

declare(strict_types=1);

namespace App\Inteligencia\Fluxo;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Inteligencia\Contexto\MontadorDeContextoDaPasta;
use App\Inteligencia\Contexto\MontadorDeContextoDoPush;
use App\Inteligencia\Contract\ConversaDaIaNaCentral;
use App\Inteligencia\DTO\ContextoDaPasta;
use App\Inteligencia\DTO\PedidoDeLinguagem;
use App\Inteligencia\DTO\ResultadoDaAnalise;
use App\Inteligencia\Entity\AnaliseDeInteligencia;
use App\Inteligencia\Enum\Agente;
use App\Inteligencia\Enum\TipoDeAnalise;
use App\Inteligencia\Exception\ContextoBloqueadoException;
use App\Inteligencia\Prompt\PromptDoAgente;
use App\Inteligencia\Prompt\PromptDoChatDaCentral;
use App\Inteligencia\Repository\ConfiguracaoDeInteligenciaRepository;
use App\Inteligencia\Service\InterpretadorDeRespostaDoChat;
use App\Inteligencia\Service\VisibilidadeDoFinanceiroDaPasta;
use App\Pasta\Entity\AbaDaPasta;
use App\Pasta\Entity\Pasta;
use App\Pasta\Repository\PastaRepository;
use App\Pasta\Service\AcessoAPasta;
use App\Pasta\Service\VisibilidadeDasAbasDaPasta;
use Psr\Clock\ClockInterface;

/**
 * O chat da Central (F3, `docs/specs/ia-chat-central.md`) como {@see FluxoDeAnaliseSemPasta}: o alvo da análise é a
 * conversa IA (`alvo_id`) e a pergunta é a mensagem de `contexto_resumo.mensagemId`. A Central entrega, pela porta
 * {@see ConversaDaIaNaCentral}, o texto da pergunta, o primeiro nome da pessoa e as até 12 mensagens anteriores; tudo
 * passa pela neutralização e pela máscara do escritório (a mesma regra da pergunta da pasta) antes de ir ao modelo.
 *
 * Sem `contexto_resumo.pastaId`, nada de pasta, equipe ou produtividade entra no pedido (prompt `chat-central-v1`).
 *
 * Com `pastaId` (PR 4a, a pasta aberta na tela): o worker NÃO confia no que a solicitação decidiu. Relê a pasta por id +
 * escritório, reconfere o vínculo (pela porta, que devolve null se encerrado), `AcessoAPasta::podeVer`, financeiro e abas
 * ocultas; se a pessoa perdeu o acesso, o financeiro ou passou a ter uma aba oculta que a solicitação deixou ler, é
 * `\LogicException` com motivo fixo (o handler marca `falhou` SEM chamar o provedor). O financeiro e as abas nunca
 * AUMENTAM: vale o que a solicitação gravou, no máximo mais apertado. O contexto vem do `MontadorDeContextoDaPasta` com o
 * Gestor (segredo de justiça lança antes de ler) e o prompt é o `chat-central-v2`. O histórico leva SÓ as perguntas da
 * própria pessoa (nunca a fala anterior da IA, que pode ter lido outra pasta ou o financeiro).
 *
 * Com `contexto_resumo.escopo = 'clientes'` (PR 4c, prompt `chat-central-v3`): o worker repete o aceite do escritório e relê CADA
 * pasta de `pastasIds` por id + escritório, reconferindo `podeVer`, financeiro e abas POR pasta (nada herdado da aberta). Pasta
 * aberta inválida ou com segredo = `falhou` sem chamar o provedor; pasta adicional inválida ou com segredo é omitida e
 * contada (`naoConsultadas`, só o número vai ao prompt). Orçamento: aberta
 * {@see MontadorDeContextoDaPasta::ORCAMENTO_PASTA_ABERTA_NO_CRUZAMENTO}, cada adicional
 * {@see MontadorDeContextoDaPasta::ORCAMENTO_PASTA_ADICIONAL_NO_CRUZAMENTO}.
 *
 * `contexto_resumo` guarda só ids, flags e contagens, nunca texto nem NUP.
 * Pergunta que a Central não confirma (conversa/mensagem de outro, vínculo encerrado) é `\LogicException`: o handler
 * marca `falhou` SEM chamar o provedor. A resposta e a conclusão da análise são gravadas na mesma transação
 * ({@see aoConcluir()} roda dentro dela): resposta gravada sem análise concluída, ou o contrário, cobraria de novo.
 */
final class FluxoDoChatDaCentral implements FluxoDeAnaliseSemPasta
{
    private const ESCOPO_CLIENTES = 'clientes';

    /** Adicionais além da aberta (o teto da solicitação, `PastasDosClientesDaIa::LIMITE_PASTAS`, menos a aberta). */
    private const MAXIMO_DE_ADICIONAIS = 4;

    public function __construct(
        private readonly ConversaDaIaNaCentral $central,
        private readonly PromptDoChatDaCentral $prompt,
        private readonly InterpretadorDeRespostaDoChat $interpretador,
        private readonly MontadorDeContextoDoPush $montador,
        private readonly ClockInterface $relogio,
        private readonly PastaRepository $pastas,
        private readonly AcessoAPasta $acessoAPasta,
        private readonly VisibilidadeDoFinanceiroDaPasta $financeiro,
        private readonly VisibilidadeDasAbasDaPasta $abas,
        private readonly MontadorDeContextoDaPasta $montadorDaPasta,
        private readonly PromptDoAgente $agente,
        private readonly ConfiguracaoDeInteligenciaRepository $configuracoes,
    ) {
    }

    public function tipo(): TipoDeAnalise
    {
        return TipoDeAnalise::ChatDaCentral;
    }

    public function preparar(AnaliseDeInteligencia $analise, Tenant $tenant): ?PedidoDeLinguagem
    {
        $solicitante = $analise->getSolicitante() ?? throw new \LogicException('Chat sem solicitante.');
        $conversaId = $analise->getAlvoId();
        $mensagemId = self::mensagemDaPergunta($analise);

        $contexto = $this->central->contextoDaPergunta($tenant, $conversaId, $mensagemId, $solicitante)
            ?? throw new \LogicException('Chat sem pergunta válida nesta conversa.');

        $pastaId = (int) ($analise->getContextoResumo()['pastaId'] ?? 0);
        $comPasta = $pastaId > 0;

        $historico = $this->historicoProtegido($tenant, $contexto['historico'], $comPasta);
        $pergunta = $this->protegido($tenant, $contexto['texto']);

        $resumo = ['conversaId' => $conversaId, 'mensagemId' => $mensagemId, 'historico' => count($historico), 'total' => 1];
        $hash = $conversaId . '|' . $mensagemId;
        $contextos = [];
        $naoConsultadas = 0;
        if ($comPasta) {
            [$contextos, $decisao, $naoConsultadas] = $this->contextosDasPastas($analise, $tenant, $solicitante, $pastaId);
            $resumo += ['pastaId' => $pastaId] + $decisao;
            $hash .= '|pasta:' . $pastaId . '|' . implode('|', array_map(static fn (ContextoDaPasta $c): string => $c->hash, $contextos));
        }

        // Só ids, flags e contagens: o texto da conversa é da pessoa e não se grava (regra 6 da Inteligência).
        $analise->registrarContexto(hash('sha256', $hash), $resumo);

        if ($pergunta === '') {
            return null;
        }

        return $this->pedido($this->protegido($tenant, $contexto['nomeDaPessoa']), $historico, $pergunta, $contextos, $resumo['escopo'] ?? null, $naoConsultadas);
    }

    /**
     * @param list<array{de: string, texto: string}> $historico
     * @param list<ContextoDaPasta>                  $contextos vazio = sem pasta
     */
    private function pedido(string $nome, array $historico, string $pergunta, array $contextos, mixed $escopo, int $naoConsultadas): PedidoDeLinguagem
    {
        if ($contextos === []) {
            return $this->prompt->montar($nome, $historico, $pergunta);
        }
        if ($escopo === self::ESCOPO_CLIENTES) {
            $blocos = array_map(fn (ContextoDaPasta $c): array => $this->agente->blocosDoContexto($c), $contextos);

            return $this->prompt->montarComClientes($nome, $historico, $pergunta, $blocos, $naoConsultadas);
        }

        return $this->prompt->montarComPasta($nome, $historico, $pergunta, $this->agente->blocosDoContexto($contextos[0]));
    }

    /** O id da mensagem da pergunta, no resumo; análise sem conversa como alvo ou sem a mensagem é inconsistente. */
    private static function mensagemDaPergunta(AnaliseDeInteligencia $analise): int
    {
        if ($analise->getAlvoTipo() !== AnaliseDeInteligencia::ALVO_CONVERSA_IA) {
            throw new \LogicException('Chat com alvo que não é uma conversa.');
        }
        $mensagemId = (int) ($analise->getContextoResumo()['mensagemId'] ?? 0);
        if ($mensagemId <= 0) {
            throw new \LogicException('Chat sem a mensagem da pergunta.');
        }

        return $mensagemId;
    }

    /**
     * O histórico neutralizado e mascarado. Com pasta, SÓ as falas da própria pessoa (regra 4 do PR 4: a resposta antiga
     * da IA pode ter lido outra pasta ou o financeiro).
     *
     * @param list<array{de: 'usuario'|'ia', texto: string}> $historico
     *
     * @return list<array{de: string, texto: string}>
     */
    private function historicoProtegido(Tenant $tenant, array $historico, bool $comPasta): array
    {
        $protegido = [];
        foreach ($historico as $troca) {
            if ($comPasta && $troca['de'] !== 'usuario') {
                continue;
            }
            $texto = $this->protegido($tenant, $troca['texto']);
            if ($texto !== '') {
                $protegido[] = ['de' => $troca['de'], 'texto' => $texto];
            }
        }

        return $protegido;
    }

    /**
     * Os contextos das pastas e o que vai para o resumo: a pasta aberta (decisão do topo do resumo) e, no escopo `clientes`,
     * as adicionais. A aberta é sempre o primeiro contexto.
     *
     * @return array{list<ContextoDaPasta>, array<string, mixed>, int} os contextos, o acréscimo ao resumo e as pastas não consultadas
     */
    private function contextosDasPastas(AnaliseDeInteligencia $analise, Tenant $tenant, User $solicitante, int $pastaId): array
    {
        $gravado = $analise->getContextoResumo();
        $clientes = ($gravado['escopo'] ?? null) === self::ESCOPO_CLIENTES;
        if ($clientes && !($this->configuracoes->findDoTenant($tenant)?->aceiteDeEnvioDado() ?? false)) {
            throw new \LogicException('Chat sem o aceite do escritório para cruzar pastas.');
        }

        [$aberta, $decisao] = $this->contextoDaPasta($tenant, $solicitante, $pastaId, $gravado, $clientes ? MontadorDeContextoDaPasta::ORCAMENTO_PASTA_ABERTA_NO_CRUZAMENTO : null);
        if (!$clientes) {
            return [[$aberta], $decisao, 0];
        }

        $contextos = [$aberta];
        $incluidas = [['pastaId' => $pastaId] + $decisao];
        $naoConsultadas = max(0, (int) ($gravado['naoConsultadas'] ?? 0));
        foreach ($this->adicionaisGravadas($gravado, $pastaId) as $adicional) {
            try {
                [$contextos[], ] = $this->contextoDaPasta($tenant, $solicitante, $adicional['pastaId'], $adicional, MontadorDeContextoDaPasta::ORCAMENTO_PASTA_ADICIONAL_NO_CRUZAMENTO);
                $incluidas[] = $adicional;
            } catch (\LogicException|ContextoBloqueadoException) {
                ++$naoConsultadas;   // segredo, acesso perdido ou aba nova: some em silêncio e só entra na contagem
            }
        }

        return [$contextos, $decisao + ['escopo' => self::ESCOPO_CLIENTES, 'pastasIds' => array_column($incluidas, 'pastaId'), 'pastas' => $incluidas, 'naoConsultadas' => $naoConsultadas], $naoConsultadas];
    }

    /**
     * As adicionais gravadas na solicitação (a aberta fica de fora), no máximo as do teto e sem repetir ids.
     *
     * @param array<string, mixed> $gravado
     *
     * @return list<array{pastaId: int, financeiro: bool, abasOcultas: list<string>}>
     */
    private function adicionaisGravadas(array $gravado, int $pastaAbertaId): array
    {
        $adicionais = [];
        $vistos = [$pastaAbertaId => true];
        foreach (\is_array($gravado['pastas'] ?? null) ? $gravado['pastas'] : [] as $entrada) {
            $id = \is_array($entrada) ? (int) ($entrada['pastaId'] ?? 0) : 0;
            if ($id <= 0 || isset($vistos[$id]) || \count($adicionais) >= self::MAXIMO_DE_ADICIONAIS) {
                continue;
            }
            $vistos[$id] = true;
            $adicionais[] = [
                'pastaId' => $id,
                'financeiro' => ($entrada['financeiro'] ?? false) === true,
                'abasOcultas' => array_values(array_filter(\is_array($entrada['abasOcultas'] ?? null) ? $entrada['abasOcultas'] : [], 'is_string')),
            ];
        }

        return $adicionais;
    }

    /**
     * Relê a pasta, reconfere o acesso e monta o contexto do Gestor (segredo de justiça lança
     * {@see ContextoBloqueadoException} antes de ler; o handler marca `falhou`). `$gravado` traz `financeiro` e `abasOcultas`
     * decididos na solicitação PARA ESTA pasta.
     *
     * @param array<string, mixed> $gravado
     *
     * @return array{ContextoDaPasta, array{financeiro: bool, abasOcultas: list<string>}}
     */
    private function contextoDaPasta(Tenant $tenant, User $solicitante, int $pastaId, array $gravado, ?int $orcamento): array
    {
        $pasta = $this->pastaLiberada($tenant, $solicitante, $pastaId);
        [$financeiro, $abasOcultas] = $this->decisaoDaPasta($gravado, $tenant, $solicitante, $pasta);

        return [
            $this->montadorDaPasta->para($tenant, $pasta, Agente::Gestor, $financeiro, $abasOcultas, orcamento: $orcamento),
            ['financeiro' => $financeiro, 'abasOcultas' => $abasOcultas],
        ];
    }

    /**
     * A pasta do escritório que a pessoa ainda pode ver, relida por id + escritório (o worker não tem sessão).
     *
     * @throws \LogicException motivo fixo, sem id nem texto
     */
    private function pastaLiberada(Tenant $tenant, User $solicitante, int $pastaId): Pasta
    {
        $pasta = $this->pastas->findOneBy(['id' => $pastaId, 'tenant' => $tenant]);
        if ($pasta === null || $pasta->getTenant()?->getId() !== $tenant->getId()) {
            throw new \LogicException('Chat com pasta que não existe neste escritório.');
        }
        if ($pasta->estaExcluida() || !$this->acessoAPasta->podeVer($solicitante, $pasta)) {
            throw new \LogicException('Chat com pasta que a pessoa não pode mais ver.');
        }

        return $pasta;
    }

    /**
     * O financeiro e as abas ocultas que valem agora: o que a solicitação gravou, conferido contra o estado de hoje.
     * Perdeu o financeiro, ou uma aba que a solicitação deixou ler passou a ser oculta para ela: falha (nada de
     * "tirar só aquela parte"). O que a solicitação já restringiu continua restrito.
     *
     * @param array<string, mixed> $resumo o `contexto_resumo` (pasta aberta) ou a entrada da pasta adicional
     *
     * @return array{bool, list<string>}
     */
    private function decisaoDaPasta(array $resumo, Tenant $tenant, User $solicitante, Pasta $pasta): array
    {

        $financeiroGravado = ($resumo['financeiro'] ?? false) === true;
        $financeiroAgora = $this->financeiro->podeVer($solicitante, $tenant, $pasta);
        if ($financeiroGravado && !$financeiroAgora) {
            throw new \LogicException('Chat com pasta cujo financeiro a pessoa não pode mais ver.');
        }

        $gravadas = array_values(array_filter(\is_array($resumo['abasOcultas'] ?? null) ? $resumo['abasOcultas'] : [], 'is_string'));
        $agora = array_map(static fn (AbaDaPasta $a): string => $a->value, $this->abas->abasOcultasPara($pasta, $solicitante));
        if (array_diff($agora, $gravadas) !== []) {
            throw new \LogicException('Chat com pasta cuja aba a pessoa não pode mais ver.');
        }

        return [$financeiroGravado, $gravadas];
    }

    public function motivoDeContextoVazio(): string
    {
        return 'pergunta vazia';
    }

    public function interpretar(string $texto): ResultadoDaAnalise
    {
        return $this->interpretador->interpretar($texto);
    }

    public function aoConcluir(AnaliseDeInteligencia $analise, Tenant $tenant, ResultadoDaAnalise $resultado): void
    {
        $solicitante = $analise->getSolicitante() ?? throw new \LogicException('Chat sem solicitante.');

        $linhas = [$resultado->resumo];
        foreach ($resultado->pontos as $ponto) {
            $linhas[] = '• ' . $ponto['texto'];
        }

        $this->central->gravarResposta($tenant, $analise->getAlvoId(), $solicitante, implode("\n", $linhas), $this->relogio->now());
    }

    /** Neutralizado e mascarado pela preferência atual do escritório (vazio vira texto vazio). */
    private function protegido(Tenant $tenant, string $texto): string
    {
        return $this->montador->analiseAnterior($tenant, $texto) ?? '';
    }
}
