<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\DTO\ParcelamentoDaPastaInput;
use App\Pasta\DTO\ParcelamentoRegistradoOutput;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaPagamento;
use App\Pasta\Entity\TipoDePagamento;
use App\Pasta\Service\BaseDoPercentual;
use App\Pasta\Service\CalculadoraDeParcelamento;
use App\Shared\Service\ValorEmReais;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * "Adicionar pagamento" com entrada, parcelas e juros (desenho 1.2.3, dc
 * L.1928-2012; regra no dc L.3470-3500): honorários contratuais e custas.
 *
 * ÊXITO E SUCUMBÊNCIA (spec pasta-exito-sucumbencia, desenho 02 L.3702-3705): só o
 * percentual. Grava UMA linha prevista, sem vencimento, com a descrição do desenho
 * ("Honorários de êxito (N%)" / "Sucumbência estimada (N%)"); o valor em reais é
 * calculado na leitura sobre o Total contratado (`BaseDoPercentual`). A descrição livre
 * não vale para o previsto: a tela nem mostra o campo nesse tipo.
 *
 * Quem: alguém da equipe com permissão de editar a pasta, ao fechar o contrato
 * com o cliente ("R$ 12.000 em 10 vezes, com entrada de R$ 2.000").
 * Antes era uma linha por vez, com a conta de cabeça.
 *
 * A CONTA É FEITA AQUI. O navegador mostra uma prévia com a mesma regra, mas o
 * POST leva só os campos do formulário: nenhum valor de parcela vem de lá.
 * Regra de arredondamento → `CalculadoraDeParcelamento`.
 *
 * TUDO OU NADA: todos os campos são lidos e todas as parcelas calculadas ANTES
 * do primeiro `persist`; depois há UM `flush`, que o Doctrine executa numa
 * transação só — um erro no meio desfaz as N linhas. A auditoria vem do
 * `AuditLogSubscriber` (`PastaPagamento` é `Auditavel`), no mesmo flush.
 *
 * Reusa as regras do lançamento avulso (`RegistrarPagamentoDaPastaUseCase`):
 * dinheiro lido por `ValorEmReais`, valor > 0, data estrita AAAA-MM-DD que
 * recusa dia inexistente, lançamento nasce PENDENTE — SEM exceção.
 *
 * REGRA FINANCEIRA (decisão do dono, 07/10/2026): "nenhuma operação pode
 * registrar recebimento financeiro que não ocorreu". O desenho (dc L.3471,
 * `entradaPaga: true`) lançava a entrada quitada hoje; aqui ela nasce pendente,
 * com vencimento hoje, como todas as parcelas. O recebimento só acontece pelo
 * gesto explícito "Marcar como recebido" (`AlternarQuitacaoDoPagamentoUseCase`
 * via `pasta_pagamento_alternar_quitacao`).
 *
 * JUROS REGISTRADOS (FPN-009): no parcelamento COM juros, cada PARCELA guarda a
 * taxa ao mês (`juros_mensal`) para a linha mostrar "· juros N% a.m."; a entrada
 * não (desenho dc L.3490: `juros: i > 0 ? taxa : null`, só nas parcelas). É só
 * registro — a conta é a mesma de antes e nenhum valor lê essa coluna.
 */
final class RegistrarParcelamentoDaPastaUseCase
{
    /**
     * Teto de parcelas: o mesmo do campo do desenho (dc L.1966, `min(60, …)`) e
     * da spec. O servidor recusa acima disso — também é a trava contra POST
     * forjado que gere centenas de linhas.
     */
    public const MAX_PARCELAS = 60;

    /**
     * Descrição livre opcional (desvio consciente do desenho, que não tem o
     * campo — ver o comentário do modal). 110 e não os 120 da coluna: sobra
     * espaço para o sufixo " · 60/60" ou " · entrada".
     */
    public const MAX_DESCRICAO = 110;

    /**
     * Teto da taxa de juros, em % ao mês. 10% a.m. (≈ 214% a.a.) já está muito
     * acima de qualquer honorário parcelado razoável; acima disso é erro de
     * digitação (12 no lugar de 1,2) e não um contrato.
     */
    public const TAXA_MAXIMA_MENSAL = '10';

    private const ROTULOS = [
        ParcelamentoDaPastaInput::TIPO_CONTRATO => 'honorários',
        ParcelamentoDaPastaInput::TIPO_CUSTAS   => 'custas',
        ParcelamentoDaPastaInput::TIPO_RECORRENTE => 'mensalidade',
    ];

    private const DESCRICAO_UNICA = [
        ParcelamentoDaPastaInput::TIPO_CONTRATO => 'Honorários contratuais',
        ParcelamentoDaPastaInput::TIPO_CUSTAS   => 'Custas e despesas',
        ParcelamentoDaPastaInput::TIPO_RECORRENTE => 'Mensalidade 1/1 · honorários recorrentes',
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CalculadoraDeParcelamento $calculadora,
        private readonly ClockInterface $clock,
        private readonly BaseDoPercentual $baseDoPercentual,
    ) {}

    /**
     * @throws \InvalidArgumentException quando algum campo não serve (a tela mostra a mensagem; nada é gravado)
     * @throws \DomainException           quando a pasta não é do escritório informado
     */
    public function executar(Pasta $pasta, User $autor, Tenant $tenant, ParcelamentoDaPastaInput $input): ParcelamentoRegistradoOutput
    {
        // O controller já resolve a pasta pelo TenantFilter; esta é a segunda
        // trava, para quem chamar o UseCase por outro caminho.
        if (!self::mesmoTenant($pasta->getTenant(), $tenant)) {
            throw new \DomainException('Pasta não encontrada.');
        }

        $tipo = $input->tipo;
        if (TipoDePagamento::tryFrom($tipo)?->ehCondicionado() === true) {
            return $this->registrarPrevisto($pasta, $autor, $tenant, TipoDePagamento::from($tipo), $input->percentual);
        }

        if (!isset(self::ROTULOS[$tipo])) {
            throw new \InvalidArgumentException('Escolha o tipo do pagamento.');
        }

        // Recorrente (dc 27.10): o valor digitado é o MENSAL; o total é mensal × meses, sem entrada nem juros.
        $recorrente    = $tipo === ParcelamentoDaPastaInput::TIPO_RECORRENTE;
        $quantidade    = $this->lerQuantidade($input->parcelas);
        $totalCentavos = $this->lerTotal($pasta, $input) * ($recorrente ? $quantidade : 1);

        // Entrada maior que o total vira o total, como no desenho (`Math.min`):
        // a prévia já mostra a entrada cheia e nenhuma parcela.
        $entradaCentavos = $recorrente ? 0 : min($this->lerEntrada($input->entrada), $totalCentavos);
        $primeiro        = $this->lerData($input->primeiroVencimento);
        $comJuros        = $input->comJuros && !$recorrente;
        $taxa            = $comJuros ? $this->lerTaxa($input->taxaMensal) : '0';
        // Só para REGISTRAR na parcela (FPN-009); a conta acima segue com `$taxa`.
        $jurosDaParcela  = $comJuros ? $taxa : null;

        $principal = $totalCentavos - $entradaCentavos;
        $valores   = $principal > 0 ? $this->calculadora->parcelas($principal, $quantidade, $taxa) : [];
        $datas     = $principal > 0 ? $this->calculadora->vencimentos($primeiro, $quantidade) : [];

        $descricaoLivre = $this->lerDescricao($input->descricao);

        $rotulo    = self::ROTULOS[$tipo];
        $hoje      = $this->clock->now()->setTime(0, 0);
        $lancados  = [];

        if ($entradaCentavos > 0) {
            // Vence hoje e nasce PENDENTE: quem recebeu marca como recebido.
            $lancados[] = $this->novo(
                $pasta,
                $autor,
                $tenant,
                $descricaoLivre !== null ? $descricaoLivre . ' · entrada' : 'Entrada · ' . $rotulo,
                $entradaCentavos,
                $hoje,
            );
        }

        foreach ($valores as $k => $centavos) {
            $descricao = $this->descricaoDaParcela($tipo, $descricaoLivre, $k, $quantidade, $rotulo);
            $lancados[] = $this->novo($pasta, $autor, $tenant, $descricao, $centavos, $datas[$k], $jurosDaParcela);
        }

        foreach ($lancados as $pagamento) {
            $pagamento->setTipo(TipoDePagamento::from($tipo));
        }

        return $this->gravar($lancados);
    }

    private function descricaoDaParcela(string $tipo, ?string $descricaoLivre, int $k, int $quantidade, string $rotulo): string
    {
        return match (true) {
            // A descrição digitada vai em TODAS as parcelas; com mais de uma,
            // cada linha leva " · k/N" para continuar distinguível na lista.
            $descricaoLivre !== null && $quantidade === 1 => $descricaoLivre,
            $descricaoLivre !== null                      => sprintf('%s · %d/%d', $descricaoLivre, $k + 1, $quantidade),
            $tipo === ParcelamentoDaPastaInput::TIPO_RECORRENTE => sprintf('Mensalidade %d/%d · honorários recorrentes', $k + 1, $quantidade),
            $quantidade === 1                             => self::DESCRICAO_UNICA[$tipo],
            default                                       => sprintf('%dª parcela · %s', $k + 1, $rotulo),
        };
    }

    /**
     * Êxito ou sucumbência: uma linha, percentual entre 0 e 100, sem vencimento. O valor gravado
     * é só o retrato do cálculo de agora; quem lê calcula de novo (decisão 1 do dono).
     */
    private function registrarPrevisto(Pasta $pasta, User $autor, Tenant $tenant, TipoDePagamento $tipo, string $texto): ParcelamentoRegistradoOutput
    {
        // Duas casas, como a coluna (`NUMERIC(5,2)`): "12,345" seria gravado como outro número.
        $lido       = self::lerDecimal($texto) ?? '0';
        $percentual = bcadd($lido, '0', 2);
        if (bccomp($lido, $percentual, 4) !== 0 || bccomp($percentual, '0', 2) <= 0 || bccomp($percentual, '100', 2) > 0) {
            throw new \InvalidArgumentException('Informe um percentual entre 0 e 100.');
        }

        $rotulo    = rtrim(rtrim($percentual, '0'), '.');
        $pagamento = $this->novo($pasta, $autor, $tenant, sprintf(
            $tipo === TipoDePagamento::Exito ? 'Honorários de êxito (%s%%)' : 'Sucumbência estimada (%s%%)',
            str_replace('.', ',', $rotulo),
        ), 0, null);
        $retrato = $this->calculadora->percentualDe($this->baseDoPercentual->daPasta($pasta), $percentual);
        $pagamento->preverPorPercentual($tipo, $percentual, ValorEmReais::deCentavos($retrato));

        return $this->gravar([$pagamento]);
    }

    /**
     * Um persist por linha e UM flush: o Doctrine grava tudo numa transação só.
     *
     * @param list<PastaPagamento> $lancados
     */
    private function gravar(array $lancados): ParcelamentoRegistradoOutput
    {
        foreach ($lancados as $pagamento) {
            $this->em->persist($pagamento);
        }

        $this->em->flush();

        $soma = 0;
        $ids  = [];
        foreach ($lancados as $pagamento) {
            $soma += ValorEmReais::paraCentavos($pagamento->getValor());
            if ($pagamento->getId() !== null) {
                $ids[] = $pagamento->getId();
            }
        }

        return new ParcelamentoRegistradoOutput($ids, count($lancados), ValorEmReais::deCentavos($soma));
    }

    private function novo(Pasta $pasta, User $autor, Tenant $tenant, string $descricao, int $centavos, ?\DateTimeImmutable $vencimento, ?string $jurosMensal = null): PastaPagamento
    {
        $pagamento = new PastaPagamento();
        $pagamento->setPasta($pasta);
        $pagamento->setTenant($tenant);
        $pagamento->setAutor($autor);
        $pagamento->setDescricao($descricao);
        $pagamento->setValor(ValorEmReais::deCentavos($centavos));
        $pagamento->setVencimento($vencimento);
        $pagamento->setJurosMensal($jurosMensal);

        return $pagamento;
    }

    /** Total em centavos: o valor digitado, ou o percentual sobre o valor da causa da pasta. */
    private function lerTotal(Pasta $pasta, ParcelamentoDaPastaInput $input): int
    {
        if ($input->base === ParcelamentoDaPastaInput::BASE_PERCENTUAL) {
            $percentual = self::lerDecimal($input->percentual);
            if ($percentual === null || bccomp($percentual, '0', 4) <= 0 || bccomp($percentual, '100', 4) > 0) {
                throw new \InvalidArgumentException('Informe um percentual entre 0 e 100.');
            }

            // O valor da causa é o do BANCO, não o que a tela mostrava: a conta
            // da prévia pode ter partido de um número que outra pessoa já mudou.
            $causa = ValorEmReais::paraCentavos($pasta->getValorCausa());
            if ($causa <= 0) {
                throw new \InvalidArgumentException('Informe o valor da causa antes de calcular por percentual.');
            }

            $total = $this->calculadora->percentualDe($causa, $percentual);
            if ($total <= 0) {
                throw new \InvalidArgumentException('Informe o valor total.');
            }

            return $total;
        }

        if ($input->base !== ParcelamentoDaPastaInput::BASE_VALOR) {
            throw new \InvalidArgumentException('Escolha a base do valor.');
        }

        $decimal = ValorEmReais::normalizar($input->valorTotal, 'valor total');
        if ($decimal === null || ValorEmReais::paraCentavos($decimal) <= 0) {
            throw new \InvalidArgumentException('Informe o valor total.');
        }

        return ValorEmReais::paraCentavos($decimal);
    }

    private function lerEntrada(string $entrada): int
    {
        // Em branco é "sem entrada"; R$ 0,00 também.
        return ValorEmReais::paraCentavos(ValorEmReais::normalizar($entrada, 'valor da entrada'));
    }

    private function lerQuantidade(string $texto): int
    {
        $texto = trim($texto);
        if (preg_match('/^\d{1,4}$/', $texto) !== 1) {
            throw new \InvalidArgumentException('Informe o número de parcelas.');
        }

        $quantidade = (int) $texto;
        if ($quantidade < 1 || $quantidade > self::MAX_PARCELAS) {
            throw new \InvalidArgumentException(sprintf('O número de parcelas vai de 1 a %d.', self::MAX_PARCELAS));
        }

        return $quantidade;
    }

    /** Em branco = usa as descrições do desenho. */
    private function lerDescricao(string $texto): ?string
    {
        $texto = trim((string) preg_replace('/\s+/u', ' ', $texto));
        if ($texto === '') {
            return null;
        }

        if (mb_strlen($texto) > self::MAX_DESCRICAO) {
            throw new \InvalidArgumentException(
                sprintf('A descrição pode ter no máximo %d caracteres.', self::MAX_DESCRICAO)
            );
        }

        return $texto;
    }

    /** Juros ao mês, em percentual decimal com ponto ("1.5"). */
    private function lerTaxa(string $texto): string
    {
        $taxa = self::lerDecimal($texto);
        if ($taxa === null || bccomp($taxa, '0', 4) <= 0 || bccomp($taxa, self::TAXA_MAXIMA_MENSAL, 4) > 0) {
            throw new \InvalidArgumentException(sprintf(
                'Informe os juros ao mês entre 0 e %s%%.',
                str_replace('.', ',', self::TAXA_MAXIMA_MENSAL),
            ));
        }

        return $taxa;
    }

    /**
     * Número decimal curto como "20", "1,5", "0.99" (até 3 dígitos inteiros e 4
     * casas). Devolve com ponto, pronto para o bcmath; nulo quando não serve.
     */
    private static function lerDecimal(string $texto): ?string
    {
        $texto = str_replace([' ', '%', "\u{00A0}"], '', trim($texto));
        if (preg_match('/^(\d{1,3})(?:[.,](\d{1,4}))?$/', $texto, $m) !== 1) {
            return null;
        }

        return $m[1] . '.' . str_pad($m[2] ?? '', 4, '0');
    }

    /**
     * Mesma leitura estrita do lançamento avulso: `!` zera a hora, e a volta ao
     * texto recusa dia inexistente (`2026-02-31` rolaria para março em silêncio).
     */
    private function lerData(string $entrada): \DateTimeImmutable
    {
        $entrada = trim($entrada);
        $data    = \DateTimeImmutable::createFromFormat('!Y-m-d', $entrada);

        if ($data === false || $data->format('Y-m-d') !== $entrada) {
            throw new \InvalidArgumentException('Informe o 1º vencimento.');
        }

        return $data;
    }

    private static function mesmoTenant(?Tenant $a, Tenant $b): bool
    {
        if ($a === null) {
            return false;
        }

        if ($a === $b) {
            return true;
        }

        return $a->getId() !== null && $a->getId() === $b->getId();
    }
}
