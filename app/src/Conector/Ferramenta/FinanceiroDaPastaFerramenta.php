<?php

declare(strict_types=1);

namespace App\Conector\Ferramenta;

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
use App\Entity\Tenant\Tenant;
use App\Inteligencia\Exception\ContextoBloqueadoException;
use App\Inteligencia\Service\VisibilidadeDoFinanceiroDaPasta;
use App\Pasta\DTO\ParcelamentoDaPastaInput;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaDocumento;
use App\Pasta\Entity\PastaPagamento;
use App\Pasta\Exception\AssinaturaDoDocumentoRecusadaException;
use App\Pasta\Exception\PastaDeOutroEscritorioException;
use App\Pasta\Exception\SemPermissaoParaEditarPastaException;
use App\Pasta\Repository\PastaDocumentoRepository;
use App\Pasta\Repository\PastaPagamentoRepository;
use App\Pasta\Repository\PastaRepository;
use App\Pasta\Service\BaseDoPercentual;
use App\Pasta\UseCase\AlterarAssinaturaDoDocumentoUseCase;
use App\Pasta\UseCase\AlterarProBonoDaPastaUseCase;
use App\Pasta\UseCase\AlterarSituacaoContratoUseCase;
use App\Pasta\UseCase\AlternarQuitacaoDoPagamentoUseCase;
use App\Pasta\UseCase\AtualizarValorCausaUseCase;
use App\Pasta\UseCase\CorrigirValorDoPagamentoUseCase;
use App\Pasta\UseCase\ExcluirItensDaPastaUseCase;
use App\Pasta\UseCase\ExcluirPagamentoDaPastaUseCase;
use App\Pasta\UseCase\RegistrarPagamentoDaPastaUseCase;
use App\Pasta\UseCase\RegistrarParcelamentoDaPastaUseCase;
use App\Pasta\UseCase\RenomearDocumentoDoFinanceiroUseCase;
use App\Shared\Service\ValorEmReais;

/**
 * `financeiro_da_pasta` (`docs/specs/mcp-remoto-oauth.md`, 6.6, PR 12). A `acao` é a chave da {@see TabelaDeAbaEChave};
 * a ação fina de `pagamento` e de `documento_assinado` vai em `operacao`:
 *
 *  - `contrato` (`situacao`: pendente ou regular): direto, {@see AlterarSituacaoContratoUseCase} (move o andamento);
 *  - `pro_bono` (`ligado`): direto, {@see AlterarProBonoDaPastaUseCase};
 *  - `valor_da_causa` (`valor`): direto com o campo vazio ({@see AtualizarValorCausaUseCase}); já preenchido, é gravar
 *    por cima e vira proposta;
 *  - `pagamento`: `registrar`, `parcelar`, `marcar_recebido` e `desfazer_recebimento` são diretos
 *    ({@see RegistrarPagamentoDaPastaUseCase}, {@see RegistrarParcelamentoDaPastaUseCase},
 *    {@see AlternarQuitacaoDoPagamentoUseCase}); `corrigir_valor` ({@see CorrigirValorDoPagamentoUseCase}) e `excluir`
 *    ({@see ExcluirPagamentoDaPastaUseCase}, irreversível) são proposta;
 *  - `documento_assinado`: `marcar` e `desmarcar` são diretos ({@see AlterarAssinaturaDoDocumentoUseCase}); `excluir`
 *    (o documento do Financeiro vai para a lixeira, {@see ExcluirItensDaPastaUseCase}) é proposta;
 *  - `renomear_documento` (`documento_id`, `titulo`): troca o nome de um contrato do Financeiro. É trocar um texto
 *    preenchido, então vira proposta ({@see RenomearDocumentoDoFinanceiroUseCase}, PR 14); a confirmação recusa se o
 *    nome mudou desde a proposta.
 *
 * Ordem das recusas: ação e campos → chave do Master e módulo → a pasta por id e escritório (alheia ou na lixeira:
 * "não encontrado") → a guarda com a pasta (cadeado e aba Financeiro) → recurso `pasta` editar → segredo de justiça →
 * {@see VisibilidadeDoFinanceiroDaPasta::podeVer()} (6.2, regra 5). {@see reconferir()} repete tudo, sem gravar.
 * Até propor, a ferramenta só lê: o `propor()` dá `flush`.
 */
final class FinanceiroDaPastaFerramenta implements FerramentaDoConector, ExecutorDeProposta
{
    /**
     * As duas interfaces declaram `TAG`; sem redeclarar aqui o PHP recusa a classe (constante ambígua). Quem registra
     * a ferramenta e o executor são os atributos `AutoconfigureTag` de cada interface, não esta constante.
     */
    public const TAG = FerramentaDoConector::TAG;

    public const NOME = 'financeiro_da_pasta';

    public const ACOES = ['contrato', 'pro_bono', 'valor_da_causa', 'pagamento', 'documento_assinado', 'renomear_documento'];

    public const OPERACOES_DO_PAGAMENTO = ['registrar', 'parcelar', 'marcar_recebido', 'desfazer_recebimento', 'corrigir_valor', 'excluir'];
    public const OPERACOES_DO_DOCUMENTO = ['marcar', 'desmarcar', 'excluir'];

    public const MOTIVO_MUDOU = 'mudou_depois_da_proposta';
    public const MUDOU = 'O que a proposta mudaria já não é o que está no BlueJus. Faça o pedido de novo.';

    private const TAMANHO_DO_TEXTO = 120;

    /** Campos que cada ação (ou ação e operação) aceita além de `acao`, `pasta_id` e `operacao` (6.2, regra 10). */
    private const CAMPOS = [
        'contrato' => ['situacao'],
        'pro_bono' => ['ligado'],
        'valor_da_causa' => ['valor'],
        'pagamento.registrar' => ['descricao', 'valor', 'vencimento'],
        'pagamento.parcelar' => ['tipo', 'base', 'valor', 'percentual', 'entrada', 'parcelas', 'vencimento', 'com_juros', 'taxa_mensal', 'descricao'],
        'pagamento.marcar_recebido' => ['pagamento_id'],
        'pagamento.desfazer_recebimento' => ['pagamento_id'],
        'pagamento.corrigir_valor' => ['pagamento_id', 'valor'],
        'pagamento.excluir' => ['pagamento_id'],
        'documento_assinado.marcar' => ['documento_id'],
        'documento_assinado.desmarcar' => ['documento_id'],
        'documento_assinado.excluir' => ['documento_id'],
        'renomear_documento' => ['documento_id', 'titulo'],
    ];

    /** O que cada ação (ou ação e operação) exige. */
    private const OBRIGATORIOS = [
        'contrato' => ['situacao'],
        'pro_bono' => ['ligado'],
        'valor_da_causa' => ['valor'],
        'pagamento.registrar' => ['descricao', 'valor', 'vencimento'],
        'pagamento.parcelar' => ['tipo'],
        'pagamento.marcar_recebido' => ['pagamento_id'],
        'pagamento.desfazer_recebimento' => ['pagamento_id'],
        'pagamento.corrigir_valor' => ['pagamento_id', 'valor'],
        'pagamento.excluir' => ['pagamento_id'],
        'documento_assinado.marcar' => ['documento_id'],
        'documento_assinado.desmarcar' => ['documento_id'],
        'documento_assinado.excluir' => ['documento_id'],
        'renomear_documento' => ['documento_id', 'titulo'],
    ];

    /** O `maxLength` de cada campo de texto do esquema. */
    private const TAMANHO_DOS_TEXTOS = ['valor' => 20, 'entrada' => 20, 'percentual' => 10, 'taxa_mensal' => 10, 'parcelas' => 3, 'titulo' => RenomearDocumentoDoFinanceiroUseCase::TAMANHO_MAXIMO];

    private const TIPOS = ['contrato', 'custas', 'recorrente', 'exito', 'sucumbencia'];
    private const BASES = ['valor', 'pct'];
    private const SITUACOES = ['pendente', 'regular'];

    public function __construct(
        private readonly GuardaDaAcaoDoConector $guarda,
        private readonly PastaRepository $pastas,
        private readonly PastaPagamentoRepository $pagamentos,
        private readonly PastaDocumentoRepository $documentos,
        private readonly PastaDoContextoDaIa $liberacao,
        private readonly VisibilidadeDoFinanceiroDaPasta $financeiro,
        private readonly ProtecaoDoTextoDoChat $protecao,
        private readonly BaseDoPercentual $base,
        private readonly PropostasDoConector $propostas,
        private readonly AlterarSituacaoContratoUseCase $alterarContrato,
        private readonly AlterarProBonoDaPastaUseCase $alterarProBono,
        private readonly AtualizarValorCausaUseCase $atualizarValorDaCausa,
        private readonly RegistrarPagamentoDaPastaUseCase $registrarPagamento,
        private readonly RegistrarParcelamentoDaPastaUseCase $registrarParcelamento,
        private readonly AlternarQuitacaoDoPagamentoUseCase $alternarQuitacao,
        private readonly CorrigirValorDoPagamentoUseCase $corrigirValor,
        private readonly ExcluirPagamentoDaPastaUseCase $excluirPagamento,
        private readonly AlterarAssinaturaDoDocumentoUseCase $alterarAssinatura,
        private readonly ExcluirItensDaPastaUseCase $excluirItens,
        private readonly RenomearDocumentoDoFinanceiroUseCase $renomearDocumento,
    ) {
    }

    public function nome(): string
    {
        return self::NOME;
    }

    public function titulo(): string
    {
        return 'Financeiro da pasta';
    }

    public function descricao(): string
    {
        return 'Grava no Financeiro de uma pasta do escritório (pasta_id é obrigatório). Ações: contrato (situacao pendente ou regular), '
            . 'pro_bono (ligado verdadeiro ou falso), valor_da_causa (valor em reais; se já houver valor, a troca vira proposta), '
            . 'pagamento (operacao: registrar, parcelar, marcar_recebido, desfazer_recebimento, corrigir_valor ou excluir) e '
            . 'documento_assinado (operacao: marcar, desmarcar ou excluir, para os contratos do Financeiro) e '
            . 'renomear_documento (documento_id e titulo: o novo nome do contrato do Financeiro). '
            . 'Trocar um valor que já existe, renomear documento, excluir pagamento e excluir documento não gravam na hora: voltam como proposta, '
            . 'que a pessoa precisa aprovar com confirmar_acao. Valores em reais, com vírgula nos centavos (12.860,00). '
            . 'Pasta de outro escritório, na lixeira ou com o Financeiro oculto para a pessoa responde "não encontrado"; '
            . 'pasta em segredo de justiça não sai pelo conector.';
    }

    public function esquemaDeEntrada(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'acao' => ['type' => 'string', 'enum' => self::ACOES, 'description' => 'O que fazer no Financeiro.'],
                'pasta_id' => ['type' => 'integer', 'minimum' => 1, 'description' => 'O id da pasta (veja buscar_pastas).'],
                'operacao' => ['type' => 'string', 'enum' => array_values(array_unique([...self::OPERACOES_DO_PAGAMENTO, ...self::OPERACOES_DO_DOCUMENTO])), 'description' => 'Em pagamento: registrar, parcelar, marcar_recebido, desfazer_recebimento, corrigir_valor ou excluir. Em documento_assinado: marcar, desmarcar ou excluir.'],
                'situacao' => ['type' => 'string', 'enum' => self::SITUACOES, 'description' => 'Na acao contrato: pendente ou regular (assinado).'],
                'ligado' => ['type' => 'boolean', 'description' => 'Na acao pro_bono: verdadeiro marca a pasta como pró-bono.'],
                'valor' => ['type' => 'string', 'maxLength' => 20, 'description' => 'Valor em reais, com vírgula nos centavos (12.860,00). No valor_da_causa, registrar, corrigir_valor e parcelar por valor.'],
                'pagamento_id' => ['type' => 'integer', 'minimum' => 1, 'description' => 'O id do pagamento (veja ler_pasta, parte financeiro).'],
                'documento_id' => ['type' => 'integer', 'minimum' => 1, 'description' => 'O id do documento de contrato do Financeiro.'],
                'titulo' => ['type' => 'string', 'maxLength' => RenomearDocumentoDoFinanceiroUseCase::TAMANHO_MAXIMO, 'description' => 'Na acao renomear_documento: o novo nome do documento.'],
                'descricao' => ['type' => 'string', 'maxLength' => self::TAMANHO_DO_TEXTO, 'description' => 'Na operacao registrar: o que é o pagamento. No parcelar: descrição opcional.'],
                'vencimento' => ['type' => 'string', 'maxLength' => 10, 'description' => 'Data do vencimento (AAAA-MM-DD); no parcelar, a do primeiro.'],
                'tipo' => ['type' => 'string', 'enum' => self::TIPOS, 'description' => 'Na operacao parcelar: o tipo do pagamento.'],
                'base' => ['type' => 'string', 'enum' => self::BASES, 'description' => 'Na operacao parcelar: valor (valor fixo) ou pct (percentual do valor da causa).'],
                'percentual' => ['type' => 'string', 'maxLength' => 10, 'description' => 'Na operacao parcelar: o percentual, como 20.'],
                'entrada' => ['type' => 'string', 'maxLength' => 20, 'description' => 'Na operacao parcelar: a entrada em reais; em branco, sem entrada.'],
                'parcelas' => ['type' => 'string', 'maxLength' => 3, 'description' => 'Na operacao parcelar: o número de parcelas (até 60).'],
                'com_juros' => ['type' => 'boolean', 'description' => 'Na operacao parcelar: com juros mensais.'],
                'taxa_mensal' => ['type' => 'string', 'maxLength' => 10, 'description' => 'Na operacao parcelar: juros ao mês em percentual, como 1,5.'],
            ],
            'required' => ['acao', 'pasta_id'],
            'additionalProperties' => false,
        ];
    }

    public function executar(array $argumentos, ChamadaDoConector $chamada): RespostaDaFerramenta
    {
        // Segunda camada: a `ScopePolicy` já deu 403 antes do SDK a quem não tem `mcp:escrita` (5.1).
        if (!$chamada->temEscopo(ServidorDeAutorizacao::ESCOPO_ESCRITA)) {
            throw ErroDaFerramenta::semPermissao();
        }
        [$acao, $pastaId, $operacao, $campos] = self::entrada($argumentos);
        $this->guarda->exigir($chamada, self::NOME, $acao);
        $pasta = $this->conferirPasta($acao, $pastaId, $chamada);
        $escritorio = $chamada->contexto->escritorio;

        return match ($acao) {
            'contrato' => $this->contrato($pasta, $chamada, (string) $campos['situacao']),
            'pro_bono' => $this->proBono($pasta, $chamada, (bool) $campos['ligado']),
            'valor_da_causa' => $this->valorDaCausa($pasta, $chamada, (string) $campos['valor']),
            'pagamento' => $this->pagamento($pasta, $chamada, (string) $operacao, $campos),
            'renomear_documento' => $this->renomear($pasta, $chamada, $escritorio, (int) $campos['documento_id'], (string) $campos['titulo']),
            default => $this->documento($pasta, $chamada, (string) $operacao, $escritorio, (int) $campos['documento_id']),
        };
    }

    public function ferramenta(): string
    {
        return self::NOME;
    }

    public function reconferir(string $acao, array $dados, ChamadaDoConector $chamada): void
    {
        $pastaId = $dados['pasta_id'] ?? null;
        if (!\is_int($pastaId)) {
            throw ErroDaFerramenta::entradaInvalida();
        }
        $pasta = $this->conferirPasta($acao, $pastaId, $chamada);
        $escritorio = $chamada->contexto->escritorio;

        match ($acao) {
            'valor_da_causa' => $this->reconferirValorDaCausa($pasta, $dados),
            'pagamento' => $this->reconferirPagamento($pasta, $escritorio, $dados),
            'documento_assinado' => $this->documentoDoFinanceiro($pasta, $escritorio, (int) ($dados['documento_id'] ?? 0)),
            'renomear_documento' => $this->reconferirRenomeio($pasta, $escritorio, $dados),
            default => throw ErroDaFerramenta::entradaInvalida(),
        };
    }

    public function executarConfirmada(string $acao, array $dados, ChamadaDoConector $chamada): ResultadoDaConfirmacao
    {
        $pastaId = $dados['pasta_id'] ?? null;
        $escritorio = $chamada->contexto->escritorio;
        $pasta = \is_int($pastaId) ? $this->pastas->findOneBy(['id' => $pastaId, 'tenant' => $escritorio]) : null;
        if (!$pasta instanceof Pasta || $pasta->estaExcluida()) {
            throw ErroDaFerramenta::naoEncontrado();
        }

        return match (true) {
            $acao === 'valor_da_causa' => $this->gravarValorDaCausa($pasta, (string) $dados['valor']),
            $acao === 'pagamento' && ($dados['operacao'] ?? null) === 'corrigir_valor' => $this->gravarCorrecao($pasta, $chamada, $dados),
            $acao === 'pagamento' && ($dados['operacao'] ?? null) === 'excluir' => $this->gravarExclusaoDoPagamento($pasta, $escritorio, (int) $dados['pagamento_id']),
            $acao === 'documento_assinado' && ($dados['operacao'] ?? null) === 'excluir' => $this->gravarExclusaoDoDocumento($pasta, $chamada, (int) $dados['documento_id']),
            $acao === 'renomear_documento' => $this->gravarRenomeio($pasta, $chamada, $dados),
            default => throw ErroDaFerramenta::entradaInvalida(),
        };
    }

    /**
     * A ação, o id da pasta, a operação e os campos da ação, todos conferidos e como texto, inteiro ou booleano.
     *
     * @param array<string, mixed> $argumentos
     *
     * @return array{0: string, 1: int, 2: ?string, 3: array<string, mixed>}
     *
     * @throws ErroDaFerramenta
     */
    private static function entrada(array $argumentos): array
    {
        $acao = $argumentos['acao'] ?? null;
        if (!\is_string($acao) || !\in_array($acao, self::ACOES, true)) {
            throw ErroDaFerramenta::entradaInvalida();
        }
        $pastaId = self::inteiro($argumentos['pasta_id'] ?? null) ?? throw ErroDaFerramenta::entradaInvalida();

        $operacao = self::operacao($acao, $argumentos['operacao'] ?? null);
        $chave = $operacao === null ? $acao : $acao . '.' . $operacao;
        unset($argumentos['acao'], $argumentos['pasta_id'], $argumentos['operacao']);
        if (array_diff(array_keys($argumentos), self::CAMPOS[$chave]) !== []) {
            throw ErroDaFerramenta::entradaInvalida();
        }
        foreach (self::OBRIGATORIOS[$chave] as $campo) {
            if (!\array_key_exists($campo, $argumentos)) {
                throw ErroDaFerramenta::entradaInvalida();
            }
        }

        $campos = [];
        foreach ($argumentos as $campo => $valor) {
            $campos[$campo] = self::campo($campo, $valor);
        }

        return [$acao, $pastaId, $operacao, $campos];
    }

    /**
     * A operação da ação: obrigatória e da lista em `pagamento` e `documento_assinado`; nas outras, nunca vem.
     *
     * @throws ErroDaFerramenta
     */
    private static function operacao(string $acao, mixed $operacao): ?string
    {
        $operacoes = match ($acao) {
            'pagamento' => self::OPERACOES_DO_PAGAMENTO,
            'documento_assinado' => self::OPERACOES_DO_DOCUMENTO,
            default => [],
        };
        if ($operacoes === []) {
            return $operacao === null ? null : throw ErroDaFerramenta::entradaInvalida();
        }

        return \is_string($operacao) && \in_array($operacao, $operacoes, true) ? $operacao : throw ErroDaFerramenta::entradaInvalida();
    }

    /** @throws ErroDaFerramenta */
    private static function campo(string $campo, mixed $valor): string|int|bool
    {
        return match ($campo) {
            'pagamento_id', 'documento_id' => self::inteiro($valor) ?? throw ErroDaFerramenta::entradaInvalida(),
            'ligado', 'com_juros' => \is_bool($valor) ? $valor : throw ErroDaFerramenta::entradaInvalida(),
            'situacao' => \is_string($valor) && \in_array($valor, self::SITUACOES, true) ? $valor : throw ErroDaFerramenta::entradaInvalida(),
            'tipo' => \is_string($valor) && \in_array($valor, self::TIPOS, true) ? $valor : throw ErroDaFerramenta::entradaInvalida(),
            'base' => \is_string($valor) && \in_array($valor, self::BASES, true) ? $valor : throw ErroDaFerramenta::entradaInvalida(),
            'descricao' => \is_string($valor) && mb_strlen(trim($valor)) <= self::TAMANHO_DO_TEXTO ? trim($valor) : throw ErroDaFerramenta::entradaInvalida(),
            'vencimento' => \is_string($valor) && ($valor === '' || preg_match('/^\d{4}-\d{2}-\d{2}$/', $valor) === 1) ? $valor : throw ErroDaFerramenta::entradaInvalida(),
            default => self::escalar($campo, $valor),
        };
    }

    /** Valor, percentual, entrada, parcelas e taxa: só texto, até o tamanho do esquema ({@see TAMANHO_DOS_TEXTOS}). */
    private static function escalar(string $campo, mixed $valor): string
    {
        if (\is_string($valor) && mb_strlen(trim($valor)) <= self::TAMANHO_DOS_TEXTOS[$campo]) {
            return trim($valor);
        }

        throw ErroDaFerramenta::entradaInvalida();
    }

    private static function inteiro(mixed $valor): ?int
    {
        if (\is_string($valor) && ctype_digit($valor) && \strlen($valor) <= 9) {
            $valor = (int) $valor;
        }

        return \is_int($valor) && $valor >= 1 ? $valor : null;
    }

    /**
     * Tudo o que a ferramenta confere da pasta, na ordem da classe, e devolve a pasta viva da pessoa.
     *
     * @throws ErroDaFerramenta
     */
    private function conferirPasta(string $acao, int $pastaId, ChamadaDoConector $chamada): Pasta
    {
        $escritorio = $chamada->contexto->escritorio;
        $pasta = $this->pastas->findOneBy(['id' => $pastaId, 'tenant' => $escritorio]);
        if (!$pasta instanceof Pasta || $pasta->estaExcluida()) {
            throw ErroDaFerramenta::naoEncontrado();
        }
        $this->guarda->exigir($chamada, self::NOME, $acao, $pasta);
        $this->guarda->exigirEdicaoDaPasta($chamada, $pasta);
        try {
            $this->liberacao->liberar($chamada->contexto->pessoa, $escritorio, $pastaId);
        } catch (PastaDaIaNaoEncontradaException) {
            throw ErroDaFerramenta::naoEncontrado();
        } catch (ContextoBloqueadoException) {
            throw ErroDaFerramenta::emSegredo();
        }
        if (!$this->financeiro->podeVer($chamada->contexto->pessoa, $escritorio, $pasta)) {
            throw ErroDaFerramenta::naoEncontrado();
        }

        return $pasta;
    }

    private function contrato(Pasta $pasta, ChamadaDoConector $chamada, string $situacao): RespostaDaFerramenta
    {
        $nova = strtoupper($situacao);
        $mudou = $pasta->getSituacaoContrato() !== $nova;
        $this->gravando(fn () => $this->alterarContrato->executar($pasta, $nova, $chamada->contexto->pessoa, $chamada->contexto->escritorio));

        return $this->direta($pasta, 'contrato', null, ['situacao' => $situacao, 'mudou' => $mudou]);
    }

    private function proBono(Pasta $pasta, ChamadaDoConector $chamada, bool $ligado): RespostaDaFerramenta
    {
        $mudou = $this->gravando(fn () => $this->alterarProBono->executar($pasta, $ligado, $chamada->contexto->pessoa, $chamada->contexto->escritorio));

        return $this->direta($pasta, 'pro_bono', null, ['pro_bono' => $ligado, 'mudou' => $mudou]);
    }

    private function valorDaCausa(Pasta $pasta, ChamadaDoConector $chamada, string $valor): RespostaDaFerramenta
    {
        $decimal = self::exigirValor($valor, 'valor');
        $atual = $pasta->getValorCausa();
        if ($atual === null || $atual === '') {
            $this->gravando(fn () => $this->atualizarValorDaCausa->executar($pasta, $valor));

            return $this->direta($pasta, 'valor_da_causa', null, ['valor_da_causa' => self::reais($decimal)]);
        }

        $resumo = sprintf(
            'Trocar o valor dos honorários contratuais da pasta %s: de %s para %s.',
            $this->nomeDaPasta($pasta, $chamada->contexto->escritorio),
            self::reais($atual),
            self::reais($decimal),
        );

        return $this->propostas->propor($chamada, self::NOME, 'valor_da_causa', [
            'pasta_id' => (int) $pasta->getId(),
            'valor' => self::textoDoValor($decimal),
            'valor_anterior' => self::textoDoValor($atual),
        ], $resumo);
    }

    /** @param array<string, mixed> $campos */
    private function pagamento(Pasta $pasta, ChamadaDoConector $chamada, string $operacao, array $campos): RespostaDaFerramenta
    {
        if ($operacao === 'registrar') {
            return $this->lancar($pasta, $chamada, $campos);
        }
        if ($operacao === 'parcelar') {
            return $this->parcelar($pasta, $chamada, $campos);
        }

        $pagamento = $this->pagamentoDaPasta($pasta, $chamada->contexto->escritorio, (int) $campos['pagamento_id']);

        return match ($operacao) {
            'marcar_recebido', 'desfazer_recebimento' => $this->quitacao($pasta, $pagamento, $operacao),
            'corrigir_valor' => $this->proporCorrecao($pasta, $pagamento, $chamada, (string) $campos['valor']),
            default => $this->proporExclusaoDoPagamento($pasta, $pagamento, $chamada),
        };
    }

    /** @param array<string, mixed> $campos */
    private function lancar(Pasta $pasta, ChamadaDoConector $chamada, array $campos): RespostaDaFerramenta
    {
        $lancado = $this->gravando(fn () => $this->registrarPagamento->executar(
            $pasta,
            $chamada->contexto->pessoa,
            $chamada->contexto->escritorio,
            (string) $campos['descricao'],
            (string) $campos['valor'],
            (string) $campos['vencimento'],
        ));

        return $this->direta($pasta, 'pagamento', 'registrar', ['pagamento_id' => (int) $lancado->getId()], ['pagamento' => [(int) $lancado->getId()]]);
    }

    /** @param array<string, mixed> $campos */
    private function parcelar(Pasta $pasta, ChamadaDoConector $chamada, array $campos): RespostaDaFerramenta
    {
        $entrada = new ParcelamentoDaPastaInput(
            tipo: (string) $campos['tipo'],
            base: (string) ($campos['base'] ?? 'valor'),
            valorTotal: (string) ($campos['valor'] ?? ''),
            percentual: (string) ($campos['percentual'] ?? ''),
            entrada: (string) ($campos['entrada'] ?? ''),
            parcelas: (string) ($campos['parcelas'] ?? '1'),
            primeiroVencimento: (string) ($campos['vencimento'] ?? ''),
            comJuros: (bool) ($campos['com_juros'] ?? false),
            taxaMensal: (string) ($campos['taxa_mensal'] ?? ''),
            descricao: (string) ($campos['descricao'] ?? ''),
        );
        $saida = $this->gravando(fn () => $this->registrarParcelamento->executar($pasta, $chamada->contexto->pessoa, $chamada->contexto->escritorio, $entrada));

        return $this->direta(
            $pasta,
            'pagamento',
            'parcelar',
            ['pagamento_ids' => $saida->ids, 'quantidade' => $saida->quantidade, 'total_lancado' => self::reais($saida->totalLancado)],
            ['pagamento' => $saida->ids],
        );
    }

    private function quitacao(Pasta $pasta, PastaPagamento $pagamento, string $operacao): RespostaDaFerramenta
    {
        $receber = $operacao === 'marcar_recebido';
        if ($pagamento->estaPago() === $receber) {
            throw self::recusa($receber ? 'Este pagamento já está marcado como recebido.' : 'Este pagamento não está marcado como recebido.');
        }
        $this->gravando(fn () => $this->alternarQuitacao->executar($pagamento));

        return $this->direta($pasta, 'pagamento', $operacao, ['pagamento_id' => (int) $pagamento->getId(), 'recebido' => $pagamento->estaPago()], ['pagamento' => [(int) $pagamento->getId()]]);
    }

    private function proporCorrecao(Pasta $pasta, PastaPagamento $pagamento, ChamadaDoConector $chamada, string $valor): RespostaDaFerramenta
    {
        $decimal = self::exigirValor($valor, 'valor');
        if (ValorEmReais::paraCentavos($decimal) <= 0) {
            throw self::recusa('Informe um valor maior que zero.');
        }
        $anterior = $this->base->valorCalculado($pagamento);
        $escritorio = $chamada->contexto->escritorio;
        $resumo = sprintf(
            'Corrigir o valor do pagamento "%s" da pasta %s: de %s para %s.',
            $this->protecao->rotulo($escritorio, $pagamento->getDescricao(), 'sem descrição'),
            $this->nomeDaPasta($pasta, $escritorio),
            self::reais($anterior),
            self::reais($decimal),
        );

        return $this->propostas->propor($chamada, self::NOME, 'pagamento', [
            'pasta_id' => (int) $pasta->getId(),
            'operacao' => 'corrigir_valor',
            'pagamento_id' => (int) $pagamento->getId(),
            'valor' => self::textoDoValor($decimal),
            'valor_anterior' => self::textoDoValor($anterior),
        ], $resumo);
    }

    private function proporExclusaoDoPagamento(Pasta $pasta, PastaPagamento $pagamento, ChamadaDoConector $chamada): RespostaDaFerramenta
    {
        $escritorio = $chamada->contexto->escritorio;
        $resumo = sprintf(
            'Excluir o pagamento "%s" (%s) da pasta %s. Não há como desfazer.',
            $this->protecao->rotulo($escritorio, $pagamento->getDescricao(), 'sem descrição'),
            self::reais($this->base->valorCalculado($pagamento)),
            $this->nomeDaPasta($pasta, $escritorio),
        );

        return $this->propostas->propor($chamada, self::NOME, 'pagamento', [
            'pasta_id' => (int) $pasta->getId(),
            'operacao' => 'excluir',
            'pagamento_id' => (int) $pagamento->getId(),
        ], $resumo);
    }

    private function documento(Pasta $pasta, ChamadaDoConector $chamada, string $operacao, Tenant $escritorio, int $documentoId): RespostaDaFerramenta
    {
        $documento = $this->documentoDoFinanceiro($pasta, $escritorio, $documentoId);
        $pessoa = $chamada->contexto->pessoa;

        if ($operacao === 'excluir') {
            $resumo = sprintf(
                'Mandar para a lixeira o documento "%s" do Financeiro da pasta %s.',
                $this->protecao->rotulo($escritorio, $documento->getTitulo(), 'sem nome'),
                $this->nomeDaPasta($pasta, $escritorio),
            );

            return $this->propostas->propor($chamada, self::NOME, 'documento_assinado', [
                'pasta_id' => (int) $pasta->getId(),
                'operacao' => 'excluir',
                'documento_id' => (int) $documento->getId(),
            ], $resumo);
        }

        $this->gravando(fn () => $operacao === 'marcar'
            ? $this->alterarAssinatura->marcar($pasta, $documento, $pessoa, $escritorio)
            : $this->alterarAssinatura->desmarcar($pasta, $documento, $pessoa, $escritorio));

        return $this->direta(
            $pasta,
            'documento_assinado',
            $operacao,
            ['documento_id' => (int) $documento->getId(), 'assinado' => $documento->estaAssinado()],
            ['documento' => [(int) $documento->getId()]],
        );
    }

    private function renomear(Pasta $pasta, ChamadaDoConector $chamada, Tenant $escritorio, int $documentoId, string $titulo): RespostaDaFerramenta
    {
        $documento = $this->documentoDoFinanceiro($pasta, $escritorio, $documentoId);
        $novo = $this->gravando(static fn () => RenomearDocumentoDoFinanceiroUseCase::normalizar($titulo));
        if ($novo === $documento->getTitulo()) {
            throw self::recusa('O documento já tem este nome.');
        }
        $resumo = sprintf(
            'Renomear o documento "%s" do Financeiro da pasta %s para "%s".',
            $this->protecao->rotulo($escritorio, $documento->getTitulo(), 'sem nome'),
            $this->nomeDaPasta($pasta, $escritorio),
            $this->protecao->rotulo($escritorio, $novo, 'sem nome'),
        );

        return $this->propostas->propor($chamada, self::NOME, 'renomear_documento', [
            'pasta_id' => (int) $pasta->getId(),
            'documento_id' => (int) $documento->getId(),
            'titulo' => $novo,
            'titulo_anterior' => $documento->getTitulo(),
        ], $resumo);
    }

    /** @param array<string, mixed> $dados */
    private function reconferirRenomeio(Pasta $pasta, Tenant $escritorio, array $dados): void
    {
        $documento = $this->documentoDoFinanceiro($pasta, $escritorio, (int) ($dados['documento_id'] ?? 0));
        $this->gravando(static fn () => RenomearDocumentoDoFinanceiroUseCase::normalizar((string) ($dados['titulo'] ?? '')));
        if (($dados['titulo_anterior'] ?? null) !== $documento->getTitulo()) {
            throw new ErroDaFerramenta(self::MOTIVO_MUDOU, self::MUDOU);
        }
    }

    /** @param array<string, mixed> $dados */
    private function reconferirValorDaCausa(Pasta $pasta, array $dados): void
    {
        self::exigirValor($dados['valor'] ?? null, 'valor');
        $anterior = self::exigirValor($dados['valor_anterior'] ?? null, 'valor_anterior');
        $atual = $pasta->getValorCausa();
        if ($atual === null || ValorEmReais::paraCentavos($anterior) !== ValorEmReais::paraCentavos($atual)) {
            throw new ErroDaFerramenta(self::MOTIVO_MUDOU, self::MUDOU);
        }
    }

    /** @param array<string, mixed> $dados */
    private function reconferirPagamento(Pasta $pasta, Tenant $escritorio, array $dados): void
    {
        $operacao = $dados['operacao'] ?? null;
        if (!\in_array($operacao, ['corrigir_valor', 'excluir'], true)) {
            throw ErroDaFerramenta::entradaInvalida();
        }
        $pagamento = $this->pagamentoDaPasta($pasta, $escritorio, (int) ($dados['pagamento_id'] ?? 0));
        if ($operacao === 'excluir') {
            return;
        }
        $decimal = self::exigirValor($dados['valor'] ?? null, 'valor');
        $anterior = self::exigirValor($dados['valor_anterior'] ?? null, 'valor_anterior');
        if (ValorEmReais::paraCentavos($decimal) <= 0) {
            throw self::recusa('Informe um valor maior que zero.');
        }
        if (ValorEmReais::paraCentavos($anterior) !== ValorEmReais::paraCentavos($this->base->valorCalculado($pagamento))) {
            throw new ErroDaFerramenta(self::MOTIVO_MUDOU, self::MUDOU);
        }
    }

    private function gravarValorDaCausa(Pasta $pasta, string $valor): ResultadoDaConfirmacao
    {
        $decimal = self::exigirValor($valor, 'valor');
        $this->gravando(fn () => $this->atualizarValorDaCausa->executar($pasta, $valor));

        return new ResultadoDaConfirmacao(
            ['pasta_id' => (int) $pasta->getId(), 'acao' => 'valor_da_causa', 'valor_da_causa' => self::reais($decimal)],
            [(int) $pasta->getId()],
            ['valor_da_causa' => 1],
            ['pasta' => [(int) $pasta->getId()]],
        );
    }

    /** @param array<string, mixed> $dados */
    private function gravarCorrecao(Pasta $pasta, ChamadaDoConector $chamada, array $dados): ResultadoDaConfirmacao
    {
        $escritorio = $chamada->contexto->escritorio;
        $pagamento = $this->pagamentoDaPasta($pasta, $escritorio, (int) $dados['pagamento_id']);
        $alterado = $this->gravando(fn () => $this->corrigirValor->executar($pagamento, $pasta, $escritorio, (string) $dados['valor'], (string) $dados['valor_anterior']));

        return new ResultadoDaConfirmacao(
            ['pasta_id' => (int) $pasta->getId(), 'acao' => 'pagamento', 'operacao' => 'corrigir_valor', 'pagamento_id' => (int) $pagamento->getId(), 'alterado' => $alterado],
            [(int) $pagamento->getId()],
            ['pagamentos' => 1],
            ['pasta' => [(int) $pasta->getId()], 'pagamento' => [(int) $pagamento->getId()]],
        );
    }

    /** @param array<string, mixed> $dados */
    private function gravarRenomeio(Pasta $pasta, ChamadaDoConector $chamada, array $dados): ResultadoDaConfirmacao
    {
        $escritorio = $chamada->contexto->escritorio;
        $documento = $this->documentoDoFinanceiro($pasta, $escritorio, (int) ($dados['documento_id'] ?? 0));
        $titulo = $this->gravando(fn () => $this->renomearDocumento->executar($pasta, $documento, (string) ($dados['titulo'] ?? ''), $escritorio));
        $id = (int) $documento->getId();

        return new ResultadoDaConfirmacao(
            ['pasta_id' => (int) $pasta->getId(), 'acao' => 'renomear_documento', 'documento_id' => $id, 'titulo' => $this->protecao->rotulo($escritorio, $titulo, 'sem nome')],
            [$id],
            ['renomeados' => 1],
            ['pasta' => [(int) $pasta->getId()], 'documento' => [$id]],
        );
    }

    private function gravarExclusaoDoPagamento(Pasta $pasta, Tenant $escritorio, int $pagamentoId): ResultadoDaConfirmacao
    {
        $pagamento = $this->pagamentoDaPasta($pasta, $escritorio, $pagamentoId);
        $this->gravando(fn () => $this->excluirPagamento->executar($pagamento));

        return new ResultadoDaConfirmacao(
            ['pasta_id' => (int) $pasta->getId(), 'acao' => 'pagamento', 'operacao' => 'excluir', 'excluidos' => [['pagamento_id' => $pagamentoId]]],
            [$pagamentoId],
            ['excluidos' => 1],
            ['pasta' => [(int) $pasta->getId()], 'pagamento' => [$pagamentoId]],
        );
    }

    private function gravarExclusaoDoDocumento(Pasta $pasta, ChamadaDoConector $chamada, int $documentoId): ResultadoDaConfirmacao
    {
        $escritorio = $chamada->contexto->escritorio;
        $documento = $this->documentoDoFinanceiro($pasta, $escritorio, $documentoId);
        $this->gravando(fn () => $this->excluirItens->executar($pasta, [$documento], [], $chamada->contexto->pessoa, $escritorio));

        return new ResultadoDaConfirmacao(
            ['pasta_id' => (int) $pasta->getId(), 'acao' => 'documento_assinado', 'operacao' => 'excluir', 'excluidos' => [['documento_id' => $documentoId]], 'destino' => 'lixeira'],
            [$documentoId],
            ['excluidos' => 1],
            ['pasta' => [(int) $pasta->getId()], 'documento' => [$documentoId]],
        );
    }

    /** @throws ErroDaFerramenta */
    private function pagamentoDaPasta(Pasta $pasta, Tenant $escritorio, int $pagamentoId): PastaPagamento
    {
        return $this->pagamentos->findByIdAndPastaAndTenant($pagamentoId, $pasta, $escritorio) ?? throw ErroDaFerramenta::naoEncontrado();
    }

    /**
     * O documento de contrato do Financeiro: desta pasta e deste escritório, fora da lixeira.
     *
     * @throws ErroDaFerramenta
     */
    private function documentoDoFinanceiro(Pasta $pasta, Tenant $escritorio, int $documentoId): PastaDocumento
    {
        $documento = $this->documentos->findByIdAndTenant($documentoId, $escritorio);
        if (
            $documento === null
            || $documento->getPasta()?->getId() !== $pasta->getId()
            || $documento->getCategoria() !== PastaDocumento::CATEGORIA_CONTRATO
            || $documento->estaNaLixeira()
        ) {
            throw ErroDaFerramenta::naoEncontrado();
        }

        return $documento;
    }

    /**
     * Roda o UseCase e traduz a recusa dele. As mensagens dos UseCases são fixas (regra de negócio, sem dado do banco
     * nem do pedido); a do valor que mudou leva o valor atual e por isso vira a mensagem fixa da ferramenta.
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
        } catch (PastaDeOutroEscritorioException|SemPermissaoParaEditarPastaException) {
            throw ErroDaFerramenta::naoEncontrado();
        } catch (\UnexpectedValueException) {
            throw new ErroDaFerramenta(self::MOTIVO_MUDOU, self::MUDOU);
        } catch (AssinaturaDoDocumentoRecusadaException|\InvalidArgumentException $e) {
            throw self::recusa($e->getMessage());
        } catch (\DomainException $e) {
            // A pasta ou o pagamento que não é do escritório: "não encontrado". O resto é regra do UseCase.
            if (str_ends_with($e->getMessage(), 'não encontrada.') || str_ends_with($e->getMessage(), 'não encontrado nesta pasta.')) {
                throw ErroDaFerramenta::naoEncontrado();
            }

            throw self::recusa($e->getMessage());
        }
    }

    private static function recusa(string $mensagem): ErroDaFerramenta
    {
        return new ErroDaFerramenta(ErroDaFerramenta::MOTIVO_ENTRADA_INVALIDA, $mensagem);
    }

    /**
     * O valor em decimal do banco ("12860.00"); texto que não é dinheiro dá a recusa fixa do UseCase.
     *
     * @throws ErroDaFerramenta
     */
    private static function exigirValor(mixed $valor, string $campo): string
    {
        if (!\is_string($valor) || trim($valor) === '') {
            throw ErroDaFerramenta::filtroInvalido($campo);
        }
        try {
            $decimal = ValorEmReais::normalizar($valor, 'valor');
        } catch (\InvalidArgumentException $e) {
            throw self::recusa($e->getMessage());
        }

        return $decimal ?? throw ErroDaFerramenta::filtroInvalido($campo);
    }

    /** O decimal do banco no texto que os UseCases leem ("12.860,00"). */
    private static function textoDoValor(string $decimal): string
    {
        return number_format((float) $decimal, 2, ',', '.');
    }

    /** O dinheiro como a pessoa lê ("R$ 12.860,00"). */
    private static function reais(string $decimal): string
    {
        return 'R$ ' . self::textoDoValor($decimal);
    }

    private function nomeDaPasta(Pasta $pasta, Tenant $escritorio): string
    {
        return $this->protecao->rotulo($escritorio, $pasta->getNup() ?? '', 'sem número');
    }

    /**
     * @param array<string, mixed>     $extras
     * @param array<string, list<int>> $ids
     */
    private function direta(Pasta $pasta, string $acao, ?string $operacao, array $extras, array $ids = []): RespostaDaFerramenta
    {
        $dados = ['estado' => 'gravado', 'pasta_id' => (int) $pasta->getId(), 'acao' => $acao];
        if ($operacao !== null) {
            $dados['operacao'] = $operacao;
        }

        return new RespostaDaFerramenta($dados + $extras, contagens: ['gravados' => 1], ids: ['pasta' => [(int) $pasta->getId()]] + $ids);
    }
}
