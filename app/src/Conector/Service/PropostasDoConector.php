<?php

declare(strict_types=1);

namespace App\Conector\Service;

use App\Conector\DTO\ChamadaDoConector;
use App\Conector\DTO\RespostaDaFerramenta;
use App\Conector\Entity\ConsentimentoDoConector;
use App\Conector\Entity\PropostaDoConector;
use App\Conector\Enum\TipoDeFerramenta;
use App\Conector\Ferramenta\CatalogoFechadoDoConector;
use App\Conector\Ferramenta\ConfirmarAcaoFerramenta;
use App\Conector\Ferramenta\ExecutorDeProposta;
use App\Conector\Ferramenta\TabelaDeAbaEChave;
use App\Conector\Repository\ConsentimentoDoConectorRepository;
use App\Conector\Repository\PropostaDoConectorRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * A porta única por onde uma ferramenta de gravação deixa uma ação esperando o "sim" da pessoa
 * (`docs/specs/mcp-remoto-oauth.md`, 6.7, item 1). A ferramenta já conferiu tudo (permissão, teto, aba, chave, segredo,
 * estado do item) e chama {@see propor()} em vez de gravar; nada do que ela pediu acontece aqui.
 *
 * Grava a {@see PropostaDoConector} presa à pessoa, ao escritório, ao consentimento e ao app de I.A. do token, com os
 * dados exatos, o hash deles ({@see hashDosDados()}), o resumo, 10 minutos de validade e uso único. O id é aleatório
 * ({@see SegredoOpaco}) e só o hash dele fica no banco; o valor sai uma vez, na resposta. Antes de gravar, varre as
 * pendentes vencidas do escritório (6.7, item 6).
 *
 * Erro de programação (ferramenta que não é de gravação, ação fora da tabela, ferramenta sem
 * {@see ExecutorDeProposta}, dados ou resumo grandes demais) lança `\LogicException`: proposta que ninguém consegue
 * confirmar nunca nasce. `dados` e `resumo` nunca vão para log nem auditoria.
 */
final class PropostasDoConector
{
    /** O teto do resumo: um lote de 50 clientes com os valores mandados cabe, abaixo dos 50 mil da resposta (6.2, regra 7). */
    public const TETO_DO_RESUMO = 30_000;

    /** O teto dos dados, em bytes do JSON: o lote de 50 itens com folga (6.2, regra 12). */
    public const TETO_DOS_DADOS = 200_000;

    /** A frase da 6.7, item 1, e o lembrete de mandar o resumo exato (6.7, item 3). */
    public const INSTRUCAO = 'Mostre o resumo à pessoa e só chame confirmar_acao se ela disser sim. Na confirmação, mande o proposta_id e o resumo exatamente como estão aqui. A proposta vale 10 minutos e uma vez só.';

    private const JSON = \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PRESERVE_ZERO_FRACTION | \JSON_THROW_ON_ERROR;

    /** @param iterable<ExecutorDeProposta> $executores */
    public function __construct(
        private readonly PropostaDoConectorRepository $propostas,
        private readonly ConsentimentoDoConectorRepository $consentimentos,
        private readonly SegredoOpaco $segredos,
        private readonly EntityManagerInterface $em,
        private readonly ClockInterface $relogio,
        #[AutowireIterator(ExecutorDeProposta::TAG)]
        private readonly iterable $executores,
    ) {
    }

    /**
     * Registra a proposta e devolve a resposta da ferramenta: o id, o resumo, até quando vale e a instrução fixa. O
     * `$complemento` entra na resposta sob `detalhes` (ex.: os itens que já saíram do lote, com o motivo); ele segue as
     * regras de toda resposta e não fica na proposta.
     *
     * @param array<string, mixed> $dados       o que será feito, exatamente; volta igual ao executor
     * @param array<string, mixed> $complemento
     *
     * @throws \LogicException
     */
    public function propor(ChamadaDoConector $chamada, string $ferramenta, string $acao, array $dados, string $resumo, array $complemento = []): RespostaDaFerramenta
    {
        $resumo = $this->exigirPropostaValida($chamada, $ferramenta, $acao, $dados, $resumo);
        $hashDosDados = self::hashDosDados($dados);
        $escritorio = $chamada->contexto->escritorio;
        $consentimento = $this->consentimentoAtivoDa($chamada);

        $agora = \DateTimeImmutable::createFromInterface($this->relogio->now());
        $this->propostas->expirarVencidas($escritorio, $agora);

        $id = $this->segredos->gerar();
        $proposta = new PropostaDoConector($id['hash'], $consentimento, $ferramenta, $acao, $dados, $hashDosDados, $resumo, $agora);
        $this->propostas->salvar($proposta);
        $this->em->flush();

        $resposta = [
            'estado' => 'aguardando_confirmacao',
            'proposta' => [
                'proposta_id' => $id['valor'],
                'resumo' => $resumo,
                'expira_em' => $proposta->getExpiraEm()->format(\DateTimeInterface::ATOM),
            ],
            'instrucao' => self::INSTRUCAO,
            'como_confirmar' => ConfirmarAcaoFerramenta::NOME,
        ];
        if ($complemento !== []) {
            $resposta['detalhes'] = $complemento;
        }

        return new RespostaDaFerramenta($resposta, contagens: ['proposta' => 1]);
    }

    /**
     * Ferramenta e ação que confirmam, o escopo de escrita, o resumo e o teto dos dados. Tudo aqui é erro de programação
     * (a ferramenta e a `ScopePolicy` barram antes), nunca recusa a mostrar.
     *
     * @param array<string, mixed> $dados
     *
     * @return string o resumo normalizado
     *
     * @throws \LogicException
     */
    private function exigirPropostaValida(ChamadaDoConector $chamada, string $ferramenta, string $acao, array $dados, string $resumo): string
    {
        $this->exigirConfirmavel($ferramenta, $acao);
        if (!$chamada->temEscopo(ServidorDeAutorizacao::ESCOPO_ESCRITA)) {
            // A `ScopePolicy` barra a gravação sem `mcp:escrita` antes do SDK (5.1): chegar aqui sem ele é erro.
            throw new \LogicException('Proposta sem o escopo mcp:escrita na chamada.');
        }
        $resumo = self::normalizarResumo($resumo);
        if ($resumo === '' || mb_strlen($resumo) > self::TETO_DO_RESUMO) {
            throw new \LogicException('O resumo da proposta está vazio ou grande demais.');
        }
        if (\strlen(json_encode($dados, self::JSON)) > self::TETO_DOS_DADOS) {
            throw new \LogicException('Os dados da proposta passaram do teto.');
        }

        return $resumo;
    }

    /** @throws \LogicException */
    private function consentimentoAtivoDa(ChamadaDoConector $chamada): ConsentimentoDoConector
    {
        $consentimento = $this->consentimentos->buscarAtivoDaPessoa($chamada->consentimentoId, $chamada->contexto->pessoa);
        if ($consentimento === null || $consentimento->getTenant()?->getId() !== $chamada->contexto->escritorio->getId()) {
            // O portão acabou de aceitar o token deste consentimento: chegar aqui sem ele é erro, nunca recusa a mostrar.
            throw new \LogicException('O consentimento da chamada não está ativo neste escritório.');
        }

        return $consentimento;
    }

    /**
     * O hash que prende os dados à proposta: SHA-256 do JSON com as chaves dos objetos em ordem, para a mesma estrutura
     * dar o mesmo hash depois de ir e voltar do banco.
     *
     * @param array<array-key, mixed> $dados
     */
    public static function hashDosDados(array $dados): string
    {
        return hash('sha256', json_encode(self::emOrdem($dados), self::JSON));
    }

    /**
     * O resumo como é guardado e comparado: quebras de linha `\n` e sem espaço nas pontas. A comparação da
     * `confirmar_acao` é exata depois disso.
     */
    public static function normalizarResumo(string $resumo): string
    {
        return trim(str_replace(["\r\n", "\r"], "\n", $resumo));
    }

    /** @throws \LogicException */
    private function exigirConfirmavel(string $ferramenta, string $acao): void
    {
        if (!CatalogoFechadoDoConector::existe($ferramenta) || CatalogoFechadoDoConector::tipo($ferramenta) !== TipoDeFerramenta::Gravacao) {
            throw new \LogicException(sprintf('Só ferramenta de gravação propõe: %s.', $ferramenta));
        }
        if (TabelaDeAbaEChave::linha($ferramenta, $acao) === null) {
            throw new \LogicException(sprintf('Ação fora da tabela de aba e chave: %s.%s.', $ferramenta, $acao));
        }
        foreach ($this->executores as $executor) {
            if ($executor->ferramenta() === $ferramenta) {
                return;
            }
        }

        throw new \LogicException(sprintf('Ferramenta sem executor de proposta: %s.', $ferramenta));
    }

    /**
     * @param array<array-key, mixed> $valor
     *
     * @return array<array-key, mixed>
     */
    private static function emOrdem(array $valor): array
    {
        if (!array_is_list($valor)) {
            ksort($valor, \SORT_STRING);
        }

        return array_map(static fn (mixed $item): mixed => \is_array($item) ? self::emOrdem($item) : $item, $valor);
    }
}
