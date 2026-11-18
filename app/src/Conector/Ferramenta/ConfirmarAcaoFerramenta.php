<?php

declare(strict_types=1);

namespace App\Conector\Ferramenta;

use App\Conector\DTO\ChamadaDoConector;
use App\Conector\DTO\RespostaDaFerramenta;
use App\Conector\Exception\ErroDaFerramenta;
use App\Conector\Service\RequisicaoDoConector;
use App\Conector\UseCase\ConfirmarPropostaDoConectorUseCase;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * `confirmar_acao` (`docs/specs/mcp-remoto-oauth.md`, 6.7, item 3): executa a ação que uma ferramenta de gravação
 * devolveu como proposta, depois de a pessoa dizer sim no chat. Pede o id **e o resumo exato**: onde a caixa de
 * aprovação do app mostra os argumentos, a pessoa lê o que vai acontecer; e o modelo não muda o resumo sem que a
 * confirmação falhe.
 *
 * Escopo `mcp:escrita` (a `ScopePolicy` dá 403 antes do SDK sem ele, 5.1), `destructiveHint: true` e
 * `_meta["anthropic/requiresUserInteraction"]: true`, pelo {@see CatalogoFechadoDoConector}. As anotações são dicas: a
 * garantia é a reconferência no servidor ({@see ConfirmarPropostaDoConectorUseCase}).
 *
 * Teto próprio de chamadas (`conector_confirmacao`, por pessoa e escritório, 8), consumido antes de olhar a proposta.
 * Nunca pede nada ao app (sem elicitation, 6.7, item 2).
 */
final class ConfirmarAcaoFerramenta implements FerramentaDoConector
{
    public const NOME = 'confirmar_acao';

    /** O id da proposta é o valor do {@see \App\Conector\Service\SegredoOpaco}: 43 caracteres base64url. */
    private const FORMATO_DO_ID = '/^[A-Za-z0-9_-]{43}$/';

    public const MOTIVO_LIMITE = 'limite_confirmacao';
    public const LIMITE = 'Muitas confirmações em pouco tempo. Aguarde um minuto e tente de novo.';

    public function __construct(
        private readonly ConfirmarPropostaDoConectorUseCase $confirmar,
        private readonly RateLimiterFactoryInterface $conectorConfirmacaoLimiter,
        private readonly RequisicaoDoConector $requisicao,
    ) {
    }

    public function nome(): string
    {
        return self::NOME;
    }

    public function titulo(): string
    {
        return 'Confirmar a ação proposta';
    }

    public function descricao(): string
    {
        return 'Executa uma ação que outra ferramenta do BlueJus devolveu como proposta (excluir, trocar um valor já preenchido, cadastrar cliente, voltar fase). Só chame depois de mostrar o resumo à pessoa e ela dizer sim. Mande o proposta_id e o resumo exatamente como vieram na proposta. A proposta vale 10 minutos e uma vez só.';
    }

    public function esquemaDeEntrada(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'proposta_id' => ['type' => 'string', 'description' => 'O proposta_id que veio na proposta.'],
                'resumo' => ['type' => 'string', 'description' => 'O resumo da proposta, exatamente como veio, que a pessoa leu e aprovou.'],
            ],
            'required' => ['proposta_id', 'resumo'],
            'additionalProperties' => false,
        ];
    }

    public function executar(array $argumentos, ChamadaDoConector $chamada): RespostaDaFerramenta
    {
        $this->exigirLimite($chamada);

        $propostaId = $argumentos['proposta_id'] ?? null;
        $resumo = $argumentos['resumo'] ?? null;
        if (!\is_string($propostaId) || !\is_string($resumo)) {
            throw ErroDaFerramenta::entradaInvalida();
        }
        if (preg_match(self::FORMATO_DO_ID, $propostaId) !== 1) {
            throw new ErroDaFerramenta(ConfirmarPropostaDoConectorUseCase::MOTIVO_NAO_VALE, ConfirmarPropostaDoConectorUseCase::NAO_VALE);
        }

        $confirmada = $this->confirmar->executar($propostaId, $resumo, $chamada);
        $resultado = $confirmada['resultado'];
        $this->requisicao->anotar('ferramenta_confirmada', $confirmada['ferramenta']);
        $this->requisicao->anotar('acao', $confirmada['acao']);

        return new RespostaDaFerramenta(
            [
                'estado' => 'confirmada',
                'ferramenta' => $confirmada['ferramenta'],
                'acao' => $confirmada['acao'],
                'resultado' => $resultado->dados,
            ],
            contagens: $resultado->contagens,
            ids: $resultado->ids,
        );
    }

    /** @throws ErroDaFerramenta */
    private function exigirLimite(ChamadaDoConector $chamada): void
    {
        $chave = $chamada->contexto->pessoa->getId() . ':' . $chamada->contexto->escritorio->getId();
        if (!$this->conectorConfirmacaoLimiter->create($chave)->consume(1)->isAccepted()) {
            throw new ErroDaFerramenta(self::MOTIVO_LIMITE, self::LIMITE);
        }
    }
}
