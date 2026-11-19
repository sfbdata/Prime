<?php

declare(strict_types=1);

namespace App\Conector\Ferramenta;

use App\Conector\DTO\ChamadaDoConector;
use App\Conector\DTO\RespostaDaFerramenta;
use App\Conector\DTO\ResultadoDaConfirmacao;
use App\Conector\Exception\ErroDaFerramenta;
use App\Conector\Service\PropostasDoConector;
use App\Entity\Tenant\Tenant;
use App\Expediente\DTO\CriarMarcadorDTO;
use App\Expediente\Entity\Marcador;
use App\Expediente\Repository\MarcadorRepository;
use App\Expediente\Service\CoresDoMarcador;
use App\Expediente\UseCase\CriarMarcadorUseCase;
use App\Expediente\UseCase\EditarMarcadorUseCase;
use App\Expediente\UseCase\ReordenarMarcadorUseCase;
use App\Expediente\UseCase\SincronizarMarcadoresDaPastaUseCase;
use App\Pasta\Entity\Pasta;

/**
 * Os marcadores pela `atualizar_pasta` (`docs/specs/mcp-remoto-oauth.md`, 6.6):
 *
 *  - `marcadores_da_pasta` (`marcadores`, a lista que a pasta deve ter, até 50): o recurso `pasta` de editar, como o
 *    {@see SincronizarMarcadoresDaPastaUseCase}. Só acrescentar grava na hora; lista que tira algum marcador da pasta é
 *    exclusão e vira UMA proposta, com o que sai e o que entra no resumo;
 *  - `marcador` (do escritório; `operacao` criar, editar ou reordenar): além da linha da tabela, o módulo `expediente`,
 *    como `ExpedienteController::assertAccess`. Criar e reordenar gravam na hora; editar troca o nome, a cor ou a
 *    descrição em todas as pastas que usam o marcador, e vai por proposta com o antes e o depois.
 *
 * A confirmação recusa se os marcadores da pasta ou o marcador mudaram desde a proposta.
 */
final class AtualizarPastaMarcadores implements AtualizarPastaGrupo
{
    public const RECUSA = 'O BlueJus recusou mudar os marcadores. Confira o pedido e tente de novo.';
    public const NOME_INVALIDO = 'O nome do marcador é obrigatório e pode ter até 100 caracteres.';
    public const COR_INVALIDA = 'Cor de marcador inválida: use uma das cores da tela, no formato #rrggbb.';
    public const NOME_REPETIDO = 'Já existe um marcador com esse nome no escritório.';
    public const PAI_INVALIDO = 'O submarcador só pode ficar dentro de um marcador principal do escritório.';
    public const REORDENAR_INVALIDO = 'Só se reordena um marcador ao lado de outro do mesmo nível (dois principais, ou dois submarcadores do mesmo principal).';

    private const TAMANHO_DO_NOME = 100;

    private const CAMPOS_DA_OPERACAO = [
        'criar' => ['nome', 'cor', 'descricao', 'pai_id'],
        'editar' => ['marcador_id', 'nome', 'cor', 'descricao'],
        'reordenar' => ['marcador_id', 'alvo_id', 'depois'],
    ];

    public function __construct(
        private readonly AtualizarPastaAcesso $acesso,
        private readonly AtualizarPastaTexto $texto,
        private readonly PropostasDoConector $propostas,
        private readonly MarcadorRepository $marcadores,
        private readonly SincronizarMarcadoresDaPastaUseCase $sincronizar,
        private readonly CriarMarcadorUseCase $criar,
        private readonly EditarMarcadorUseCase $editar,
        private readonly ReordenarMarcadorUseCase $reordenar,
    ) {
    }

    public function acoes(): array
    {
        return ['marcadores_da_pasta', 'marcador'];
    }

    public function executar(AtualizarPastaEntrada $entrada, ChamadaDoConector $chamada): RespostaDaFerramenta
    {
        if ($entrada->acao === 'marcadores_da_pasta') {
            return $this->marcadoresDaPasta($entrada, $chamada);
        }
        $operacao = $entrada->textoObrigatorio('operacao');
        self::exigirCamposDaOperacao($entrada, $operacao);
        $this->exigirMarcadores($chamada);

        return match ($operacao) {
            'criar' => $this->criarMarcador($entrada, $chamada),
            'editar' => $this->proporEdicao($entrada, $chamada),
            default => $this->reordenarMarcador($entrada, $chamada),
        };
    }

    public function reconferir(string $acao, array $dados, ChamadaDoConector $chamada): void
    {
        if ($acao === 'marcadores_da_pasta') {
            $this->pastaConferida($dados, $chamada);

            return;
        }
        $this->marcadorConferido($dados, $chamada);
    }

    public function executarConfirmada(string $acao, array $dados, ChamadaDoConector $chamada): ResultadoDaConfirmacao
    {
        if ($acao === 'marcadores_da_pasta') {
            $pasta = $this->pastaConferida($dados, $chamada);
            $id = (int) $pasta->getId();

            return new ResultadoDaConfirmacao($this->sincronizarPasta($pasta, self::listaDosDados($dados, 'marcadores'), $chamada), [$id], ['pasta' => 1], ['pasta' => [$id]]);
        }
        [$marcador, $depois] = $this->marcadorConferido($dados, $chamada);
        $id = (int) $marcador->getId();

        return new ResultadoDaConfirmacao($this->gravarEdicao($marcador, $depois, $chamada), [$id], ['marcador' => 1], ['marcador' => [$id]]);
    }

    // ------------------------------------------------------------------ marcadores da pasta

    /** @throws ErroDaFerramenta */
    private function marcadoresDaPasta(AtualizarPastaEntrada $entrada, ChamadaDoConector $chamada): RespostaDaFerramenta
    {
        $pasta = $this->acesso->viva($chamada, 'marcadores_da_pasta', $entrada->pastaId(), true);
        $pedidos = self::ordenados($entrada->lista('marcadores'));
        $escritorio = $chamada->contexto->escritorio;
        $this->exigirDoEscritorio($pedidos, $escritorio);
        $atuais = self::idsDaPasta($pasta);
        $saem = array_values(array_diff($atuais, $pedidos));
        $entram = array_values(array_diff($pedidos, $atuais));
        if ($saem === [] && $entram === []) {
            throw ErroDaFerramenta::recusada(AtualizarPastaFerramenta::NADA_A_MUDAR);
        }
        $id = (int) $pasta->getId();
        if ($saem === []) {
            return new RespostaDaFerramenta($this->sincronizarPasta($pasta, $pedidos, $chamada), ids: ['pasta' => [$id], 'marcador' => $entram]);
        }
        $dados = ['pasta_id' => $id, 'marcadores' => $pedidos, 'atuais' => $atuais];

        return $this->propostas->propor($chamada, AtualizarPastaFerramenta::NOME, 'marcadores_da_pasta', $dados, $this->resumoDaPasta($pasta, $saem, $entram, $escritorio));
    }

    /**
     * @param list<int> $saem
     * @param list<int> $entram
     */
    private function resumoDaPasta(Pasta $pasta, array $saem, array $entram, Tenant $escritorio): string
    {
        $resumo = sprintf('Mudar os marcadores da pasta %s: tirar %s', $this->texto->numero($pasta, $escritorio), $this->nomes($saem, $escritorio));
        if ($entram !== []) {
            $resumo .= '; acrescentar ' . $this->nomes($entram, $escritorio);
        }

        return $resumo . '. Os marcadores continuam existindo no escritório.';
    }

    /**
     * As conferências de acesso de novo, os marcadores da pasta iguais aos da proposta e os pedidos ainda do escritório.
     *
     * @param array<string, mixed> $dados
     *
     * @throws ErroDaFerramenta
     */
    private function pastaConferida(array $dados, ChamadaDoConector $chamada): Pasta
    {
        $pasta = $this->acesso->viva($chamada, 'marcadores_da_pasta', AtualizarPastaRecusa::inteiro($dados, 'pasta_id'), true);
        if (self::idsDaPasta($pasta) !== self::listaDosDados($dados, 'atuais')) {
            throw ErroDaFerramenta::recusada(AtualizarPastaFerramenta::MUDOU);
        }
        $this->exigirDoEscritorio(self::listaDosDados($dados, 'marcadores'), $chamada->contexto->escritorio);

        return $pasta;
    }

    /**
     * @param list<int> $pedidos
     *
     * @return array<string, mixed>
     *
     * @throws ErroDaFerramenta
     */
    private function sincronizarPasta(Pasta $pasta, array $pedidos, ChamadaDoConector $chamada): array
    {
        $contexto = $chamada->contexto;
        $gravada = AtualizarPastaRecusa::gravando(fn (): Pasta => $this->sincronizar->executar((int) $pasta->getId(), $pedidos, $contexto->pessoa, $contexto->escritorio), self::RECUSA);
        $marcadores = array_values(array_map(
            fn (Marcador $m): array => ['id' => (int) $m->getId(), 'nome' => $this->texto->protegido($contexto->escritorio, $m->getNome())],
            $gravada->getMarcadores()->toArray(),
        ));

        return ['estado' => 'concluida', 'acao' => 'marcadores_da_pasta', 'pasta_id' => (int) $pasta->getId(), 'marcadores' => $marcadores];
    }

    /**
     * @param list<int> $ids
     *
     * @throws ErroDaFerramenta
     */
    private function exigirDoEscritorio(array $ids, Tenant $escritorio): void
    {
        foreach ($ids as $id) {
            if ($this->marcadores->findPorTenant($id, $escritorio) === null) {
                throw ErroDaFerramenta::naoEncontrado();
            }
        }
    }

    /** @param list<int> $ids */
    private function nomes(array $ids, Tenant $escritorio): string
    {
        $nomes = [];
        foreach ($ids as $id) {
            $nome = $this->marcadores->findPorTenant($id, $escritorio)?->getNome();
            $nomes[] = AtualizarPastaTexto::citado($this->texto->protegido($escritorio, $nome, 'sem nome'));
        }

        return implode(', ', $nomes);
    }

    /** @return list<int> */
    private static function idsDaPasta(Pasta $pasta): array
    {
        return self::ordenados(array_map(static fn (Marcador $m): int => (int) $m->getId(), $pasta->getMarcadores()->toArray()));
    }

    /**
     * @param array<int> $ids
     *
     * @return list<int>
     */
    private static function ordenados(array $ids): array
    {
        $ids = array_values(array_unique($ids));
        sort($ids);

        return $ids;
    }

    /**
     * @param array<string, mixed> $dados
     *
     * @return list<int>
     *
     * @throws ErroDaFerramenta
     */
    private static function listaDosDados(array $dados, string $campo): array
    {
        $lista = $dados[$campo] ?? null;
        if (!\is_array($lista) || !array_is_list($lista)) {
            throw ErroDaFerramenta::entradaInvalida();
        }
        foreach ($lista as $id) {
            if (!\is_int($id)) {
                throw ErroDaFerramenta::entradaInvalida();
            }
        }

        return $lista;
    }

    // ------------------------------------------------------------------ marcador do escritório

    /** @throws ErroDaFerramenta */
    private static function exigirCamposDaOperacao(AtualizarPastaEntrada $entrada, string $operacao): void
    {
        foreach (AtualizarPastaEntrada::CAMPOS['marcador'] as $campo) {
            if ($campo !== 'operacao' && $entrada->tem($campo) && !\in_array($campo, self::CAMPOS_DA_OPERACAO[$operacao], true)) {
                throw ErroDaFerramenta::entradaInvalida();
            }
        }
        $obrigatorios = ['criar' => ['nome'], 'editar' => ['marcador_id'], 'reordenar' => ['marcador_id', 'alvo_id']][$operacao];
        foreach ($obrigatorios as $campo) {
            if (!$entrada->tem($campo)) {
                throw ErroDaFerramenta::entradaInvalida();
            }
        }
    }

    /** @throws ErroDaFerramenta */
    private function exigirMarcadores(ChamadaDoConector $chamada): void
    {
        $this->acesso->exigirLinha($chamada, 'marcador');
        $this->acesso->exigirModuloExpediente($chamada);
    }

    /** @throws ErroDaFerramenta */
    private function marcadorDoEscritorio(int $id, ChamadaDoConector $chamada): Marcador
    {
        return $this->marcadores->findPorTenant($id, $chamada->contexto->escritorio) ?? throw ErroDaFerramenta::naoEncontrado();
    }

    /** @throws ErroDaFerramenta */
    private function criarMarcador(AtualizarPastaEntrada $entrada, ChamadaDoConector $chamada): RespostaDaFerramenta
    {
        $contexto = $chamada->contexto;
        $nome = self::nomeValido($entrada->textoObrigatorio('nome'));
        $cor = self::corValida($entrada->texto('cor'));
        if ($this->marcadores->existeNomeNoTenant($nome, $contexto->escritorio)) {
            throw ErroDaFerramenta::recusada(self::NOME_REPETIDO);
        }
        $paiId = $entrada->inteiro('pai_id');
        if ($paiId !== null && $this->marcadorDoEscritorio($paiId, $chamada)->getPai() !== null) {
            throw ErroDaFerramenta::recusada(self::PAI_INVALIDO);
        }
        $descricao = $entrada->texto('descricao');
        $dto = new CriarMarcadorDTO($nome, $paiId, $cor, $descricao === null ? null : trim($descricao));
        $marcador = AtualizarPastaRecusa::gravando(fn (): Marcador => $this->criar->executar($dto, $contexto->pessoa, $contexto->escritorio), self::RECUSA);
        $id = (int) $marcador->getId();

        return new RespostaDaFerramenta(['estado' => 'concluida', 'acao' => 'marcador', 'operacao' => 'criar', 'marcador' => $this->retratoNaResposta($marcador, $chamada)], ids: ['marcador' => [$id]]);
    }

    /** @throws ErroDaFerramenta */
    private function reordenarMarcador(AtualizarPastaEntrada $entrada, ChamadaDoConector $chamada): RespostaDaFerramenta
    {
        $movido = $this->marcadorDoEscritorio($entrada->inteiroObrigatorio('marcador_id'), $chamada);
        $alvo = $this->marcadorDoEscritorio($entrada->inteiroObrigatorio('alvo_id'), $chamada);
        if ($movido === $alvo) {
            throw ErroDaFerramenta::recusada(AtualizarPastaFerramenta::NADA_A_MUDAR);
        }
        $depois = $entrada->booleano('depois');
        $escritorio = $chamada->contexto->escritorio;
        AtualizarPastaRecusa::gravando(fn () => $this->reordenar->executar((int) $movido->getId(), (int) $alvo->getId(), $depois, $escritorio), self::REORDENAR_INVALIDO);
        $ids = [(int) $movido->getId(), (int) $alvo->getId()];

        return new RespostaDaFerramenta(['estado' => 'concluida', 'acao' => 'marcador', 'operacao' => 'reordenar', 'marcador_id' => $ids[0], 'posicao' => $depois ? 'depois' : 'antes', 'de_marcador_id' => $ids[1]], ids: ['marcador' => $ids]);
    }

    /** @throws ErroDaFerramenta */
    private function proporEdicao(AtualizarPastaEntrada $entrada, ChamadaDoConector $chamada): RespostaDaFerramenta
    {
        if (!$entrada->tem('nome') && !$entrada->tem('cor') && !$entrada->tem('descricao')) {
            throw ErroDaFerramenta::entradaInvalida();
        }
        $marcador = $this->marcadorDoEscritorio($entrada->inteiroObrigatorio('marcador_id'), $chamada);
        $antes = self::retrato($marcador);
        $depois = [
            'nome' => $entrada->tem('nome') ? self::nomeValido($entrada->textoObrigatorio('nome')) : $antes['nome'],
            'cor' => $entrada->tem('cor') ? self::corValida($entrada->texto('cor')) : $antes['cor'],
            'descricao' => $entrada->tem('descricao') ? self::descricao($entrada->texto('descricao')) : $antes['descricao'],
        ];
        if ($depois === $antes) {
            throw ErroDaFerramenta::recusada(AtualizarPastaFerramenta::NADA_A_MUDAR);
        }
        $this->exigirNomeLivre($marcador, $depois['nome'], $chamada);
        $dados = ['operacao' => 'editar', 'marcador_id' => (int) $marcador->getId(), 'antes' => $antes, 'depois' => $depois];

        return $this->propostas->propor($chamada, AtualizarPastaFerramenta::NOME, 'marcador', $dados, $this->resumoDaEdicao($antes, $depois, $chamada));
    }

    /**
     * @param array{nome: string, cor: ?string, descricao: ?string} $antes
     * @param array{nome: string, cor: ?string, descricao: ?string} $depois
     */
    private function resumoDaEdicao(array $antes, array $depois, ChamadaDoConector $chamada): string
    {
        $escritorio = $chamada->contexto->escritorio;
        $linhas = [sprintf('Editar o marcador %s do escritório (a troca vale em todas as pastas que o usam):', AtualizarPastaTexto::citado($this->texto->protegido($escritorio, $antes['nome'])))];
        foreach (['nome' => 'Nome', 'cor' => 'Cor', 'descricao' => 'Descrição'] as $campo => $rotulo) {
            if ($antes[$campo] !== $depois[$campo]) {
                $de = $antes[$campo] === null ? 'vazio' : AtualizarPastaTexto::citado($this->texto->protegido($escritorio, $antes[$campo]));
                $para = $depois[$campo] === null ? 'vazio' : AtualizarPastaTexto::citado(AtualizarPastaTexto::umaLinha($depois[$campo]));
                $linhas[] = sprintf('%s: de %s para %s.', $rotulo, $de, $para);
            }
        }

        return implode("\n", $linhas);
    }

    /**
     * A linha e o módulo de novo, o marcador ainda do escritório, igual ao da proposta, e o nome novo ainda livre.
     *
     * @param array<string, mixed> $dados
     *
     * @return array{0: Marcador, 1: array{nome: string, cor: ?string, descricao: ?string}}
     *
     * @throws ErroDaFerramenta
     */
    private function marcadorConferido(array $dados, ChamadaDoConector $chamada): array
    {
        if (($dados['operacao'] ?? null) !== 'editar' || !\is_array($dados['depois'] ?? null)) {
            throw ErroDaFerramenta::entradaInvalida();
        }
        $this->exigirMarcadores($chamada);
        $marcador = $this->marcadorDoEscritorio(AtualizarPastaRecusa::inteiro($dados, 'marcador_id'), $chamada);
        if (self::retrato($marcador) !== ($dados['antes'] ?? null)) {
            throw ErroDaFerramenta::recusada(AtualizarPastaFerramenta::MUDOU);
        }
        $depois = $dados['depois'];
        $novo = ['nome' => self::nomeValido((string) ($depois['nome'] ?? '')), 'cor' => self::corValida(AtualizarPastaRecusa::texto($depois, 'cor')), 'descricao' => AtualizarPastaRecusa::texto($depois, 'descricao')];
        $this->exigirNomeLivre($marcador, $novo['nome'], $chamada);

        return [$marcador, $novo];
    }

    /**
     * @param array{nome: string, cor: ?string, descricao: ?string} $depois
     *
     * @return array<string, mixed>
     *
     * @throws ErroDaFerramenta
     */
    private function gravarEdicao(Marcador $marcador, array $depois, ChamadaDoConector $chamada): array
    {
        $escritorio = $chamada->contexto->escritorio;
        $id = (int) $marcador->getId();
        // Descrição vazia apaga (o UseCase só mexe na descrição quando ela vem).
        $gravado = AtualizarPastaRecusa::gravando(fn (): Marcador => $this->editar->executar($id, $depois['nome'], $depois['cor'], $escritorio, $depois['descricao'] ?? ''), self::RECUSA);

        return ['estado' => 'concluida', 'acao' => 'marcador', 'operacao' => 'editar', 'marcador' => $this->retratoNaResposta($gravado, $chamada)];
    }

    /** @throws ErroDaFerramenta */
    private function exigirNomeLivre(Marcador $marcador, string $nome, ChamadaDoConector $chamada): void
    {
        if ($nome !== $marcador->getNome() && $this->marcadores->existeNomeNoTenant($nome, $chamada->contexto->escritorio, (int) $marcador->getId())) {
            throw ErroDaFerramenta::recusada(self::NOME_REPETIDO);
        }
    }

    /** @return array{nome: string, cor: ?string, descricao: ?string} */
    private static function retrato(Marcador $marcador): array
    {
        return ['nome' => $marcador->getNome(), 'cor' => $marcador->getCor(), 'descricao' => $marcador->getDescricao()];
    }

    /** @return array{id: int, nome: string, cor: ?string, pai_id: ?int} */
    private function retratoNaResposta(Marcador $marcador, ChamadaDoConector $chamada): array
    {
        return [
            'id' => (int) $marcador->getId(),
            'nome' => $this->texto->protegido($chamada->contexto->escritorio, $marcador->getNome()),
            'cor' => $marcador->getCor(),
            'pai_id' => $marcador->getPai()?->getId(),
        ];
    }

    /** @throws ErroDaFerramenta */
    private static function nomeValido(string $nome): string
    {
        $nome = AtualizarPastaTexto::umaLinha($nome);
        if ($nome === '' || mb_strlen($nome) > self::TAMANHO_DO_NOME) {
            throw ErroDaFerramenta::recusada(self::NOME_INVALIDO);
        }

        return $nome;
    }

    /** @throws ErroDaFerramenta */
    private static function corValida(?string $cor): ?string
    {
        $cor = $cor === null || trim($cor) === '' ? null : mb_strtolower(trim($cor));
        if (!CoresDoMarcador::aceita($cor)) {
            throw ErroDaFerramenta::recusada(self::COR_INVALIDA);
        }

        return $cor;
    }

    private static function descricao(?string $descricao): ?string
    {
        $descricao = $descricao === null ? '' : trim($descricao);

        return $descricao === '' ? null : $descricao;
    }
}
