<?php

declare(strict_types=1);

namespace App\Conector\UseCase;

use App\Conector\DTO\ChamadaDoConector;
use App\Conector\DTO\ResultadoDaConfirmacao;
use App\Conector\Entity\PropostaDoConector;
use App\Conector\Enum\EstadoDaPropostaDoConector;
use App\Conector\Exception\ErroDaFerramenta;
use App\Conector\Ferramenta\ExecutorDeProposta;
use App\Conector\Repository\PropostaDoConectorRepository;
use App\Conector\Service\GuardaDaAcaoDoConector;
use App\Conector\Service\PropostasDoConector;
use App\Conector\Service\SegredoOpaco;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * A `confirmar_acao` por dentro (`docs/specs/mcp-remoto-oauth.md`, 6.7, itens 3 a 6): a ÚNICA porta pela qual uma
 * proposta do conector vira gravação. Tudo numa transação, com a proposta travada (`SELECT ... FOR UPDATE`), como o
 * `ConfirmarPropostaDeCadastroUseCase` do chat; duas confirmações ao mesmo tempo gravam uma vez.
 *
 * Ordem (todas recusam antes de gravar):
 *  1. a proposta existe no escritório do token, é da mesma pessoa, do mesmo consentimento (logo, do mesmo app de I.A.)
 *     e o consentimento continua ativo; está `pendente` e no prazo. Qualquer falha aqui dá a MESMA mensagem
 *     ({@see NAO_VALE}), sem dizer se a proposta existe. A vencida vira `expirada` (e perde os dados) e isso fica gravado;
 *  2. o resumo mandado é igual ao guardado (depois de {@see PropostasDoConector::normalizarResumo()}) e os dados batem
 *     com o hash;
 *  3. a linha da tabela de aba e chave da ação, sem pasta (chave do Master e módulo), pela
 *     {@see GuardaDaAcaoDoConector}; depois, o {@see ExecutorDeProposta::reconferir()} da ferramenta confere de novo
 *     o resto (pasta, recurso, teto, abas, segredo, estado do item);
 *  4. o executor grava; a proposta vira `confirmada`, guarda só os ids e perde `dados` e `resumo`.
 *
 * Recusa dos passos 1 a 3 confirma a transação (só o vencimento muda algo) e a proposta, quando não venceu, continua
 * pendente: a pessoa pode tentar de novo enquanto ela vale, e cada tentativa reconfere tudo. Exceção vinda do passo 4
 * desfaz TUDO, limpa o EntityManager (nada do que ficou na memória é gravado depois) e segue: a proposta continua
 * pendente.
 *
 * Pessoa, escritório e consentimento vêm só da {@see ChamadaDoConector}. Nada de `dados` ou `resumo` vai para log.
 */
final class ConfirmarPropostaDoConectorUseCase
{
    public const MOTIVO_NAO_VALE = 'proposta_nao_vale';
    public const NAO_VALE = 'Proposta não encontrada, já usada ou vencida. Peça a ação de novo para gerar uma proposta nova.';

    public const MOTIVO_RESUMO_DIFERENTE = 'resumo_diferente';
    public const RESUMO_DIFERENTE = 'O resumo não confere com o da proposta. Mande o resumo exatamente como veio na proposta.';

    /** @param iterable<ExecutorDeProposta> $executores */
    public function __construct(
        private readonly PropostaDoConectorRepository $propostas,
        private readonly GuardaDaAcaoDoConector $guarda,
        private readonly SegredoOpaco $segredos,
        private readonly EntityManagerInterface $em,
        private readonly ClockInterface $relogio,
        #[AutowireIterator(ExecutorDeProposta::TAG)]
        private readonly iterable $executores,
    ) {
    }

    /**
     * @return array{ferramenta: string, acao: string, resultado: ResultadoDaConfirmacao}
     *
     * @throws ErroDaFerramenta
     */
    public function executar(string $propostaId, string $resumo, ChamadaDoConector $chamada): array
    {
        $agora = \DateTimeImmutable::createFromInterface($this->relogio->now());
        $hash = $this->segredos->hashDe($propostaId);
        $conexao = $this->em->getConnection();
        $conexao->beginTransaction();

        try {
            $desfecho = $this->decidir($hash, PropostasDoConector::normalizarResumo($resumo), $chamada, $agora);
            $this->em->flush();
            $conexao->commit();
        } catch (\Throwable $erro) {
            if ($conexao->isTransactionActive()) {
                $conexao->rollBack();
            }
            // O que o executor mexeu na memória não pode ir ao banco num flush depois deste.
            $this->em->clear();

            throw $erro;
        }

        if ($desfecho instanceof ErroDaFerramenta) {
            throw $desfecho;
        }

        return $desfecho;
    }

    /**
     * A recusa volta como valor (não lançada) para a transação confirmar o que ela mudou: o vencimento.
     *
     * @return array{ferramenta: string, acao: string, resultado: ResultadoDaConfirmacao}|ErroDaFerramenta
     */
    private function decidir(string $hash, string $resumo, ChamadaDoConector $chamada, \DateTimeImmutable $agora): array|ErroDaFerramenta
    {
        $proposta = $this->propostas->buscarComTrava($hash, $chamada->contexto->escritorio);
        if ($proposta === null || !self::eDaMesmaConexao($proposta, $chamada) || $proposta->getEstado() !== EstadoDaPropostaDoConector::Pendente) {
            return self::naoVale();
        }
        if ($proposta->venceuEm($agora)) {
            $proposta->expirar($agora);

            return self::naoVale();
        }

        $dados = $proposta->getDados();
        $resumoGuardado = $proposta->getResumo();
        if ($dados === null || $resumoGuardado === null || !hash_equals($proposta->getDadosHash(), PropostasDoConector::hashDosDados($dados))) {
            return self::naoVale();
        }
        if (!hash_equals($resumoGuardado, $resumo)) {
            return new ErroDaFerramenta(self::MOTIVO_RESUMO_DIFERENTE, self::RESUMO_DIFERENTE);
        }

        $ferramenta = $proposta->getFerramenta();
        $acao = $proposta->getAcao();
        $executor = $this->executorDe($ferramenta);
        try {
            $this->guarda->exigir($chamada, $ferramenta, $acao);
            $executor->reconferir($acao, $dados, $chamada);
        } catch (ErroDaFerramenta $recusa) {
            return $recusa;
        }

        $resultado = $executor->executarConfirmada($acao, $dados, $chamada);
        $proposta->confirmar($resultado->idsDoResultado, $agora);

        return ['ferramenta' => $ferramenta, 'acao' => $acao, 'resultado' => $resultado];
    }

    /**
     * Mesma pessoa, mesmo consentimento (o app de I.A. é o dele) e consentimento ativo. O escritório já veio na busca.
     */
    private static function eDaMesmaConexao(PropostaDoConector $proposta, ChamadaDoConector $chamada): bool
    {
        $consentimento = $proposta->getConsentimento();

        return $proposta->getUser()->getId() === $chamada->contexto->pessoa->getId()
            && $consentimento->getId() === $chamada->consentimentoId
            && $consentimento->isAtivo()
            && $proposta->getCliente()->getId() === $consentimento->getCliente()->getId();
    }

    /** @throws \LogicException proposta de ferramenta sem executor: a {@see PropostasDoConector} nunca a deixaria nascer */
    private function executorDe(string $ferramenta): ExecutorDeProposta
    {
        foreach ($this->executores as $executor) {
            if ($executor->ferramenta() === $ferramenta) {
                return $executor;
            }
        }

        throw new \LogicException(sprintf('Ferramenta sem executor de proposta: %s.', $ferramenta));
    }

    private static function naoVale(): ErroDaFerramenta
    {
        return new ErroDaFerramenta(self::MOTIVO_NAO_VALE, self::NAO_VALE);
    }
}
