<?php

declare(strict_types=1);

namespace App\Conector\Ferramenta;

use App\Conector\DTO\ChamadaDoConector;
use App\Conector\DTO\RespostaDaFerramenta;
use App\Conector\DTO\ResultadoDaConfirmacao;
use App\Conector\Exception\ErroDaFerramenta;
use App\Conector\Service\PropostasDoConector;
use App\Pasta\DTO\EditarPastaDTO;
use App\Pasta\Entity\Pasta;
use App\Pasta\UseCase\EditarPastaUseCase;
use App\Sync\Service\ReconciliadorDePasta;
use App\Sync\Service\SincronizacaoPastaDispatcher;

/**
 * `editar_dados` da `atualizar_pasta` (`docs/specs/mcp-remoto-oauth.md`, 6.6): o número da pasta e o identificador do
 * cliente, como o "Editar dados" da tela (`pasta_edit`, que exige o recurso `pasta` de editar). A ação da pasta não muda
 * por aqui (decisão do dono de 23/10/2026), nem a situação (que é a ação `situacao`).
 *
 * Preencher campo vazio grava na hora. Trocar ou apagar um valor já preenchido é gravar por cima (abertura da 6.6):
 * vira proposta, com o antes e o depois no resumo; numa chamada que mistura as duas coisas, a chamada inteira vira
 * proposta. Na confirmação, o antes guardado tem de ser o que está no banco; senão, "a pasta mudou".
 *
 * Depois de gravar, como a tela, pede ao Drive que renomeie a pasta quando o nome mudou (só com o Drive conectado).
 */
final class AtualizarPastaDados implements AtualizarPastaGrupo
{
    public const NUP_VAZIO = 'O número da pasta não pode ficar vazio.';
    public const RECUSA = 'O BlueJus recusou editar os dados da pasta. Confira o número e o identificador e tente de novo.';

    private const ROTULOS = ['nup' => 'Número da pasta', 'nome_cliente' => 'Identificador do cliente'];

    public function __construct(
        private readonly AtualizarPastaAcesso $acesso,
        private readonly AtualizarPastaTexto $texto,
        private readonly PropostasDoConector $propostas,
        private readonly EditarPastaUseCase $editar,
        private readonly SincronizacaoPastaDispatcher $drive,
    ) {
    }

    public function acoes(): array
    {
        return ['editar_dados'];
    }

    public function executar(AtualizarPastaEntrada $entrada, ChamadaDoConector $chamada): RespostaDaFerramenta
    {
        if (!$entrada->tem('nup') && !$entrada->tem('nome_cliente')) {
            throw ErroDaFerramenta::entradaInvalida();
        }
        $pasta = $this->acesso->viva($chamada, 'editar_dados', $entrada->pastaId(), true);
        $mudancas = self::mudancas($pasta, $entrada);
        if ($mudancas === []) {
            throw ErroDaFerramenta::recusada(AtualizarPastaFerramenta::NADA_A_MUDAR);
        }
        $id = (int) $pasta->getId();
        if (!self::trocaAlgo($mudancas)) {
            return new RespostaDaFerramenta($this->gravar($pasta, $chamada, $mudancas), ids: ['pasta' => [$id]]);
        }

        return $this->propostas->propor($chamada, AtualizarPastaFerramenta::NOME, 'editar_dados', ['pasta_id' => $id, 'mudancas' => $mudancas], $this->resumo($pasta, $chamada, $mudancas));
    }

    public function reconferir(string $acao, array $dados, ChamadaDoConector $chamada): void
    {
        $this->conferida($dados, $chamada);
    }

    public function executarConfirmada(string $acao, array $dados, ChamadaDoConector $chamada): ResultadoDaConfirmacao
    {
        $pasta = $this->conferida($dados, $chamada);
        $id = (int) $pasta->getId();

        return new ResultadoDaConfirmacao($this->gravar($pasta, $chamada, self::mudancasDosDados($dados)), [$id], ['pasta' => 1], ['pasta' => [$id]]);
    }

    /**
     * Só o que muda de fato, campo a campo, com o valor de antes (null = vazio) e o de depois, já normalizados como a
     * entidade grava (número aparado e em maiúsculas; identificador em maiúsculas, vazio vira nulo).
     *
     * @return array<string, array{antes: ?string, depois: ?string}>
     *
     * @throws ErroDaFerramenta
     */
    private static function mudancas(Pasta $pasta, AtualizarPastaEntrada $entrada): array
    {
        $pedidos = [];
        if ($entrada->tem('nup')) {
            $pedidos['nup'] = mb_strtoupper(trim($entrada->textoObrigatorio('nup')));
            if ($pedidos['nup'] === '') {
                throw ErroDaFerramenta::recusada(self::NUP_VAZIO);
            }
        }
        if ($entrada->tem('nome_cliente')) {
            $nome = mb_strtoupper(AtualizarPastaTexto::umaLinha($entrada->textoObrigatorio('nome_cliente')));
            $pedidos['nome_cliente'] = $nome === '' ? null : $nome;
        }
        $atuais = self::atuais($pasta);
        $mudancas = [];
        foreach ($pedidos as $campo => $depois) {
            if ($atuais[$campo] !== $depois) {
                $mudancas[$campo] = ['antes' => $atuais[$campo], 'depois' => $depois];
            }
        }

        return $mudancas;
    }

    /** @return array{nup: ?string, nome_cliente: ?string} */
    private static function atuais(Pasta $pasta): array
    {
        $nup = $pasta->getNup();
        $nome = $pasta->getNomeCliente();

        return ['nup' => $nup === null || $nup === '' ? null : $nup, 'nome_cliente' => $nome === null || $nome === '' ? null : $nome];
    }

    /** @param array<string, array{antes: ?string, depois: ?string}> $mudancas */
    private static function trocaAlgo(array $mudancas): bool
    {
        foreach ($mudancas as $mudanca) {
            if ($mudanca['antes'] !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * As recusas de acesso de novo e o antes de cada campo igual ao banco.
     *
     * @param array<string, mixed> $dados
     *
     * @throws ErroDaFerramenta
     */
    private function conferida(array $dados, ChamadaDoConector $chamada): Pasta
    {
        $pasta = $this->acesso->viva($chamada, 'editar_dados', AtualizarPastaRecusa::inteiro($dados, 'pasta_id'), true);
        $atuais = self::atuais($pasta);
        foreach (self::mudancasDosDados($dados) as $campo => $mudanca) {
            if ($atuais[$campo] !== $mudanca['antes']) {
                throw ErroDaFerramenta::recusada(AtualizarPastaFerramenta::MUDOU);
            }
        }

        return $pasta;
    }

    /**
     * @param array<string, mixed> $dados
     *
     * @return array<string, array{antes: ?string, depois: ?string}>
     *
     * @throws ErroDaFerramenta
     */
    private static function mudancasDosDados(array $dados): array
    {
        $mudancas = $dados['mudancas'] ?? null;
        if (!\is_array($mudancas) || $mudancas === []) {
            throw ErroDaFerramenta::entradaInvalida();
        }
        $conferidas = [];
        foreach ($mudancas as $campo => $mudanca) {
            if (!isset(self::ROTULOS[$campo]) || !\is_array($mudanca)) {
                throw ErroDaFerramenta::entradaInvalida();
            }
            $conferidas[(string) $campo] = ['antes' => AtualizarPastaRecusa::texto($mudanca, 'antes'), 'depois' => AtualizarPastaRecusa::texto($mudanca, 'depois')];
        }

        return $conferidas;
    }

    /** @param array<string, array{antes: ?string, depois: ?string}> $mudancas */
    private function resumo(Pasta $pasta, ChamadaDoConector $chamada, array $mudancas): string
    {
        $escritorio = $chamada->contexto->escritorio;
        $linhas = ['Editar os dados da pasta ' . $this->texto->numero($pasta, $escritorio) . ':'];
        foreach ($mudancas as $campo => $mudanca) {
            $antes = $mudanca['antes'] === null ? null : AtualizarPastaTexto::citado($this->texto->protegido($escritorio, $mudanca['antes']));
            $depois = $mudanca['depois'] === null ? null : AtualizarPastaTexto::citado(AtualizarPastaTexto::umaLinha($mudanca['depois']));
            $linhas[] = self::ROTULOS[$campo] . ': ' . match (true) {
                $antes === null => 'preencher com ' . $depois . ' (o campo está vazio).',
                $depois === null => 'apagar ' . $antes . '.',
                default => 'trocar ' . $antes . ' por ' . $depois . '.',
            };
        }

        return implode("\n", $linhas);
    }

    /**
     * Grava pelo UseCase da tela, com o que não muda igual ao que está na pasta.
     *
     * @param array<string, array{antes: ?string, depois: ?string}> $mudancas
     *
     * @return array<string, mixed>
     *
     * @throws ErroDaFerramenta
     */
    private function gravar(Pasta $pasta, ChamadaDoConector $chamada, array $mudancas): array
    {
        $contexto = $chamada->contexto;
        $nomeAntes = ReconciliadorDePasta::nomeEsperado($pasta->getNup(), $pasta->getNomeCliente(), $pasta->getNomeAcao());
        $nup = \array_key_exists('nup', $mudancas) ? (string) $mudancas['nup']['depois'] : (string) $pasta->getNup();
        $nome = \array_key_exists('nome_cliente', $mudancas) ? $mudancas['nome_cliente']['depois'] : $pasta->getNomeCliente();
        $dto = new EditarPastaDTO(nup: $nup, nomeCliente: $nome, nomeAcao: $pasta->getNomeAcao(), situacao: $pasta->getSituacao());
        AtualizarPastaRecusa::gravando(fn () => $this->editar->executar($dto, $pasta, $contexto->pessoa), self::RECUSA);
        $this->drive->despacharSeNomeMudou($pasta, $contexto->pessoa, $contexto->escritorio, $nomeAntes);
        $identificador = $pasta->getNomeCliente();

        return [
            'estado' => 'concluida',
            'acao' => 'editar_dados',
            'pasta_id' => (int) $pasta->getId(),
            'numero' => $this->texto->numero($pasta, $contexto->escritorio),
            'identificador' => $identificador === null || $identificador === '' ? null : $this->texto->protegido($contexto->escritorio, $identificador),
        ];
    }
}
