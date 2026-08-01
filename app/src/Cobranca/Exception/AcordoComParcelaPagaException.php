<?php

declare(strict_types=1);

namespace App\Cobranca\Exception;

/**
 * Cancelamento tentado sobre um Acordo cujas parcelas já receberam pagamento (spec
 * `cobranca-cancelar-acordo.md` §D4).
 *
 * O motivo é de DINHEIRO, não de integridade referencial: cancelado, o acordo deixa de ser vigente e
 * suas parcelas saem do exigível; a `CalculadoraSaldo` só abate alocações de obrigações EXIGÍVEIS
 * (`AlocacaoPagamentoRepository::totalAlocadoEmObrigacoes`), então o que já foi recebido **para de ser
 * descontado** e a dívida original volta cheia. O dinheiro continua registrado, mas some da conta — e
 * some em silêncio, que é o pior jeito de sumir.
 *
 * Decisão do dono (01/08): o viés de confirmação é a contabilidade, e a contabilidade não cancela
 * acordo com parcela paga — desfaz-se o pagamento primeiro.
 *
 * ⚠️ RESSALVA REGISTRADA NA SPEC (§3.1): **desfazer pagamento ainda não existe** (`CorrigirPagamento`
 * exige `valorPago` positivo e não há rota de exclusão). Enquanto o estorno não for implementado,
 * esta recusa é um beco sem saída para um pagamento lançado por engano. Medido em 01/08: nenhuma
 * parcela de acordo tem pagamento, no dev nem em produção — ninguém está travado hoje.
 *
 * ⚠️ ROMPER não passa por aqui — e a rigor tem o MESMO efeito sobre o dinheiro, porque acordo rompido
 * também deixa de ser vigente. A diferença não é técnica, é de propósito: romper é um fato do mundo
 * (o devedor descumpriu) e BLOQUEAR seria errado, o gestor precisa poder registrar o que aconteceu.
 * A saída certa para os dois casos é realocar o pagamento, e isso depende do estorno que ainda não
 * existe (§3.1). Lacuna registrada, não esquecida.
 */
final class AcordoComParcelaPagaException extends \DomainException
{
    public function __construct(int $acordoId)
    {
        parent::__construct(sprintf(
            'Acordo %d não pode ser cancelado: há pagamento registrado nas parcelas dele. '
            . 'Desfaça o pagamento primeiro, senão o valor recebido deixa de abater a dívida. '
            . '(Se o acordo existiu e foi descumprido, o caso é romper, não cancelar.)',
            $acordoId,
        ));
    }
}
