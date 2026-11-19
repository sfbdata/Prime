<?php

declare(strict_types=1);

namespace App\Conector\Ferramenta;

use App\Agenda\Entity\Evento;
use App\Agenda\Exception\CompromissoNaoEncontradoException;
use App\Agenda\Exception\SemPermissaoNoCompromissoException;
use App\Agenda\Repository\EventoDaAgendaRepository;
use App\Agenda\Service\PermissoesDaAgenda;
use App\Agenda\UseCase\AlterarStatusDoCompromissoUseCase;
use App\Agenda\UseCase\AvisarNoChatUseCase;
use App\Agenda\UseCase\AvisarPrazoNoChatUseCase;
use App\Agenda\UseCase\ExcluirCompromissoUseCase;
use App\Agenda\UseCase\MoverCompromissoUseCase;
use App\Agenda\UseCase\SalvarCompromissoUseCase;
use App\Central\Exception\PastaDaIaNaoEncontradaException;
use App\Central\Service\PastaDoContextoDaIa;
use App\ChatIa\Service\ProtecaoDoTextoDoChat;
use App\Conector\DTO\ChamadaDoConector;
use App\Conector\DTO\ResultadoDaConfirmacao;
use App\Conector\DTO\RespostaDaFerramenta;
use App\Conector\Exception\ErroDaFerramenta;
use App\Conector\Service\GuardaDaAcaoDoConector;
use App\Conector\Service\PropostasDoConector;
use App\Conector\Service\ServidorDeAutorizacao;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Inteligencia\Exception\ContextoBloqueadoException;
use App\Pasta\Entity\Pasta;
use App\Pasta\Repository\PastaPrazoRepository;
use App\Pasta\Repository\PastaRepository;
use App\Repository\UserRepository;

/**
 * `gravar_na_agenda` (`docs/specs/mcp-remoto-oauth.md`, 6.6, PR 13). A `acao` é a chave da {@see TabelaDeAbaEChave}:
 *
 *  - `salvar`: sem `compromisso_id` cria direto; com ele edita o compromisso, e editar é gravar por cima: vira proposta
 *    ({@see SalvarCompromissoUseCase}). O que não vem na edição fica como está;
 *  - `mover`: troca o dia (e a hora) de um compromisso, sempre por proposta ({@see MoverCompromissoUseCase});
 *  - `status`: `agendado`, `concluido` e `cancelado` gravam direto ({@see AlterarStatusDoCompromissoUseCase}; cancelar
 *    avisa os participantes, como a tela); `excluido` é o pedido de excluir e vira proposta ({@see ExcluirCompromissoUseCase});
 *  - `avisar`: avisa colegas no sino sobre um compromisso (`compromisso_id`) ou um prazo (`prazo_id`), direto
 *    ({@see AvisarNoChatUseCase}, {@see AvisarPrazoNoChatUseCase}).
 *
 * Teto (6.3): editar, mover, mudar a situação e excluir só valem para compromisso que a própria pessoa criou, mesmo
 * para quem administra o escritório: o de outra pessoa responde "não encontrado". Participantes e avisados só da equipe
 * ativa do escritório. Com pasta, a pessoa precisa poder ver a pasta e ela não pode estar em segredo de justiça.
 * {@see reconferir()} repete tudo, sem gravar, e recusa se o compromisso mudou depois da proposta.
 */
final class GravarNaAgendaFerramenta implements FerramentaDoConector, ExecutorDeProposta
{
    /**
     * As duas interfaces declaram `TAG`; sem redeclarar aqui o PHP recusa a classe (constante ambígua). Quem registra
     * a ferramenta e o executor são os atributos `AutoconfigureTag` de cada interface, não esta constante.
     */
    public const TAG = FerramentaDoConector::TAG;

    public const NOME = 'gravar_na_agenda';

    public const MOTIVO_MUDOU = 'mudou_depois_da_proposta';
    public const MUDOU = 'O que a proposta mudaria já não é o que está no BlueJus. Faça o pedido de novo.';

    public const FORA_DA_EQUIPE = 'Só pessoas da equipe ativa do escritório podem participar ou ser avisadas.';

    /** O aviso da 6.6 sobre o Google: o listener da Agenda sincroniza depois da gravação (14.h). */
    public const AVISO_DO_GOOGLE = 'O compromisso vai também para a agenda Google de quem o criou e dos participantes que ligaram a própria conta no BlueJus, como na tela da Agenda.';

    public function __construct(
        private readonly GuardaDaAcaoDoConector $guarda,
        private readonly PastaRepository $pastas,
        private readonly PastaPrazoRepository $prazos,
        private readonly PastaDoContextoDaIa $liberacao,
        private readonly UserRepository $usuarios,
        private readonly EventoDaAgendaRepository $eventos,
        private readonly PermissoesDaAgenda $permissoesDaAgenda,
        private readonly ProtecaoDoTextoDoChat $protecao,
        private readonly PropostasDoConector $propostas,
        private readonly SalvarCompromissoUseCase $salvarCompromisso,
        private readonly MoverCompromissoUseCase $moverCompromisso,
        private readonly AlterarStatusDoCompromissoUseCase $alterarStatus,
        private readonly ExcluirCompromissoUseCase $excluirCompromisso,
        private readonly AvisarNoChatUseCase $avisarNoChat,
        private readonly AvisarPrazoNoChatUseCase $avisarPrazoNoChat,
    ) {
    }

    public function nome(): string
    {
        return self::NOME;
    }

    public function titulo(): string
    {
        return 'Gravar na agenda';
    }

    public function descricao(): string
    {
        return 'Grava na Agenda do BlueJus, só em compromissos que a própria pessoa criou (o de outra pessoa responde "não encontrado"). '
            . 'Ações: salvar (sem compromisso_id cria na hora, com titulo e data; com compromisso_id edita, e a edição vira proposta; o que não for mandado fica como está), '
            . 'mover (troca o dia e a hora; vira proposta), status (agendado, concluido e cancelado gravam na hora, e cancelar avisa os participantes; excluido vira proposta) '
            . 'e avisar (avisa colegas no sino sobre um compromisso ou um prazo, na hora). '
            . 'Proposta não grava: a pessoa precisa aprovar com confirmar_acao. Participantes e avisados só da equipe ativa do escritório. '
            . 'O compromisso gravado vai também para a agenda Google de quem o criou e dos participantes que ligaram a conta. '
            . 'Pasta que a pessoa não vê responde "não encontrado"; pasta em segredo de justiça não sai pelo conector.';
    }

    public function esquemaDeEntrada(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'acao' => ['type' => 'string', 'enum' => GravarNaAgendaEntrada::ACOES, 'description' => 'O que fazer na Agenda.'],
                'compromisso_id' => ['type' => 'integer', 'minimum' => 1, 'description' => 'O id do compromisso (veja evento_id em ler_agenda). Em salvar, só para editar.'],
                'prazo_id' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Só em avisar: o id do prazo (veja prazo_id em ler_agenda), no lugar de compromisso_id.'],
                'titulo' => ['type' => 'string', 'maxLength' => GravarNaAgendaEntrada::TAMANHO_DO_TITULO, 'description' => 'Em salvar: o título. Obrigatório ao criar.'],
                'tipo' => ['type' => 'string', 'enum' => GravarNaAgendaEntrada::TIPOS, 'description' => 'Em salvar: meus (compromisso), aud (audiência), reun (reunião), prazo, urg (urgente), pess (pessoal) ou lemb (lembrete). Padrão: meus.'],
                'data' => ['type' => 'string', 'format' => 'date', 'description' => 'Em salvar e mover: o dia, AAAA-MM-DD. Obrigatório ao criar e ao mover.'],
                'dia_inteiro' => ['type' => 'boolean', 'description' => 'Em salvar: compromisso de dia inteiro. Ao criar, vale true se não houver inicio.'],
                'inicio' => ['type' => 'string', 'maxLength' => 5, 'description' => 'Em salvar: hora de início, HH:MM.'],
                'fim' => ['type' => 'string', 'maxLength' => 5, 'description' => 'Em salvar: hora de fim, HH:MM (vazio: dura uma hora, ou mantém a duração ao editar).'],
                'dias' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 366, 'description' => 'Em salvar: quantos dias depois do início o compromisso termina.'],
                'visibilidade' => ['type' => 'string', 'enum' => GravarNaAgendaEntrada::VISIBILIDADES, 'description' => 'Em salvar: equipe (padrão) ou so_eu (só a própria pessoa vê).'],
                'pasta_id' => ['type' => ['integer', 'null'], 'minimum' => 1, 'description' => 'Em salvar: o id da pasta do compromisso (veja buscar_pastas). null tira a pasta.'],
                'participantes' => ['type' => 'array', 'items' => ['type' => 'integer', 'minimum' => 1], 'maxItems' => GravarNaAgendaEntrada::MAXIMO_DE_PESSOAS, 'description' => 'Em salvar: ids de pessoas da equipe ativa que participam (quem entra é avisado).'],
                'lembrete_minutos' => ['type' => 'integer', 'enum' => [0, 10, 15, 30, 60, 1440], 'description' => 'Em salvar: lembrete em minutos antes do início; 0 é sem lembrete. Padrão: 15.'],
                'observacao' => ['type' => 'string', 'maxLength' => GravarNaAgendaEntrada::TAMANHO_DA_OBSERVACAO, 'description' => 'Em salvar: observações do compromisso.'],
                'hora' => ['type' => 'string', 'maxLength' => 5, 'description' => 'Em mover: a nova hora, HH:MM (sem ela, a hora não muda).'],
                'status' => ['type' => 'string', 'enum' => GravarNaAgendaEntrada::STATUS, 'description' => 'Em status: agendado (reabrir), concluido, cancelado ou excluido (exclui o compromisso; vira proposta).'],
                'colegas' => ['type' => 'array', 'items' => ['type' => 'integer', 'minimum' => 1], 'minItems' => 1, 'maxItems' => GravarNaAgendaEntrada::MAXIMO_DE_PESSOAS, 'description' => 'Em avisar: ids das pessoas da equipe a avisar.'],
            ],
            'required' => ['acao'],
            'additionalProperties' => false,
        ];
    }

    public function executar(array $argumentos, ChamadaDoConector $chamada): RespostaDaFerramenta
    {
        // Segunda camada: a `ScopePolicy` já deu 403 antes do SDK a quem não tem `mcp:escrita` (5.1).
        if (!$chamada->temEscopo(ServidorDeAutorizacao::ESCOPO_ESCRITA)) {
            throw ErroDaFerramenta::semPermissao();
        }
        [$acao, $campos] = GravarNaAgendaEntrada::ler($argumentos);
        $this->guarda->exigir($chamada, self::NOME, $acao);

        return match ($acao) {
            'salvar' => $this->salvar($campos, $chamada),
            'mover' => $this->mover($campos, $chamada),
            'status' => $this->status($campos, $chamada),
            default => $this->avisar($campos, $chamada),
        };
    }

    public function ferramenta(): string
    {
        return self::NOME;
    }

    public function reconferir(string $acao, array $dados, ChamadaDoConector $chamada): void
    {
        $id = $dados['compromisso_id'] ?? null;
        if (!\is_int($id) || !\in_array($acao, ['salvar', 'mover', 'status'], true)) {
            throw ErroDaFerramenta::entradaInvalida();
        }
        $evento = $this->eventoDaPessoa($id, $chamada, $acao);
        $anterior = $dados['anterior'] ?? null;
        // O hash ignora a ordem das chaves (o banco pode devolver o JSON em outra ordem).
        if (!\is_array($anterior) || PropostasDoConector::hashDosDados($anterior) !== PropostasDoConector::hashDosDados(GravarNaAgendaCompromisso::estado($evento))) {
            throw new ErroDaFerramenta(self::MOTIVO_MUDOU, self::MUDOU);
        }

        match ($acao) {
            'salvar' => $this->reconferirEdicao($evento, $dados, $chamada),
            'mover' => $this->exigirSemRepeticao($evento),
            default => ($dados['operacao'] ?? null) === 'excluir' ? null : throw ErroDaFerramenta::entradaInvalida(),
        };
    }

    public function executarConfirmada(string $acao, array $dados, ChamadaDoConector $chamada): ResultadoDaConfirmacao
    {
        $id = $dados['compromisso_id'] ?? null;
        if (!\is_int($id)) {
            throw ErroDaFerramenta::entradaInvalida();
        }
        $evento = $this->eventoDaPessoa($id, $chamada, $acao);
        $pessoa = $chamada->contexto->pessoa;
        $escritorio = $chamada->contexto->escritorio;

        $extras = match (true) {
            $acao === 'salvar' => ['data' => $this->gravarEdicao($evento, $dados, $chamada)],
            $acao === 'mover' => ['data' => $this->gravando(fn (): string => $this->moverCompromisso->executar($id, (string) $dados['data'], $dados['hora'] ?? null, $pessoa, $escritorio))],
            ($dados['operacao'] ?? null) === 'excluir' => $this->gravarExclusao($id, $pessoa, $escritorio),
            default => throw ErroDaFerramenta::entradaInvalida(),
        };

        return new ResultadoDaConfirmacao(
            ['compromisso_id' => $id, 'acao' => $acao] + $extras + ['google' => self::AVISO_DO_GOOGLE],
            [$id],
            ['gravados' => 1],
            ['compromisso' => [$id]],
        );
    }

    /**
     * @param array<string, mixed> $campos
     *
     * @throws ErroDaFerramenta
     */
    private function salvar(array $campos, ChamadaDoConector $chamada): RespostaDaFerramenta
    {
        $escritorio = $chamada->contexto->escritorio;
        $id = $campos['compromisso_id'] ?? null;
        $evento = $id === null ? null : $this->eventoDaPessoa((int) $id, $chamada, 'salvar');
        $this->exigirDaEquipe($campos['participantes'] ?? [], $escritorio);
        $final = GravarNaAgendaCompromisso::consolidar($evento, $campos);
        $pasta = $this->conferirPastaDoPedido($final, $evento, $chamada);
        if ($evento instanceof Evento) {
            return $this->proporEdicao($evento, $final, $campos['participantes'] ?? [], $pasta, $chamada);
        }
        $input = GravarNaAgendaCompromisso::input($final, (string) $pasta?->getNup(), null);
        $salvo = $this->gravando(fn () => $this->salvarCompromisso->executar($input, null, $chamada->contexto->pessoa, $escritorio, $this->verPastas($chamada)));

        return $this->direta('salvar', $salvo->id, ['data' => $salvo->data, 'avisados' => $salvo->avisados], true);
    }

    /**
     * @param array<string, mixed> $final
     * @param list<int>            $pedidos os participantes que a pessoa mandou (os que o compromisso já tinha não entram na conferência)
     */
    private function proporEdicao(Evento $evento, array $final, array $pedidos, ?Pasta $pasta, ChamadaDoConector $chamada): RespostaDaFerramenta
    {
        $escritorio = $chamada->contexto->escritorio;
        $ativos = $this->usuarios->findColaboradoresAtivosPorTenant($escritorio);
        $nomes = [];
        foreach ([...$evento->getParticipantes()->toArray(), ...$ativos] as $u) {
            $nomes[(int) $u->getId()] = (string) $u->getFullName();
        }
        $pastas = [];
        foreach ([$evento->getPasta(), $pasta] as $p) {
            if ($p !== null) {
                $pastas[(int) $p->getId()] = (string) $p->getNup();
            }
        }
        $resumo = sprintf(
            'Compromisso "%s" (hoje em %s). %s',
            $this->protecao->rotulo($escritorio, $evento->getTitulo(), 'sem título'),
            GravarNaAgendaCompromisso::quandoDoEvento($evento),
            (new GravarNaAgendaResumo($this->protecao, $escritorio))->edicao(
                GravarNaAgendaCompromisso::doEvento($evento),
                $final,
                $nomes,
                $pastas,
                array_map(static fn (User $u): int => (int) $u->getId(), $ativos),
            ),
        );

        return $this->propostas->propor($chamada, self::NOME, 'salvar', [
            'compromisso_id' => (int) $evento->getId(),
            'final' => $final,
            'pedidos' => $pedidos,
            'anterior' => GravarNaAgendaCompromisso::estado($evento),
        ], $resumo);
    }

    /**
     * @param array<string, mixed> $dados
     *
     * @throws ErroDaFerramenta
     */
    private function reconferirEdicao(Evento $evento, array $dados, ChamadaDoConector $chamada): void
    {
        $final = $dados['final'] ?? null;
        $pedidos = $dados['pedidos'] ?? null;
        if (!\is_array($final) || !\is_array($pedidos)) {
            throw ErroDaFerramenta::entradaInvalida();
        }
        $this->exigirDaEquipe(array_map('intval', $pedidos), $chamada->contexto->escritorio);
        $this->conferirPastaDoPedido($final, $evento, $chamada);
    }

    /**
     * @param array<string, mixed> $dados
     *
     * @return string o dia do compromisso
     */
    private function gravarEdicao(Evento $evento, array $dados, ChamadaDoConector $chamada): string
    {
        $final = $dados['final'];
        $escritorio = $chamada->contexto->escritorio;
        $input = GravarNaAgendaCompromisso::input($final, $this->nupDa($final['pasta_id'], $escritorio), $evento);
        $salvo = $this->gravando(fn () => $this->salvarCompromisso->executar($input, (int) $evento->getId(), $chamada->contexto->pessoa, $escritorio, $this->verPastas($chamada)));

        return $salvo->data;
    }

    /**
     * @return array<string, mixed>
     */
    private function gravarExclusao(int $id, User $pessoa, Tenant $escritorio): array
    {
        $this->gravando(fn () => $this->excluirCompromisso->executar($id, $pessoa, $escritorio));

        return ['operacao' => 'excluir', 'destino' => 'excluido'];
    }

    /**
     * @param array<string, mixed> $campos
     *
     * @throws ErroDaFerramenta
     */
    private function mover(array $campos, ChamadaDoConector $chamada): RespostaDaFerramenta
    {
        $evento = $this->eventoDaPessoa((int) $campos['compromisso_id'], $chamada, 'mover');
        $this->exigirSemRepeticao($evento);
        $hora = isset($campos['hora']) && $campos['hora'] !== '' ? (string) $campos['hora'] : null;
        $resumo = sprintf(
            'Mover o compromisso "%s" de %s para %s.',
            $this->protecao->rotulo($chamada->contexto->escritorio, $evento->getTitulo(), 'sem título'),
            GravarNaAgendaCompromisso::quandoDoEvento($evento),
            self::quandoDoMovimento($evento, (string) $campos['data'], $hora),
        );

        return $this->propostas->propor($chamada, self::NOME, 'mover', [
            'compromisso_id' => (int) $evento->getId(),
            'data' => $campos['data'],
            'hora' => $hora,
            'anterior' => GravarNaAgendaCompromisso::estado($evento),
        ], $resumo);
    }

    private static function quandoDoMovimento(Evento $evento, string $dia, ?string $hora): string
    {
        $inicio = $evento->getDataInicio() ?? new \DateTimeImmutable();
        $horario = $evento->isDiaInteiro() || $hora === null ? $inicio->format('H:i') : $hora;

        return GravarNaAgendaCompromisso::quando(new \DateTimeImmutable($dia . ' ' . $horario), $evento->isDiaInteiro());
    }

    /**
     * @param array<string, mixed> $campos
     *
     * @throws ErroDaFerramenta
     */
    private function status(array $campos, ChamadaDoConector $chamada): RespostaDaFerramenta
    {
        $evento = $this->eventoDaPessoa((int) $campos['compromisso_id'], $chamada, 'status');
        $status = (string) $campos['status'];
        if ($status === 'excluido') {
            return $this->propostas->propor($chamada, self::NOME, 'status', [
                'compromisso_id' => (int) $evento->getId(),
                'operacao' => 'excluir',
                'anterior' => GravarNaAgendaCompromisso::estado($evento),
            ], sprintf(
                'Excluir o compromisso "%s" (%s) da agenda de todos. Não há como desfazer.',
                $this->protecao->rotulo($chamada->contexto->escritorio, $evento->getTitulo(), 'sem título'),
                GravarNaAgendaCompromisso::quandoDoEvento($evento),
            ));
        }
        $mudou = $evento->getStatus() !== $status;
        $this->gravando(fn () => $this->alterarStatus->executar((int) $evento->getId(), $status, $chamada->contexto->pessoa, $chamada->contexto->escritorio));

        return $this->direta('status', (int) $evento->getId(), ['status' => $status, 'mudou' => $mudou], true);
    }

    /**
     * @param array<string, mixed> $campos
     *
     * @throws ErroDaFerramenta
     */
    private function avisar(array $campos, ChamadaDoConector $chamada): RespostaDaFerramenta
    {
        $pessoa = $chamada->contexto->pessoa;
        $escritorio = $chamada->contexto->escritorio;
        $colegas = $campos['colegas'];
        $this->exigirDaEquipe($colegas, $escritorio);

        if (isset($campos['prazo_id'])) {
            $prazo = $this->prazoVisivel((int) $campos['prazo_id'], $chamada);
            $avisados = $this->gravando(fn (): int => $this->avisarPrazoNoChat->executar((int) $prazo, $colegas, $pessoa, $escritorio));

            return $this->direta('avisar', null, ['prazo_id' => (int) $campos['prazo_id'], 'avisados' => $avisados], false);
        }
        $evento = $this->eventoDaPessoa((int) $campos['compromisso_id'], $chamada, 'avisar', false);
        $avisados = $this->gravando(fn (): int => $this->avisarNoChat->executar((int) $evento->getId(), $colegas, $pessoa, $escritorio));

        return $this->direta('avisar', (int) $evento->getId(), ['avisados' => $avisados], false);
    }

    /**
     * Só quem vê os prazos na Agenda avisa sobre prazo (como a tela); a pasta do prazo segue a conferência da pasta.
     *
     * @return int o id do prazo
     *
     * @throws ErroDaFerramenta
     */
    private function prazoVisivel(int $prazoId, ChamadaDoConector $chamada): int
    {
        $escritorio = $chamada->contexto->escritorio;
        if (!$this->permissoesDaAgenda->verPrazos($chamada->contexto->pessoa, $escritorio)) {
            throw ErroDaFerramenta::naoEncontrado();
        }
        $prazo = $this->prazos->doEscritorio($prazoId, $escritorio) ?? throw ErroDaFerramenta::naoEncontrado();
        $this->conferirPasta((int) $prazo->getPasta()->getId(), $chamada, 'avisar');

        return $prazoId;
    }

    /**
     * O compromisso do escritório que a pessoa vê e, salvo em `avisar`, que ela criou (teto da 6.3: vale também para
     * quem administra); o que não é dela é "não encontrado". A pasta do compromisso segue a conferência da pasta.
     *
     * @throws ErroDaFerramenta
     */
    private function eventoDaPessoa(int $id, ChamadaDoConector $chamada, string $acao, bool $soCriador = true): Evento
    {
        $pessoa = $chamada->contexto->pessoa;
        $evento = $this->eventos->visivelPara($id, $chamada->contexto->escritorio, $pessoa);
        if ($evento === null || ($soCriador && $evento->getCriador()?->getId() !== $pessoa->getId())) {
            throw ErroDaFerramenta::naoEncontrado();
        }
        if ($evento->getPasta() !== null) {
            $this->conferirPasta((int) $evento->getPasta()->getId(), $chamada, $acao);
        }

        return $evento;
    }

    /**
     * A pasta que o pedido liga ao compromisso, conferida; a que o compromisso já tinha já foi conferida com ele.
     *
     * @param array<string, mixed> $final
     *
     * @throws ErroDaFerramenta
     */
    private function conferirPastaDoPedido(array $final, ?Evento $evento, ChamadaDoConector $chamada): ?Pasta
    {
        $pastaId = $final['pasta_id'] ?? null;
        if (!\is_int($pastaId)) {
            return null;
        }
        if ($evento?->getPasta()?->getId() !== $pastaId && !$this->verPastas($chamada)) {
            throw ErroDaFerramenta::semPermissao();
        }

        return $this->conferirPasta($pastaId, $chamada, 'salvar');
    }

    /**
     * A pasta do escritório da chamada, viva, que a pessoa pode ver, fora do segredo de justiça.
     *
     * @throws ErroDaFerramenta
     */
    private function conferirPasta(int $pastaId, ChamadaDoConector $chamada, string $acao): Pasta
    {
        $escritorio = $chamada->contexto->escritorio;
        $pasta = $this->pastas->findOneBy(['id' => $pastaId, 'tenant' => $escritorio]);
        if (!$pasta instanceof Pasta || $pasta->estaExcluida()) {
            throw ErroDaFerramenta::naoEncontrado();
        }
        $this->guarda->exigir($chamada, self::NOME, $acao, $pasta);
        try {
            $this->liberacao->liberar($chamada->contexto->pessoa, $escritorio, $pastaId);
        } catch (PastaDaIaNaoEncontradaException) {
            throw ErroDaFerramenta::naoEncontrado();
        } catch (ContextoBloqueadoException) {
            throw ErroDaFerramenta::emSegredo();
        }

        return $pasta;
    }

    private function nupDa(mixed $pastaId, Tenant $escritorio): string
    {
        if (!\is_int($pastaId)) {
            return '';
        }

        return (string) $this->pastas->findOneBy(['id' => $pastaId, 'tenant' => $escritorio])?->getNup();
    }

    private function verPastas(ChamadaDoConector $chamada): bool
    {
        return $this->permissoesDaAgenda->verPastas($chamada->contexto->pessoa, $chamada->contexto->escritorio);
    }

    /**
     * Participante e avisado só da equipe ativa do escritório da chamada: id de fora é recusa (a tela ignora em silêncio).
     *
     * @param list<int> $ids
     *
     * @throws ErroDaFerramenta
     */
    private function exigirDaEquipe(array $ids, Tenant $escritorio): void
    {
        if ($ids === []) {
            return;
        }
        $ativos = array_map(static fn (User $u): ?int => $u->getId(), $this->usuarios->findColaboradoresAtivosPorTenant($escritorio));
        if (array_diff($ids, $ativos) !== []) {
            throw new ErroDaFerramenta(ErroDaFerramenta::MOTIVO_ENTRADA_INVALIDA, self::FORA_DA_EQUIPE);
        }
    }

    /** @throws ErroDaFerramenta */
    private function exigirSemRepeticao(Evento $evento): void
    {
        if ($evento->isRecorrente()) {
            throw new ErroDaFerramenta(ErroDaFerramenta::MOTIVO_ENTRADA_INVALIDA, 'Compromisso que se repete não pode ser arrastado: edite a série.');
        }
    }

    /**
     * Roda o UseCase e traduz a recusa dele. As mensagens dos UseCases são fixas (regra de negócio); a da pasta que não
     * é do escritório vira "não encontrado".
     *
     * @template T
     *
     * @param callable(): T $gravar
     *
     * @return T
     *
     * @throws ErroDaFerramenta
     */
    private function gravando(callable $gravar): mixed
    {
        try {
            return $gravar();
        } catch (CompromissoNaoEncontradoException|SemPermissaoNoCompromissoException) {
            throw ErroDaFerramenta::naoEncontrado();
        } catch (\DomainException $e) {
            if (str_ends_with($e->getMessage(), 'neste escritório.')) {
                throw ErroDaFerramenta::naoEncontrado();
            }

            throw new ErroDaFerramenta(ErroDaFerramenta::MOTIVO_ENTRADA_INVALIDA, $e->getMessage());
        }
    }

    /**
     * @param array<string, mixed> $extras
     */
    private function direta(string $acao, ?int $compromissoId, array $extras, bool $google): RespostaDaFerramenta
    {
        $dados = ['estado' => 'gravado', 'acao' => $acao];
        if ($compromissoId !== null) {
            $dados['compromisso_id'] = $compromissoId;
        }
        $dados += $extras;
        if ($google) {
            $dados['google'] = self::AVISO_DO_GOOGLE;
        }

        return new RespostaDaFerramenta($dados, contagens: ['gravados' => 1], ids: $compromissoId === null ? [] : ['compromisso' => [$compromissoId]]);
    }
}
