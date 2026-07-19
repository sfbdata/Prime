<?php

declare(strict_types=1);

namespace App\Cobranca\UseCase;

use App\Cobranca\DTO\EditarObrigacaoInput;
use App\Cobranca\Entity\Obrigacao;
use App\Cobranca\Enum\TipoEventoHistorico;
use App\Cobranca\Exception\CasoEncerradoException;
use App\Cobranca\Exception\ObrigacaoDeAcordoException;
use App\Cobranca\Exception\ObrigacaoNaoEncontradaException;
use App\Cobranca\Exception\ValorAbaixoDoAlocadoException;
use App\Cobranca\Repository\AlocacaoPagamentoRepository;
use App\Cobranca\Repository\ObrigacaoRepository;
use App\Cobranca\Service\RegistrarEventoHistorico;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;

/**
 * Edita (corrige) uma Obrigação cadastrada errada (ajuste 5) — descrição, valor original, vencimento,
 * referência e encargos reconhecidos, com `motivo` obrigatório.
 *
 * História: o gestor cadastrou/importou uma obrigação com um dado errado e precisa corrigi-la. É uma
 * CORREÇÃO DE CADASTRO auditada, distinta de movimento operacional: o saldo continua DERIVADO
 * (CalculadoraSaldo) — mudar o valor da obrigação ajusta o saldo por derivação, nunca por coluna manual
 * (invariável 20). Guards protegem o histórico financeiro: caso encerrado (SPEC §17), obrigação ligada a
 * acordo (parcela/substituída — gerida pelo acordo), e valor exigível que cairia abaixo do já pago.
 * O único registro é o evento ObrigacaoEditada (antes/depois no payload) + a auditoria técnica da entidade.
 */
final class EditarObrigacaoUseCase
{
    public function __construct(
        private readonly ObrigacaoRepository $obrigacaoRepository,
        private readonly AlocacaoPagamentoRepository $alocacaoRepository,
        private readonly RegistrarEventoHistorico $registrarEvento,
    ) {
    }

    public function executar(EditarObrigacaoInput $input, Tenant $tenant, User $usuario): Obrigacao
    {
        // Guarda multi-tenant: só uma obrigação do próprio escritório pode ser editada.
        $obrigacao = $this->obrigacaoRepository->findOneByIdDoTenant((int) $input->obrigacaoId, $tenant);

        if ($obrigacao === null) {
            throw new ObrigacaoNaoEncontradaException((int) $input->obrigacaoId);
        }

        $caso = $obrigacao->getCaso();

        // Caso encerrado não aceita novos lançamentos/movimentos (SPEC §17): fim do ciclo do Caso.
        if ($caso !== null && $caso->estaEncerrado()) {
            throw new CasoEncerradoException((int) $caso->getId());
        }

        // Obrigação travada por acordo VIGENTE (parcela ativa ou substituída por acordo ativo/cumprido) é
        // gerida PELO acordo — nunca por fora (invariáveis 14/15; parcelas → item 7). Acordo rompido/
        // cancelado NÃO trava: a original voltou ao saldo e a parcela virou histórico, ambas editáveis.
        if ($obrigacao->participaDeAcordoVigente()) {
            throw new ObrigacaoDeAcordoException((int) $obrigacao->getId());
        }

        // O novo valor exigível não pode cair abaixo do que já foi pago/alocado nesta obrigação.
        //
        // Encargos separados (F4): o input traz os TRÊS componentes; a soma deles com o valor original
        // é exatamente o `valorExigivel()` do estado NOVO (INV-E1), ao centavo. Honorários ficam de
        // fora de propósito (INV-E2) — não são dívida do credor. Chamar `$obrigacao->valorExigivel()`
        // aqui não serve: o guard roda ANTES de mutar a entidade — mutar e só depois lançar deixaria a
        // obrigação suja em memória, à mercê de um flush alheio na mesma request.
        $novoExigivel = (int) $input->valorOriginal + $input->juros + $input->multa + $input->correcao;
        $totalAlocado = $this->alocacaoRepository->totalAlocadoEmObrigacoes([(int) $obrigacao->getId()], $tenant);
        if ($novoExigivel < $totalAlocado) {
            throw new ValorAbaixoDoAlocadoException((int) $obrigacao->getId(), $novoExigivel, $totalAlocado);
        }

        // Snapshot ANTES para o histórico/auditoria (o depois é o input já normalizado).
        $antes = $this->snapshot($obrigacao);

        $descricao = trim((string) $input->descricao);
        $referencia = $this->normalizar($input->referenciaExterna);
        $obrigacao->setDescricao($descricao);
        $obrigacao->setValorOriginal((int) $input->valorOriginal);
        $obrigacao->setVencimentoOriginal($input->vencimentoOriginal);
        $obrigacao->setReferenciaExterna($referencia);

        // Encargos só são tocados quando REALMENTE mudaram, e é a mudança que congela.
        //
        // Este form edita descrição, valor, vencimento, referência e encargos de uma vez, e reenvia
        // todos os campos sempre. Mexer nos encargos incondicionalmente tiraria a obrigação do cron
        // PARA SEMPRE por causa de um typo corrigido na descrição — não há UI de descongelar
        // (`descongelarEncargos()` não tem chamador em app/src). A spec §8 condiciona o congelamento
        // a editar VALORES/CONFIG à mão, não a qualquer edição.
        //
        // A comparação é COMPONENTE A COMPONENTE, não pelo agregado: trocar R$ 10 de multa por R$ 10
        // de juros mantém o agregado igual e mudou dinheiro de categoria — tem de congelar.
        $mudou = $input->juros !== $obrigacao->getJuros()
            || $input->multa !== $obrigacao->getMulta()
            || $input->correcao !== $obrigacao->getCorrecao();

        if ($mudou) {
            $agora = new \DateTimeImmutable();

            // F4: aqui morre o último uso da ponte deprecada `setEncargosReconhecidos()` no caminho de
            // edição — era o achado I5 da revisão da F2: ela jogava o agregado inteiro em `juros` e
            // ZERAVA multa/correção, destruindo o split que esta feature existe para preservar. Com
            // `definirEncargos()` cada componente vai para o seu campo. Os HONORÁRIOS são repassados
            // como estão: a UI não os edita e o motor de cálculo é quem os materializa — passar 0 aqui
            // apagaria a dívida de honorários a cada correção de digitação.
            $obrigacao->definirEncargos($input->juros, $input->multa, $input->correcao, $obrigacao->getHonorarios(), $agora);

            // Editar encargos à mão é decisão de gente sobre dinheiro: a partir daqui o cron da F3
            // (`app:cobranca:atualizar-encargos`) não recalcula mais esta obrigação (INV-E4). Sem
            // isto o robô desfaria a correção manual na madrugada seguinte.
            $obrigacao->congelarEncargos($agora);
        }

        $motivo = trim((string) $input->motivo);

        // A obrigação é managed: o flush do evento commita, na mesma transação, a alteração + o evento.
        $this->registrarEvento->registrar(
            $caso,
            TipoEventoHistorico::ObrigacaoEditada,
            $usuario,
            sprintf('Obrigação corrigida: %s', $motivo),
            [
                'obrigacaoId' => $obrigacao->getId(),
                'antes' => $antes,
                'depois' => $this->snapshot($obrigacao),
                'motivo' => $motivo,
            ],
            flush: true,
        );

        return $obrigacao;
    }

    /**
     * @return array{descricao: string, valorOriginal: int, vencimentoOriginal: string, encargosReconhecidos: int, juros: int, multa: int, correcao: int, referenciaExterna: ?string, encargosCongeladosEm: ?string}
     */
    private function snapshot(Obrigacao $obrigacao): array
    {
        return [
            'descricao' => $obrigacao->getDescricao(),
            'valorOriginal' => $obrigacao->getValorOriginal(),
            'vencimentoOriginal' => $obrigacao->getVencimentoOriginal()->format('Y-m-d'),
            // O agregado continua no snapshot (derivado, INV-E1) para não quebrar a leitura do
            // histórico ANTIGO, que só conhece esta chave; os três componentes entram ao lado —
            // sem eles o histórico não explica QUAL encargo o gestor mexeu.
            'encargosReconhecidos' => $obrigacao->getEncargosReconhecidos(),
            'juros' => $obrigacao->getJuros(),
            'multa' => $obrigacao->getMulta(),
            'correcao' => $obrigacao->getCorrecao(),
            'referenciaExterna' => $obrigacao->getReferenciaExterna(),
            // O histórico tem de registrar o congelamento: é ele que explica por que aquela
            // obrigação parou de crescer a partir desta edição (antes null → depois preenchido).
            'encargosCongeladosEm' => $obrigacao->getEncargosCongeladosEm()?->format('Y-m-d H:i:s'),
        ];
    }

    private function normalizar(?string $valor): ?string
    {
        $valor = trim((string) $valor);

        return $valor === '' ? null : $valor;
    }
}
