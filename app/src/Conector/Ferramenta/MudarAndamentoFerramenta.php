<?php

declare(strict_types=1);

namespace App\Conector\Ferramenta;

use App\Central\Exception\PastaDaIaNaoEncontradaException;
use App\Central\Service\PastaDoContextoDaIa;
use App\ChatIa\Service\ProtecaoDoTextoDoChat;
use App\Conector\DTO\ChamadaDoConector;
use App\Conector\DTO\RespostaDaFerramenta;
use App\Conector\DTO\ResultadoDaConfirmacao;
use App\Conector\Exception\ErroDaFerramenta;
use App\Conector\Service\GuardaDaAcaoDoConector;
use App\Conector\Service\PropostasDoConector;
use App\Inteligencia\Exception\ContextoBloqueadoException;
use App\Inteligencia\Service\VisibilidadeDoFinanceiroDaPasta;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\RotuloDeEtapa;
use App\Pasta\Exception\PastaDeOutroEscritorioException;
use App\Pasta\Exception\SemPermissaoParaEditarPastaException;
use App\Pasta\Repository\PastaRepository;
use App\Pasta\Repository\RotuloDeEtapaRepository;
use App\Pasta\Service\AndamentoDaPasta;
use App\Pasta\UseCase\MudarFaseDaPastaUseCase;
use App\Pasta\UseCase\NomearEtapaDaPastaUseCase;

/**
 * `mudar_andamento` (`docs/specs/mcp-remoto-oauth.md`, 6.6, PR 10): a linha do tempo (as fases) da pasta. Ações:
 *  - `mudar_fase` (`fase`, `sem_pular?`, `motivo?`): avançar executa direto; voltar a fase e reativar a pasta
 *    finalizada (Finalizada sobre Finalizada) devolvem proposta (6.7), porque o contrato do Financeiro acompanha o
 *    andamento (chegar a Contrato o marca como assinado, voltar para antes dele o reabre);
 *  - `nomear_etapa` (`fase_de_inicio`, `texto`): dar nome a uma etapa sem nome é direto; trocar o nome dado e remover
 *    (texto vazio) vão por proposta.
 *
 * A fase vem pelo nome da trilha da via da pasta ("Contrato") ou pelo número da posição na linha do tempo (1 é a
 * primeira fase). O contrato só é citado, na resposta e no resumo, para quem vê o Financeiro da pasta
 * ({@see VisibilidadeDoFinanceiroDaPasta}, 6.2.5); para os demais, nenhuma palavra sobre ele, como na tela.
 *
 * Ordem das recusas (a mesma ao propor e ao {@see reconferir()}): a guarda da ação → a pasta por id e escritório (alheia,
 * inexistente ou na lixeira: "não encontrado") → a guarda com a pasta → o segredo de justiça → o recurso de editar a
 * pasta. Até chamar {@see PropostasDoConector::propor()} a ferramenta só LÊ. A mensagem de toda recusa de regra é uma
 * constante desta classe, escolhida pela classe da exceção do UseCase.
 */
// Não implementa o ExecutorDeProposta: as duas interfaces declaram `TAG` e o PHP recusa a classe. O
// MudarAndamentoExecutor é o executor e repassa para o reconferir() e o executarConfirmada() daqui.
final class MudarAndamentoFerramenta implements FerramentaDoConector
{
    public const NOME = 'mudar_andamento';

    public const ACOES = ['mudar_fase', 'nomear_etapa'];

    private const TAMANHO_DO_MOTIVO = 200;
    private const INDICE_DO_CONTRATO = 2;
    private const CONTRATO_ASSINADO = 'REGULAR';

    public const FASE_INVALIDA = 'Fase inválida para a linha do tempo desta pasta. Use o nome da fase ou o número da posição dela.';
    public const JA_NA_FASE = 'A pasta já está nesta fase.';
    public const SEM_MUDANCA = 'Nada a mudar: a etapa já tem este nome, ou não tem nome para remover.';
    public const TEXTO_LONGO = 'O nome da etapa passou do tamanho máximo.';
    public const TEXTO_INVALIDO = 'O nome da etapa tem caracteres inválidos.';
    public const MUDOU = 'A pasta mudou desde a proposta. Peça de novo.';
    public const RECUSA_DO_USECASE = 'Não foi possível concluir a mudança do andamento. Confira os dados e tente de novo.';

    private const CAMPOS_DA_ACAO = [
        'mudar_fase' => ['fase', 'sem_pular', 'motivo'],
        'nomear_etapa' => ['fase_de_inicio', 'texto'],
    ];

    public function __construct(
        private readonly GuardaDaAcaoDoConector $guarda,
        private readonly PastaRepository $pastas,
        private readonly PastaDoContextoDaIa $liberacao,
        private readonly ProtecaoDoTextoDoChat $protecao,
        private readonly AndamentoDaPasta $andamento,
        private readonly RotuloDeEtapaRepository $rotulos,
        private readonly VisibilidadeDoFinanceiroDaPasta $financeiro,
        private readonly PropostasDoConector $propostas,
        private readonly MudarFaseDaPastaUseCase $mudarFase,
        private readonly NomearEtapaDaPastaUseCase $nomearEtapa,
    ) {
    }

    public function nome(): string
    {
        return self::NOME;
    }

    public function titulo(): string
    {
        return 'Mudar o andamento da pasta';
    }

    public function descricao(): string
    {
        return 'Muda o andamento (as fases) de uma pasta do escritório (pasta_id é obrigatório). Ações: mudar_fase (fase pelo nome da trilha da pasta, '
            . 'como "Contrato", ou pelo número da posição, 1 é a primeira; sem_pular e motivo são opcionais) e nomear_etapa (fase_de_inicio, a fase em que a etapa '
            . 'começa, e texto, o nome da etapa com até ' . RotuloDeEtapa::TAMANHO_MAXIMO . ' letras; texto vazio remove o nome). Avançar a fase e dar nome a uma etapa sem nome '
            . 'acontecem na hora. Voltar a fase, reativar uma pasta finalizada, trocar ou remover um nome já dado ficam esperando o sim da pessoa: mostre o resumo e '
            . 'chame confirmar_acao só se ela disser sim. Pasta de outro escritório, na lixeira ou que a pessoa não vê responde "não encontrado"; '
            . 'pasta em segredo de justiça não sai pelo conector.';
    }

    public function esquemaDeEntrada(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'acao' => ['type' => 'string', 'enum' => self::ACOES, 'description' => 'O que fazer.'],
                'pasta_id' => ['type' => 'integer', 'minimum' => 1, 'description' => 'O id da pasta (veja buscar_pastas).'],
                'fase' => ['type' => ['string', 'integer'], 'description' => 'Na acao mudar_fase: o nome da fase na trilha da pasta (ex.: Contrato) ou o número da posição dela (1 é a primeira).'],
                'sem_pular' => ['type' => 'boolean', 'description' => 'Na acao mudar_fase: com verdadeiro, as fases do meio não ficam marcadas como puladas.'],
                'motivo' => ['type' => 'string', 'maxLength' => self::TAMANHO_DO_MOTIVO, 'description' => 'Na acao mudar_fase: o motivo, que vai para o Registro da pasta.'],
                'fase_de_inicio' => ['type' => ['string', 'integer'], 'description' => 'Na acao nomear_etapa: a fase em que a etapa começa (nome ou número da posição).'],
                'texto' => ['type' => 'string', 'maxLength' => 100, 'description' => 'Na acao nomear_etapa: o nome da etapa, até ' . RotuloDeEtapa::TAMANHO_MAXIMO . ' letras; vazio remove o nome.'],
            ],
            'required' => ['acao', 'pasta_id'],
            'additionalProperties' => false,
        ];
    }

    public function executar(array $argumentos, ChamadaDoConector $chamada): RespostaDaFerramenta
    {
        [$acao, $pastaId, $campos] = self::entrada($argumentos);
        $pasta = $this->pastaConferida($chamada, $acao, $pastaId);

        return $acao === 'mudar_fase'
            ? $this->fase($pasta, $chamada, $campos)
            : $this->etapa($pasta, $chamada, $campos);
    }

    public function reconferir(string $acao, array $dados, ChamadaDoConector $chamada): void
    {
        $pasta = $this->pastaConferida($chamada, $acao, self::inteiroDosDados($dados, 'pasta_id'));
        $estado = $this->andamento->estado($pasta, $chamada->contexto->escritorio);
        $ultima = \count($estado['fases']) - 1;
        if ($acao === 'mudar_fase') {
            self::reconferirFase($pasta, $estado['atual'], $ultima, $dados);

            return;
        }
        $origem = self::inteiroDosDados($dados, 'fase_de_inicio');
        if ($origem < 0 || $origem >= $ultima) {
            throw ErroDaFerramenta::recusada(self::FASE_INVALIDA);
        }
        if ($this->textoDaEtapa($pasta, $estado['via'], $origem, $chamada) !== ($dados['texto_atual'] ?? null)) {
            throw ErroDaFerramenta::recusada(self::MUDOU);
        }
    }

    /**
     * Voltar ou reativar: a fase pedida ainda existe, e a fase atual e o contrato do Financeiro são os da proposta (o
     * resumo disse se o contrato volta a ficar pendente; se ele mudou, o resumo já não vale).
     *
     * @param array<string, mixed> $dados
     *
     * @throws ErroDaFerramenta
     */
    private static function reconferirFase(Pasta $pasta, int $atual, int $ultima, array $dados): void
    {
        $alvo = self::inteiroDosDados($dados, 'fase');
        if ($alvo < 0 || $alvo > $ultima) {
            throw ErroDaFerramenta::recusada(self::FASE_INVALIDA);
        }
        if ($atual !== self::inteiroDosDados($dados, 'fase_atual') || $pasta->getSituacaoContrato() !== ($dados['contrato'] ?? null)) {
            throw ErroDaFerramenta::recusada(self::MUDOU);
        }
    }

    public function executarConfirmada(string $acao, array $dados, ChamadaDoConector $chamada): ResultadoDaConfirmacao
    {
        $pasta = $this->pastaConferida($chamada, $acao, self::inteiroDosDados($dados, 'pasta_id'));
        $resposta = $acao === 'mudar_fase'
            ? $this->aplicarFase($pasta, $chamada, self::inteiroDosDados($dados, 'fase'), ($dados['sem_pular'] ?? false) === true, (string) ($dados['motivo'] ?? ''))
            : $this->aplicarEtapa($pasta, $chamada, self::inteiroDosDados($dados, 'fase_de_inicio'), (string) ($dados['texto'] ?? ''));
        $id = (int) $pasta->getId();

        return new ResultadoDaConfirmacao($resposta, [$id], ['pasta' => 1], ['pasta' => [$id]]);
    }

    /**
     * A ação, o id da pasta e os campos da ação, todos conferidos.
     *
     * @param array<string, mixed> $argumentos
     *
     * @return array{0: string, 1: int, 2: array<string, mixed>}
     *
     * @throws ErroDaFerramenta
     */
    private static function entrada(array $argumentos): array
    {
        $acao = $argumentos['acao'] ?? null;
        if (!\is_string($acao) || !isset(self::CAMPOS_DA_ACAO[$acao])) {
            throw ErroDaFerramenta::entradaInvalida();
        }
        $pastaId = $argumentos['pasta_id'] ?? null;
        if (!\is_int($pastaId) || $pastaId < 1) {
            throw ErroDaFerramenta::entradaInvalida();
        }
        unset($argumentos['acao'], $argumentos['pasta_id']);
        if (array_diff(array_keys($argumentos), self::CAMPOS_DA_ACAO[$acao]) !== []) {
            throw ErroDaFerramenta::entradaInvalida();
        }
        $obrigatorio = $acao === 'mudar_fase' ? 'fase' : 'fase_de_inicio';
        if (!isset($argumentos[$obrigatorio]) || ($acao === 'nomear_etapa' && !\is_string($argumentos['texto'] ?? null))) {
            throw ErroDaFerramenta::entradaInvalida();
        }
        self::conferirTipos($argumentos);

        return [$acao, $pastaId, $argumentos];
    }

    /**
     * @param array<string, mixed> $campos
     *
     * @throws ErroDaFerramenta
     */
    private static function conferirTipos(array $campos): void
    {
        foreach (['fase', 'fase_de_inicio'] as $fase) {
            if (isset($campos[$fase]) && !\is_int($campos[$fase]) && !\is_string($campos[$fase])) {
                throw ErroDaFerramenta::entradaInvalida();
            }
        }
        if (isset($campos['sem_pular']) && !\is_bool($campos['sem_pular'])) {
            throw ErroDaFerramenta::entradaInvalida();
        }
        $motivo = $campos['motivo'] ?? '';
        if (!\is_string($motivo) || mb_strlen($motivo) > self::TAMANHO_DO_MOTIVO) {
            throw ErroDaFerramenta::entradaInvalida();
        }
        $texto = $campos['texto'] ?? '';
        if (!\is_string($texto) || mb_strlen($texto) > 100) {
            throw ErroDaFerramenta::entradaInvalida();
        }
    }

    /** @param array<string, mixed> $dados */
    private static function inteiroDosDados(array $dados, string $campo): int
    {
        $valor = $dados[$campo] ?? null;

        return \is_int($valor) ? $valor : throw ErroDaFerramenta::entradaInvalida();
    }

    /**
     * A pasta já conferida, na ordem da classe: guarda da ação, pasta por id e escritório, guarda com a pasta, segredo e
     * recurso de editar.
     *
     * @throws ErroDaFerramenta
     */
    private function pastaConferida(ChamadaDoConector $chamada, string $acao, int $pastaId): Pasta
    {
        $this->guarda->exigir($chamada, self::NOME, $acao);
        $pasta = $this->pastas->findOneBy(['id' => $pastaId, 'tenant' => $chamada->contexto->escritorio]);
        if (!$pasta instanceof Pasta || $pasta->estaExcluida()) {
            throw ErroDaFerramenta::naoEncontrado();
        }
        $this->guarda->exigir($chamada, self::NOME, $acao, $pasta);
        try {
            $this->liberacao->liberar($chamada->contexto->pessoa, $chamada->contexto->escritorio, $pastaId);
        } catch (PastaDaIaNaoEncontradaException) {
            throw ErroDaFerramenta::naoEncontrado();
        } catch (ContextoBloqueadoException) {
            throw ErroDaFerramenta::emSegredo();
        }
        $this->guarda->exigirEdicaoDaPasta($chamada, $pasta);

        return $pasta;
    }

    /**
     * A posição na linha do tempo (índice) pedida por nome da trilha ou por número (1 é a primeira); null se não existe.
     * "Finalizada" é sempre a última fase da linha, também depois de uma reativação.
     *
     * @param array{via: string, fases: list<array{0: string, 1: string}>} $estado
     */
    private static function posicaoDaFase(array $estado, int|string $pedida): ?int
    {
        $ultima = \count($estado['fases']) - 1;
        if (\is_int($pedida) || ctype_digit($pedida)) {
            $indice = (int) $pedida - 1;

            return $indice >= 0 && $indice <= $ultima ? $indice : null;
        }
        $nome = mb_strtolower(trim($pedida));
        $base = AndamentoDaPasta::base($estado['via']);
        foreach ($base as $indice => $fase) {
            if ($nome !== '' && mb_strtolower($fase[0]) === $nome) {
                return $indice === \count($base) - 1 ? $ultima : $indice;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $campos */
    private function fase(Pasta $pasta, ChamadaDoConector $chamada, array $campos): RespostaDaFerramenta
    {
        $estado = $this->andamento->estado($pasta, $chamada->contexto->escritorio);
        $alvo = self::posicaoDaFase($estado, $campos['fase']) ?? throw ErroDaFerramenta::recusada(self::FASE_INVALIDA);
        $atual = $estado['atual'];
        if ($alvo === $atual && $alvo !== \count($estado['fases']) - 1) {
            throw ErroDaFerramenta::recusada(self::JA_NA_FASE);
        }
        $semPular = ($campos['sem_pular'] ?? false) === true;
        $motivo = trim((string) ($campos['motivo'] ?? ''));
        $id = (int) $pasta->getId();
        if ($alvo > $atual) {
            return new RespostaDaFerramenta($this->aplicarFase($pasta, $chamada, $alvo, $semPular, $motivo), ids: ['pasta' => [$id]]);
        }
        $dados = ['pasta_id' => $id, 'fase_atual' => $atual, 'fase' => $alvo, 'sem_pular' => $semPular, 'motivo' => $motivo,
            'contrato' => $pasta->getSituacaoContrato()];

        return $this->propostas->propor($chamada, self::NOME, 'mudar_fase', $dados, $this->resumoDaFase($pasta, $chamada, $estado, $alvo, $motivo));
    }

    /**
     * @param array{fases: list<array{0: string, 1: string}>, atual: int} $estado
     */
    private function resumoDaFase(Pasta $pasta, ChamadaDoConector $chamada, array $estado, int $alvo, string $motivo): string
    {
        $escritorio = $chamada->contexto->escritorio;
        $atual = $estado['atual'];
        $nup = $this->protecao->rotulo($escritorio, (string) $pasta->getNup(), 'sem número');
        $resumo = $alvo === $atual
            ? sprintf('Reativar a pasta %s, que está finalizada: ela passa por "Reativada" e "Em andamento" e o histórico anterior continua.', $nup)
            : sprintf('Voltar a fase da pasta %s de "%s" para "%s".', $nup, $estado['fases'][$atual][0], $estado['fases'][$alvo][0]);
        if ($motivo !== '') {
            $resumo .= ' Motivo: ' . $this->protecao->rotulo($escritorio, $motivo, '') . '.';
        }
        if (!$this->financeiro->podeVer($chamada->contexto->pessoa, $escritorio, $pasta)) {
            return $resumo;
        }
        $reabre = $alvo < self::INDICE_DO_CONTRATO && $atual >= self::INDICE_DO_CONTRATO && $pasta->getSituacaoContrato() === self::CONTRATO_ASSINADO;

        return $resumo . ($reabre ? ' O contrato do Financeiro volta a ficar pendente.' : ' O contrato do Financeiro não muda.');
    }

    /**
     * Grava pelo UseCase da tela e conta o que aconteceu; o contrato só entra para quem vê o Financeiro.
     *
     * @return array<string, mixed>
     *
     * @throws ErroDaFerramenta
     */
    private function aplicarFase(Pasta $pasta, ChamadaDoConector $chamada, int $alvo, bool $semPular, string $motivo): array
    {
        $escritorio = $chamada->contexto->escritorio;
        $estado = $this->andamento->estado($pasta, $escritorio);
        $contratoAntes = $pasta->getSituacaoContrato();
        try {
            $this->mudarFase->executar($pasta, $chamada->contexto->pessoa, $escritorio, $alvo, $semPular, $motivo);
        } catch (\Throwable $e) {
            throw self::recusaDoUseCase($e);
        }
        $depois = $this->andamento->estado($pasta, $escritorio);
        $dados = ['estado' => 'concluida', 'acao' => 'mudar_fase', 'pasta_id' => (int) $pasta->getId(),
            'fase_anterior' => $estado['fases'][$estado['atual']][0], 'fase' => $depois['fases'][$depois['atual']][0]];
        $contrato = self::fraseDoContrato($contratoAntes, $pasta->getSituacaoContrato());
        if ($contrato !== null && $this->financeiro->podeVer($chamada->contexto->pessoa, $escritorio, $pasta)) {
            $dados['contrato'] = $contrato;
        }

        return $dados;
    }

    private static function fraseDoContrato(string $antes, string $depois): ?string
    {
        if ($antes === $depois) {
            return null;
        }

        return $depois === self::CONTRATO_ASSINADO
            ? 'O contrato do Financeiro foi marcado como assinado.'
            : 'O contrato do Financeiro voltou a ficar pendente.';
    }

    /**
     * A recusa da regra pela CLASSE da exceção do UseCase; a mensagem dela nunca passa adiante.
     *
     * @throws \Throwable o que não é recusa de regra segue como erro de verdade
     */
    private static function recusaDoUseCase(\Throwable $e): ErroDaFerramenta
    {
        return match (true) {
            $e instanceof PastaDeOutroEscritorioException,
            $e instanceof SemPermissaoParaEditarPastaException => ErroDaFerramenta::naoEncontrado(),
            $e instanceof \DomainException => ErroDaFerramenta::recusada(self::RECUSA_DO_USECASE),
            default => throw $e,
        };
    }

    /** @param array<string, mixed> $campos */
    private function etapa(Pasta $pasta, ChamadaDoConector $chamada, array $campos): RespostaDaFerramenta
    {
        $estado = $this->andamento->estado($pasta, $chamada->contexto->escritorio);
        $origem = self::posicaoDaFase($estado, $campos['fase_de_inicio']);
        if ($origem === null || $origem >= \count($estado['fases']) - 1) {
            throw ErroDaFerramenta::recusada(self::FASE_INVALIDA);
        }
        $texto = self::textoNormalizado((string) $campos['texto']);
        $atual = $this->textoDaEtapa($pasta, $estado['via'], $origem, $chamada);
        if ($atual === $texto || ($atual === null && $texto === '')) {
            throw ErroDaFerramenta::recusada(self::SEM_MUDANCA);
        }
        $id = (int) $pasta->getId();
        if ($atual === null) {
            return new RespostaDaFerramenta($this->aplicarEtapa($pasta, $chamada, $origem, $texto), ids: ['pasta' => [$id]]);
        }
        $dados = ['pasta_id' => $id, 'fase_de_inicio' => $origem, 'texto' => $texto, 'texto_atual' => $atual];

        return $this->propostas->propor($chamada, self::NOME, 'nomear_etapa', $dados, $this->resumoDaEtapa($pasta, $chamada, $estado, $origem, $atual, $texto));
    }

    /**
     * @param array{fases: list<array{0: string, 1: string}>} $estado
     */
    private function resumoDaEtapa(Pasta $pasta, ChamadaDoConector $chamada, array $estado, int $origem, string $atual, string $texto): string
    {
        $escritorio = $chamada->contexto->escritorio;
        $nup = $this->protecao->rotulo($escritorio, (string) $pasta->getNup(), 'sem número');
        $entre = sprintf('entre "%s" e "%s" da pasta %s', $estado['fases'][$origem][0], $estado['fases'][$origem + 1][0], $nup);
        $velho = $this->protecao->rotulo($escritorio, $atual, 'sem nome');
        if ($texto === '') {
            return sprintf('Remover o nome "%s" da etapa %s.', $velho, $entre);
        }

        return sprintf('Trocar o nome da etapa %s: de "%s" para "%s".', $entre, $velho, $this->protecao->rotulo($escritorio, $texto, 'sem nome'));
    }

    /**
     * @return array<string, mixed>
     *
     * @throws ErroDaFerramenta
     */
    private function aplicarEtapa(Pasta $pasta, ChamadaDoConector $chamada, int $origem, string $texto): array
    {
        $escritorio = $chamada->contexto->escritorio;
        try {
            $this->nomearEtapa->executar($pasta, $chamada->contexto->pessoa, $escritorio, $origem, $texto);
        } catch (\Throwable $e) {
            throw self::recusaDoUseCase($e);
        }
        $estado = $this->andamento->estado($pasta, $escritorio);

        return [
            'estado' => 'concluida',
            'acao' => 'nomear_etapa',
            'pasta_id' => (int) $pasta->getId(),
            'entre' => [$estado['fases'][$origem][0], $estado['fases'][$origem + 1][0]],
            'nome' => $texto === '' ? null : $this->protecao->rotulo($escritorio, $texto, 'sem nome'),
        ];
    }

    private function textoDaEtapa(Pasta $pasta, string $via, int $origem, ChamadaDoConector $chamada): ?string
    {
        return $this->rotulos->daOrigem($pasta, $via, $origem, $chamada->contexto->escritorio)?->getTexto();
    }

    /** O texto como o UseCase o normaliza (espaços juntos, sem as pontas), recusando o que ele recusaria. */
    private static function textoNormalizado(string $texto): string
    {
        $texto = trim((string) preg_replace('/\s+/u', ' ', $texto));
        if (mb_strlen($texto) > RotuloDeEtapa::TAMANHO_MAXIMO) {
            throw ErroDaFerramenta::recusada(self::TEXTO_LONGO);
        }
        if (preg_match('/[\p{Cc}<>]/u', $texto) === 1) {
            throw ErroDaFerramenta::recusada(self::TEXTO_INVALIDO);
        }

        return $texto;
    }
}
