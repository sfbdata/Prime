<?php

declare(strict_types=1);

namespace App\Conector\Ferramenta;

use App\Comercial\Campanha\CalendarioDasDatasComemorativas;
use App\Comercial\Entity\DataComemorativa;
use App\Comercial\Enum\Automacao;
use App\Comercial\Exception\AutomacaoRecusada;
use App\Comercial\Exception\DataComemorativaRecusada;
use App\Comercial\Repository\AutomacaoDeEmailRepository;
use App\Comercial\Repository\DataComemorativaRepository;
use App\Comercial\UseCase\AlterarAutomacaoUseCase;
use App\Comercial\UseCase\AlterarDataComemorativaUseCase;
use App\Conector\DTO\ChamadaDoConector;
use App\Conector\DTO\RespostaDaFerramenta;
use App\Conector\DTO\ResultadoDaConfirmacao;
use App\Conector\Exception\ErroDaFerramenta;
use App\Conector\Service\GuardaDoMasterDoConector;
use App\Conector\Service\PropostasDoConector;
use App\Shared\Audit\EscopoDeAuditoriaDePlataforma;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * `automacoes_de_email` (`docs/specs/mcp-remoto-oauth.md`, 6.9, PR 18): liga, desliga e ajusta as automações de e-mail e
 * as datas comemorativas do Comercial do Painel do Master. Só para o Master da plataforma; a guarda é a primeira coisa
 * do {@see executar}, do {@see reconferir} e do {@see executarConfirmada} (o Master pode perder o papel entre propor e
 * confirmar), com a recusa neutra {@see NAO_DISPONIVEL}.
 *
 * Ações: `alterar_automacao` (`ligada?`, `atraso?`) e `alterar_data` (`ligada?`, `antecedencia?`, `publico?`). Campo
 * omitido fica com o valor atual. O estado final é montado e conferido com as regras dos UseCases antes de qualquer
 * gravação. Estado final igual ao atual é "nada a fazer" (não grava, não propõe). Estado final desligado é chamada
 * direta. Estado final ligado com alguma mudança (ligar, ou mudar atraso, antecedência ou público) vira proposta (6.7),
 * porque a automação ou a data passa a mandar e-mail. Até propor a ferramenta só LÊ.
 *
 * Toda gravação (direta e confirmada) roda dentro de {@see EscopoDeAuditoriaDePlataforma::executar()}: o `audit_log`
 * sai com `tenant_id` nulo, e a rota `conector_mcp` entra sozinha pelo AuditLogSubscriber. A recusa do UseCase passa com
 * a mensagem dele. Nenhuma resposta traz e-mail de ninguém.
 */
// Não implementa o ExecutorDeProposta (as duas interfaces declaram `TAG`): o AutomacoesDeEmailExecutor repassa para
// o reconferir() e o executarConfirmada() daqui, como o par da mudar_andamento.
final class AutomacoesDeEmailFerramenta implements FerramentaDoConector
{
    public const NOME = 'automacoes_de_email';

    public const ALTERAR_AUTOMACAO = 'alterar_automacao';
    public const ALTERAR_DATA = 'alterar_data';
    public const ACOES = [self::ALTERAR_AUTOMACAO, self::ALTERAR_DATA];

    public const MOTIVO_SO_DO_MASTER = 'so_do_master';
    public const NAO_DISPONIVEL = 'Ferramenta não disponível.';

    /** As mensagens do {@see AlterarAutomacaoUseCase}: a conferência antes de propor recusa com o mesmo texto. */
    public const AUTOMACAO_DESCONHECIDA = AlterarAutomacaoUseCase::DESCONHECIDA;
    public const AUTOMACAO_OBRIGATORIA = AlterarAutomacaoUseCase::OBRIGATORIA;
    public const ATRASO_INVALIDO = AlterarAutomacaoUseCase::ATRASO_INVALIDO;
    public const MUDOU = 'O estado mudou desde a proposta. Peça de novo.';

    private const CAMPOS_DA_ACAO = [
        self::ALTERAR_AUTOMACAO => ['ligada', 'atraso'],
        self::ALTERAR_DATA => ['ligada', 'antecedencia', 'publico'],
    ];

    private const ANTECEDENCIAS = [0 => 'no dia', 1 => '1 dia antes', 3 => '3 dias antes'];
    private const PUBLICOS = ['todos' => 'Todos', 'prim' => 'Primários', 'leads' => 'Base de contatos'];

    public function __construct(
        private readonly AutomacaoDeEmailRepository $automacoes,
        private readonly DataComemorativaRepository $datas,
        private readonly AlterarAutomacaoUseCase $alterarAutomacao,
        private readonly AlterarDataComemorativaUseCase $alterarData,
        private readonly EscopoDeAuditoriaDePlataforma $plataforma,
        private readonly PropostasDoConector $propostas,
    ) {
    }

    public function nome(): string
    {
        return self::NOME;
    }

    public function titulo(): string
    {
        return 'Automações de e-mail do Painel do Master';
    }

    public function descricao(): string
    {
        return 'Liga, desliga e ajusta as automações de e-mail e as datas comemorativas do Comercial (só para o Master da plataforma). Ações: "alterar_automacao" (chave da automação, como veio em ler_comercial; ligada e atraso são opcionais) e "alterar_data" (chave da data; ligada, antecedencia em dias, 0, 1 ou 3, e publico, todos, prim ou leads, são opcionais). Campo que não vier fica como está. Desligar acontece na hora. Ligar, ou mudar atraso, antecedência ou público de uma que fica ligada, espera o sim da pessoa, porque passa a mandar e-mail: mostre o resumo e chame confirmar_acao só se ela disser sim. Pedir o que já está assim não muda nada. A automação "cad" não desliga.';
    }

    public function esquemaDeEntrada(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'acao' => ['type' => 'string', 'enum' => self::ACOES, 'description' => 'alterar_automacao ou alterar_data.'],
                'chave' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 20, 'description' => 'A chave da automação ou da data comemorativa, como veio na ação automacoes de ler_comercial.'],
                'ligada' => ['type' => 'boolean', 'description' => 'Ligar (true) ou desligar (false). Se não vier, fica como está.'],
                'atraso' => ['type' => 'string', 'maxLength' => 10, 'description' => 'Só em alterar_automacao, nas que têm atraso (ex.: 24h, 3d, 85). Se não vier, fica como está.'],
                'antecedencia' => ['type' => 'integer', 'enum' => array_keys(self::ANTECEDENCIAS), 'description' => 'Só em alterar_data. Dias de antecedência do envio: 0 (no dia), 1 ou 3.'],
                'publico' => ['type' => 'string', 'enum' => array_keys(self::PUBLICOS), 'description' => 'Só em alterar_data. todos, prim (primários) ou leads (base de contatos).'],
            ],
            'required' => ['acao', 'chave'],
            'additionalProperties' => false,
        ];
    }

    public function executar(array $argumentos, ChamadaDoConector $chamada): RespostaDaFerramenta
    {
        $this->exigirMaster($chamada);
        [$acao, $chave, $campos] = self::entrada($argumentos);

        return $acao === self::ALTERAR_AUTOMACAO
            ? $this->automacao($chave, $campos, $chamada)
            : $this->data($chave, $campos, $chamada);
    }

    public function reconferir(string $acao, array $dados, ChamadaDoConector $chamada): void
    {
        $this->exigirMaster($chamada);
        $chave = self::textoDosDados($dados, 'chave');
        $antes = self::arrayDosDados($dados, 'antes');
        $depois = self::arrayDosDados($dados, 'depois');
        if (!\array_key_exists('ligada', $depois) || $depois['ligada'] !== true) {
            // Só vira proposta o que termina ligado (6.9); o que desliga é direto e nunca passa por aqui.
            throw ErroDaFerramenta::entradaInvalida();
        }
        if ($acao === self::ALTERAR_AUTOMACAO) {
            $automacao = self::automacaoDaChave($chave);
            $final = self::arrayDosDados($dados, 'depois');
            self::validarAutomacao($automacao, $final['ligada'] ?? null, $final['atraso'] ?? null);
            $atual = $this->estadoDaAutomacao($automacao);
        } elseif ($acao === self::ALTERAR_DATA) {
            $final = self::arrayDosDados($dados, 'depois');
            self::validarData($chave, $final['antecedencia'] ?? null, $final['publico'] ?? null);
            $atual = $this->estadoDaData($chave);
        } else {
            throw ErroDaFerramenta::entradaInvalida();
        }
        if (!self::iguais($atual, $antes)) {
            throw ErroDaFerramenta::recusada(self::MUDOU);
        }
    }

    public function executarConfirmada(string $acao, array $dados, ChamadaDoConector $chamada): ResultadoDaConfirmacao
    {
        $this->exigirMaster($chamada);
        $chave = self::textoDosDados($dados, 'chave');
        $depois = self::arrayDosDados($dados, 'depois');
        $resposta = match ($acao) {
            self::ALTERAR_AUTOMACAO => $this->gravarAutomacao($chave, (bool) ($depois['ligada'] ?? false), isset($depois['atraso']) ? (string) $depois['atraso'] : null, $chamada),
            self::ALTERAR_DATA => $this->gravarData($chave, (bool) ($depois['ligada'] ?? false), (int) ($depois['antecedencia'] ?? 0), (string) ($depois['publico'] ?? ''), $chamada),
            default => throw ErroDaFerramenta::entradaInvalida(),
        };

        return new ResultadoDaConfirmacao($resposta, contagens: ['alteracao' => 1]);
    }

    /** @throws ErroDaFerramenta */
    private function exigirMaster(ChamadaDoConector $chamada): void
    {
        try {
            GuardaDoMasterDoConector::exigir($chamada->contexto->pessoa);
        } catch (AccessDeniedException) {
            throw new ErroDaFerramenta(self::MOTIVO_SO_DO_MASTER, self::NAO_DISPONIVEL);
        }
    }

    /**
     * @param array<string, mixed> $argumentos
     *
     * @return array{0: string, 1: string, 2: array<string, mixed>}
     *
     * @throws ErroDaFerramenta
     */
    private static function entrada(array $argumentos): array
    {
        $acao = $argumentos['acao'] ?? null;
        $chave = $argumentos['chave'] ?? null;
        if (!\is_string($acao) || !isset(self::CAMPOS_DA_ACAO[$acao]) || !\is_string($chave) || $chave === '' || mb_strlen($chave) > 20) {
            throw ErroDaFerramenta::entradaInvalida();
        }
        unset($argumentos['acao'], $argumentos['chave']);
        if (array_diff(array_keys($argumentos), self::CAMPOS_DA_ACAO[$acao]) !== []) {
            throw ErroDaFerramenta::entradaInvalida();
        }
        foreach ($argumentos as $campo => $valor) {
            $valido = match ($campo) {
                'ligada' => \is_bool($valor),
                'antecedencia' => \is_int($valor),
                default => \is_string($valor),
            };
            if (!$valido) {
                throw ErroDaFerramenta::entradaInvalida();
            }
        }

        return [$acao, $chave, $argumentos];
    }

    /** @param array<string, mixed> $campos */
    private function automacao(string $chave, array $campos, ChamadaDoConector $chamada): RespostaDaFerramenta
    {
        $automacao = self::automacaoDaChave($chave);
        $antes = $this->estadoDaAutomacao($automacao);
        $depois = [
            'ligada' => $campos['ligada'] ?? $antes['ligada'],
            'atraso' => $automacao->opcoesDeAtraso() === [] ? null : ($campos['atraso'] ?? $antes['atraso']),
        ];
        self::validarAutomacao($automacao, $depois['ligada'], $depois['atraso']);
        if ($depois === $antes) {
            return self::nadaAFazer($chave);
        }
        if (!$depois['ligada']) {
            return new RespostaDaFerramenta($this->gravarAutomacao($chave, false, $depois['atraso'], $chamada), contagens: ['alteracao' => 1]);
        }
        $resumo = sprintf('Automação "%s": de %s para %s. Ela passa a mandar e-mail.', $automacao->titulo(), self::fraseDaAutomacao($antes), self::fraseDaAutomacao($depois));

        return $this->propostas->propor($chamada, self::NOME, self::ALTERAR_AUTOMACAO, ['chave' => $chave, 'antes' => $antes, 'depois' => $depois], $resumo);
    }

    /** @param array<string, mixed> $campos */
    private function data(string $chave, array $campos, ChamadaDoConector $chamada): RespostaDaFerramenta
    {
        $antes = $this->estadoDaData($chave);
        $depois = [
            'ligada' => $campos['ligada'] ?? $antes['ligada'],
            'antecedencia' => $campos['antecedencia'] ?? $antes['antecedencia'],
            'publico' => $campos['publico'] ?? $antes['publico'],
            'modelo' => $antes['modelo'], // o modelo escolhido (M5.12) não muda por aqui
        ];
        self::validarData($chave, $depois['antecedencia'], $depois['publico']);
        if ($depois === $antes) {
            return self::nadaAFazer($chave);
        }
        if (!$depois['ligada']) {
            return new RespostaDaFerramenta($this->gravarData($chave, false, $depois['antecedencia'], $depois['publico'], $chamada), contagens: ['alteracao' => 1]);
        }
        $nome = CalendarioDasDatasComemorativas::data($chave)['nome'];
        $resumo = sprintf('Data comemorativa "%s": de %s para %s. Ela passa a mandar e-mail.', $nome, self::fraseDaData($antes), self::fraseDaData($depois));

        return $this->propostas->propor($chamada, self::NOME, self::ALTERAR_DATA, ['chave' => $chave, 'antes' => $antes, 'depois' => $depois], $resumo);
    }

    private static function nadaAFazer(string $chave): RespostaDaFerramenta
    {
        return new RespostaDaFerramenta(['estado' => 'nada_a_fazer', 'chave' => $chave, 'mensagem' => 'Já está assim: nada foi alterado.']);
    }

    /** @throws ErroDaFerramenta */
    private static function automacaoDaChave(string $chave): Automacao
    {
        $automacao = Automacao::tryFrom($chave);

        return $automacao !== null && $automacao->temChave() ? $automacao : throw ErroDaFerramenta::recusada(self::AUTOMACAO_DESCONHECIDA);
    }

    /** As mesmas regras do {@see AlterarAutomacaoUseCase}, para recusar antes de propor. */
    private static function validarAutomacao(Automacao $automacao, mixed $ligada, mixed $atraso): void
    {
        if ($automacao->obrigatoria() && $ligada !== true) {
            throw ErroDaFerramenta::recusada(self::AUTOMACAO_OBRIGATORIA);
        }
        $opcoes = $automacao->opcoesDeAtraso();
        if ($opcoes !== [] && !\in_array($atraso, $opcoes, true)) {
            throw ErroDaFerramenta::recusada(self::ATRASO_INVALIDO);
        }
    }

    /** As mesmas regras do {@see AlterarDataComemorativaUseCase}, para recusar antes de propor. */
    private static function validarData(string $chave, mixed $antecedencia, mixed $publico): void
    {
        if (!CalendarioDasDatasComemorativas::existe($chave)) {
            throw ErroDaFerramenta::recusada(AlterarDataComemorativaUseCase::DESCONHECIDA);
        }
        if (!\in_array($antecedencia, CalendarioDasDatasComemorativas::ANTECEDENCIAS, true)) {
            throw ErroDaFerramenta::recusada(AlterarDataComemorativaUseCase::ANTECEDENCIA_INVALIDA);
        }
        if (!\in_array($publico, DataComemorativa::PUBLICOS, true)) {
            throw ErroDaFerramenta::recusada(AlterarDataComemorativaUseCase::PUBLICO_INVALIDO);
        }
    }

    /** @return array{ligada: bool, atraso: ?string} o atraso só conta nas automações que têm seletor */
    private function estadoDaAutomacao(Automacao $automacao): array
    {
        $estado = $this->automacoes->estado($automacao);

        return ['ligada' => $estado->ligada, 'atraso' => $automacao->opcoesDeAtraso() === [] ? null : $estado->atraso];
    }

    /** @return array{ligada: bool, antecedencia: int, publico: string, modelo: ?string} a data sem linha no banco vale o padrão */
    private function estadoDaData(string $chave): array
    {
        return DataComemorativa::estadoOuPadrao($this->datas->estados(), $chave);
    }

    /** @param array{ligada: bool, atraso: ?string} $estado */
    private static function fraseDaAutomacao(array $estado): string
    {
        if (!$estado['ligada']) {
            return 'desligada';
        }

        return $estado['atraso'] === null ? 'ligada' : 'ligada, atraso ' . $estado['atraso'];
    }

    /** @param array{ligada: bool, antecedencia: int, publico: string, modelo: ?string} $estado */
    private static function fraseDaData(array $estado): string
    {
        if (!$estado['ligada']) {
            return 'desligada';
        }

        return sprintf('ligada, %s, para %s', self::ANTECEDENCIAS[$estado['antecedencia']] ?? $estado['antecedencia'], self::PUBLICOS[$estado['publico']] ?? $estado['publico']);
    }

    /**
     * @return array<string, mixed>
     *
     * @throws ErroDaFerramenta
     */
    private function gravarAutomacao(string $chave, bool $ligada, ?string $atraso, ChamadaDoConector $chamada): array
    {
        try {
            $estado = $this->plataforma->executar(fn () => $this->alterarAutomacao->executar($chave, $ligada, $atraso, $chamada->contexto->pessoa));
        } catch (AutomacaoRecusada $e) {
            throw ErroDaFerramenta::recusada($e->getMessage());
        }

        return [
            'estado' => 'concluida',
            'acao' => self::ALTERAR_AUTOMACAO,
            'chave' => $estado->automacao->value,
            'titulo' => $estado->automacao->titulo(),
            'ligada' => $estado->ligada,
            'atraso' => $estado->atraso,
        ];
    }

    /**
     * @return array<string, mixed>
     *
     * @throws ErroDaFerramenta
     */
    private function gravarData(string $chave, bool $ligada, int $antecedencia, string $publico, ChamadaDoConector $chamada): array
    {
        try {
            // Mantém o modelo escolhido para a data (M5.12): o conector não o troca, e nulo voltaria ao padrão da data.
            $modelo = $this->estadoDaData($chave)['modelo'];
            $linha = $this->plataforma->executar(fn () => $this->alterarData->executar($chave, $ligada, $antecedencia, $publico, $chamada->contexto->pessoa, $modelo));
        } catch (DataComemorativaRecusada $e) {
            throw ErroDaFerramenta::recusada($e->getMessage());
        }

        return [
            'estado' => 'concluida',
            'acao' => self::ALTERAR_DATA,
            'chave' => $linha->chave,
            'nome' => $linha->nome,
            'ligada' => $linha->ligada,
            'antecedencia_em_dias' => $linha->antecedencia,
            'publico' => $linha->publico,
        ];
    }

    /**
     * Compara dois estados sem depender da ordem das chaves (o JSON do banco as reordena).
     *
     * @param array<string, mixed> $a
     * @param array<string, mixed> $b
     */
    private static function iguais(array $a, array $b): bool
    {
        ksort($a);
        ksort($b);

        return $a === $b;
    }

    /** @param array<string, mixed> $dados */
    private static function textoDosDados(array $dados, string $campo): string
    {
        $valor = $dados[$campo] ?? null;

        return \is_string($valor) ? $valor : throw ErroDaFerramenta::entradaInvalida();
    }

    /**
     * @param array<string, mixed> $dados
     *
     * @return array<string, mixed>
     */
    private static function arrayDosDados(array $dados, string $campo): array
    {
        $valor = $dados[$campo] ?? null;

        return \is_array($valor) ? $valor : throw ErroDaFerramenta::entradaInvalida();
    }
}
