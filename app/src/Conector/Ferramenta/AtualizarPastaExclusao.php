<?php

declare(strict_types=1);

namespace App\Conector\Ferramenta;

use App\Conector\DTO\ChamadaDoConector;
use App\Conector\DTO\RespostaDaFerramenta;
use App\Conector\DTO\ResultadoDaConfirmacao;
use App\Conector\Exception\ErroDaFerramenta;
use App\Conector\Service\PropostasDoConector;
use App\Expediente\Entity\Marcador;
use App\Expediente\Repository\MarcadorRepository;
use App\Expediente\UseCase\ExcluirMarcadorUseCase;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaReu;
use App\Pasta\Repository\PastaRepository;
use App\Pasta\Repository\PastaReuRepository;
use App\Pasta\UseCase\ExcluirPastaUseCase;
use App\Pasta\UseCase\GerenciarReusDaPastaUseCase;

/**
 * A ação `excluir` da `atualizar_pasta` (`docs/specs/mcp-remoto-oauth.md`, 6.6, 6.7 e decisão 31): SEMPRE por proposta,
 * com o alvo nos dados. Além da linha da tabela (módulo `pastas`, chave `expedientes`), cada alvo exige a mesma permissão
 * que a tela exige hoje, nada mais frouxo:
 *
 *  - `pasta` (`pasta_id`): o recurso `pasta` com a ação `delete` (`pasta_delete`, `PastaController.php:1792`). A pasta
 *    vai para a lixeira (lápide restaurável, {@see ExcluirPastaUseCase}); o resumo traz o número, que a tela pede para
 *    digitar, e a confirmação recusa se o número mudou;
 *  - `marcador` (`marcador_id`): o módulo `expediente` (`expediente_marcador_excluir`, `ExpedienteController::assertAccess`).
 *    O marcador é do escritório: sai de todas as pastas, e os submarcadores vão junto ({@see ExcluirMarcadorUseCase});
 *  - `reu` (`pasta_id`, `reu_id`): o recurso `pasta` de editar (`pasta_reu_remover`, {@see GerenciarReusDaPastaUseCase}).
 *
 * Até propor só lê. {@see reconferir()} refaz as conferências do alvo e recusa se ele mudou desde a proposta.
 */
final class AtualizarPastaExclusao implements AtualizarPastaGrupo
{
    public const RECUSA = 'O BlueJus recusou a exclusão. Confira o pedido e tente de novo.';
    public const AVISO_LIXEIRA = 'A pasta foi para a lixeira do escritório e pode ser restaurada com a ação restaurar.';

    private const CAMPOS_DO_ALVO = ['pasta' => ['pasta_id'], 'marcador' => ['marcador_id'], 'reu' => ['pasta_id', 'reu_id']];

    public function __construct(
        private readonly AtualizarPastaAcesso $acesso,
        private readonly AtualizarPastaTexto $texto,
        private readonly PropostasDoConector $propostas,
        private readonly PastaRepository $pastas,
        private readonly MarcadorRepository $marcadores,
        private readonly PastaReuRepository $reus,
        private readonly ExcluirPastaUseCase $excluirPasta,
        private readonly ExcluirMarcadorUseCase $excluirMarcador,
        private readonly GerenciarReusDaPastaUseCase $gerenciarReus,
    ) {
    }

    public function acoes(): array
    {
        return ['excluir'];
    }

    public function executar(AtualizarPastaEntrada $entrada, ChamadaDoConector $chamada): RespostaDaFerramenta
    {
        $alvo = $entrada->textoObrigatorio('alvo');
        foreach (['pasta_id', 'marcador_id', 'reu_id'] as $campo) {
            if ($entrada->tem($campo) !== \in_array($campo, self::CAMPOS_DO_ALVO[$alvo], true)) {
                throw ErroDaFerramenta::entradaInvalida();
            }
        }
        [$dados, $resumo] = match ($alvo) {
            'pasta' => $this->propostaDaPasta($entrada->pastaId(), $chamada),
            'marcador' => $this->propostaDoMarcador($entrada->inteiroObrigatorio('marcador_id'), $chamada),
            default => $this->propostaDoReu($entrada->pastaId(), $entrada->inteiroObrigatorio('reu_id'), $chamada),
        };

        return $this->propostas->propor($chamada, AtualizarPastaFerramenta::NOME, 'excluir', ['alvo' => $alvo] + $dados, $resumo);
    }

    public function reconferir(string $acao, array $dados, ChamadaDoConector $chamada): void
    {
        match ($dados['alvo'] ?? null) {
            'pasta' => $this->pastaConferida($dados, $chamada),
            'marcador' => $this->marcadorConferido($dados, $chamada),
            'reu' => $this->reuConferido($dados, $chamada),
            default => throw ErroDaFerramenta::entradaInvalida(),
        };
    }

    public function executarConfirmada(string $acao, array $dados, ChamadaDoConector $chamada): ResultadoDaConfirmacao
    {
        return match ($dados['alvo'] ?? null) {
            'pasta' => $this->excluirAPasta($dados, $chamada),
            'marcador' => $this->excluirOMarcador($dados, $chamada),
            'reu' => $this->removerOReu($dados, $chamada),
            default => throw ErroDaFerramenta::entradaInvalida(),
        };
    }

    // ------------------------------------------------------------------ pasta

    /** @throws ErroDaFerramenta */
    private function pastaParaExcluir(ChamadaDoConector $chamada, int $pastaId): Pasta
    {
        $pasta = $this->acesso->viva($chamada, 'excluir', $pastaId, false);
        $this->acesso->exigirExclusao($chamada, $pasta);

        return $pasta;
    }

    /**
     * @return array{0: array<string, mixed>, 1: string}
     *
     * @throws ErroDaFerramenta
     */
    private function propostaDaPasta(int $pastaId, ChamadaDoConector $chamada): array
    {
        $pasta = $this->pastaParaExcluir($chamada, $pastaId);
        $escritorio = $chamada->contexto->escritorio;
        $numero = $this->texto->numero($pasta, $escritorio);
        $identificador = (string) $pasta->getNomeCliente();
        $resumo = sprintf(
            'Excluir a pasta %s%s: ela vai para a lixeira do escritório, sai das listas e pode ser restaurada depois. Confira o número da pasta: %s.',
            $numero,
            $identificador === '' ? '' : ' (' . $this->texto->protegido($escritorio, $identificador) . ')',
            $numero,
        );

        return [['pasta_id' => (int) $pasta->getId(), 'numero' => (string) $pasta->getNup()], $resumo];
    }

    /**
     * @param array<string, mixed> $dados
     *
     * @throws ErroDaFerramenta
     */
    private function pastaConferida(array $dados, ChamadaDoConector $chamada): Pasta
    {
        $pasta = $this->pastaParaExcluir($chamada, AtualizarPastaRecusa::inteiro($dados, 'pasta_id'));
        if ((string) $pasta->getNup() !== AtualizarPastaRecusa::texto($dados, 'numero')) {
            throw ErroDaFerramenta::recusada(AtualizarPastaFerramenta::MUDOU);
        }

        return $pasta;
    }

    /** @param array<string, mixed> $dados */
    private function excluirAPasta(array $dados, ChamadaDoConector $chamada): ResultadoDaConfirmacao
    {
        $pasta = $this->pastaConferida($dados, $chamada);
        $contexto = $chamada->contexto;
        AtualizarPastaRecusa::gravando(fn () => $this->excluirPasta->executar($pasta, $contexto->pessoa, $contexto->escritorio), self::RECUSA);
        $id = (int) $pasta->getId();
        $resultado = ['estado' => 'excluida', 'alvo' => 'pasta', 'pasta_id' => $id, 'numero' => $this->texto->numero($pasta, $contexto->escritorio), 'aviso' => self::AVISO_LIXEIRA];

        return new ResultadoDaConfirmacao($resultado, [$id], ['pasta' => 1], ['pasta' => [$id]]);
    }

    // ------------------------------------------------------------------ marcador

    /** @throws ErroDaFerramenta */
    private function marcadorParaExcluir(ChamadaDoConector $chamada, int $marcadorId): Marcador
    {
        $this->acesso->exigirLinha($chamada, 'excluir');
        $this->acesso->exigirModuloExpediente($chamada);

        return $this->marcadores->findPorTenant($marcadorId, $chamada->contexto->escritorio) ?? throw ErroDaFerramenta::naoEncontrado();
    }

    /**
     * @return array{0: array<string, mixed>, 1: string}
     *
     * @throws ErroDaFerramenta
     */
    private function propostaDoMarcador(int $marcadorId, ChamadaDoConector $chamada): array
    {
        $marcador = $this->marcadorParaExcluir($chamada, $marcadorId);
        $contexto = $chamada->contexto;
        $quantas = $this->pastas->countPorMarcador($marcador, $contexto->escritorio, $contexto->pessoa);
        $resumo = sprintf(
            'Excluir o marcador %s do escritório: ele sai de todas as pastas que o usam (%d delas você vê) e não volta.',
            AtualizarPastaTexto::citado($this->texto->protegido($contexto->escritorio, $marcador->getNome())),
            $quantas,
        );
        $filhos = $marcador->getFilhos()->toArray();
        if ($filhos !== []) {
            $nomes = array_map(fn (Marcador $f): string => AtualizarPastaTexto::citado($this->texto->protegido($contexto->escritorio, $f->getNome())), $filhos);
            $resumo .= ' Os submarcadores ' . implode(', ', $nomes) . ' também são excluídos.';
        }

        return [['marcador_id' => (int) $marcador->getId(), 'nome' => $marcador->getNome(), 'filhos' => self::idsDosFilhos($marcador)], $resumo];
    }

    /** @return list<int> */
    private static function idsDosFilhos(Marcador $marcador): array
    {
        $ids = array_map(static fn (Marcador $f): int => (int) $f->getId(), $marcador->getFilhos()->toArray());
        sort($ids);

        return array_values($ids);
    }

    /**
     * @param array<string, mixed> $dados
     *
     * @throws ErroDaFerramenta
     */
    private function marcadorConferido(array $dados, ChamadaDoConector $chamada): Marcador
    {
        $marcador = $this->marcadorParaExcluir($chamada, AtualizarPastaRecusa::inteiro($dados, 'marcador_id'));
        if ($marcador->getNome() !== AtualizarPastaRecusa::texto($dados, 'nome') || self::idsDosFilhos($marcador) !== ($dados['filhos'] ?? null)) {
            throw ErroDaFerramenta::recusada(AtualizarPastaFerramenta::MUDOU);
        }

        return $marcador;
    }

    /** @param array<string, mixed> $dados */
    private function excluirOMarcador(array $dados, ChamadaDoConector $chamada): ResultadoDaConfirmacao
    {
        $marcador = $this->marcadorConferido($dados, $chamada);
        $id = (int) $marcador->getId();
        $nome = $this->texto->protegido($chamada->contexto->escritorio, $marcador->getNome());
        AtualizarPastaRecusa::gravando(fn (): Marcador => $this->excluirMarcador->executar($id, $chamada->contexto->escritorio), self::RECUSA);

        return new ResultadoDaConfirmacao(['estado' => 'excluido', 'alvo' => 'marcador', 'marcador_id' => $id, 'nome' => $nome], [$id], ['marcador' => 1], ['marcador' => [$id]]);
    }

    // ------------------------------------------------------------------ réu

    /**
     * @return array{0: Pasta, 1: PastaReu, 2: bool} a pasta, o réu e se ele é o principal (o primeiro da ordem)
     *
     * @throws ErroDaFerramenta
     */
    private function reuParaRemover(ChamadaDoConector $chamada, int $pastaId, int $reuId): array
    {
        $pasta = $this->acesso->viva($chamada, 'excluir', $pastaId, true);
        foreach ($this->reus->daPasta($pasta, $chamada->contexto->escritorio) as $posicao => $reu) {
            if ($reu->getId() === $reuId) {
                return [$pasta, $reu, $posicao === 0];
            }
        }

        throw ErroDaFerramenta::naoEncontrado();
    }

    /**
     * @return array{0: array<string, mixed>, 1: string}
     *
     * @throws ErroDaFerramenta
     */
    private function propostaDoReu(int $pastaId, int $reuId, ChamadaDoConector $chamada): array
    {
        [$pasta, $reu, $principal] = $this->reuParaRemover($chamada, $pastaId, $reuId);
        $escritorio = $chamada->contexto->escritorio;
        $resumo = sprintf(
            'Remover o réu %s da pasta %s.%s',
            AtualizarPastaTexto::citado($this->texto->protegido($escritorio, $reu->getNome())),
            $this->texto->numero($pasta, $escritorio),
            $principal ? ' Ele é o réu principal: o próximo da lista passa a ser o principal.' : '',
        );

        return [['pasta_id' => (int) $pasta->getId(), 'reu_id' => $reuId, 'nome' => $reu->getNome()], $resumo];
    }

    /**
     * @param array<string, mixed> $dados
     *
     * @return array{0: Pasta, 1: PastaReu, 2: bool}
     *
     * @throws ErroDaFerramenta
     */
    private function reuConferido(array $dados, ChamadaDoConector $chamada): array
    {
        $conferido = $this->reuParaRemover($chamada, AtualizarPastaRecusa::inteiro($dados, 'pasta_id'), AtualizarPastaRecusa::inteiro($dados, 'reu_id'));
        if ($conferido[1]->getNome() !== AtualizarPastaRecusa::texto($dados, 'nome')) {
            throw ErroDaFerramenta::recusada(AtualizarPastaFerramenta::MUDOU);
        }

        return $conferido;
    }

    /** @param array<string, mixed> $dados */
    private function removerOReu(array $dados, ChamadaDoConector $chamada): ResultadoDaConfirmacao
    {
        [$pasta, $reu] = $this->reuConferido($dados, $chamada);
        $contexto = $chamada->contexto;
        $reuId = (int) $reu->getId();
        $nome = $this->texto->protegido($contexto->escritorio, $reu->getNome());
        $restantes = AtualizarPastaRecusa::gravando(fn (): array => $this->gerenciarReus->remover($pasta, $contexto->pessoa, $contexto->escritorio, $reuId), self::RECUSA);
        $id = (int) $pasta->getId();

        return new ResultadoDaConfirmacao(
            ['estado' => 'removido', 'alvo' => 'reu', 'pasta_id' => $id, 'reu_id' => $reuId, 'nome' => $nome, 'reus_que_ficam' => \count($restantes)],
            [$reuId],
            ['reu' => 1],
            ['pasta' => [$id], 'reu' => [$reuId]],
        );
    }
}
