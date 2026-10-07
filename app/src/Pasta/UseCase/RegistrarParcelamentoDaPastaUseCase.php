<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\DTO\ParcelamentoDaPastaInput;
use App\Pasta\DTO\ParcelamentoRegistradoOutput;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaPagamento;
use App\Pasta\Service\CalculadoraDeParcelamento;
use App\Shared\Service\ValorEmReais;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * "Adicionar pagamento" com entrada, parcelas e juros (desenho 1.2.3, dc
 * L.1928-2012; regra no dc L.3470-3500) — fatia 11a: honorários contratuais e
 * custas. Êxito e sucumbência (valor por percentual, sem vencimento) não cabem
 * no `PastaPagamento` de hoje e ficam para a fatia com migration (11b).
 *
 * Quem: alguém da equipe com permissão de editar a pasta, ao fechar o contrato
 * com o cliente ("R$ 12.000 em 10 vezes, com entrada de R$ 2.000 já paga").
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
 * recusa dia inexistente, lançamento nasce PENDENTE. A exceção é a entrada
 * marcada "já recebida hoje" — o desenho a lança quitada na data de hoje, que é
 * de fato o dia em que o dinheiro entrou.
 */
final class RegistrarParcelamentoDaPastaUseCase
{
    /**
     * Teto de parcelas aceito pelo servidor. O campo do desenho vai até 60
     * (dc L.1966); o servidor aceita até 120 (10 anos) como trava contra POST
     * forjado que gere milhares de linhas, sem recusar um parcelamento longo real.
     */
    public const MAX_PARCELAS = 120;

    /**
     * Teto da taxa de juros, em % ao mês. 10% a.m. (≈ 214% a.a.) já está muito
     * acima de qualquer honorário parcelado razoável; acima disso é erro de
     * digitação (12 no lugar de 1,2) e não um contrato.
     */
    public const TAXA_MAXIMA_MENSAL = '10';

    private const ROTULOS = [
        ParcelamentoDaPastaInput::TIPO_CONTRATO => 'honorários',
        ParcelamentoDaPastaInput::TIPO_CUSTAS   => 'custas',
    ];

    private const DESCRICAO_UNICA = [
        ParcelamentoDaPastaInput::TIPO_CONTRATO => 'Honorários contratuais',
        ParcelamentoDaPastaInput::TIPO_CUSTAS   => 'Custas e despesas',
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CalculadoraDeParcelamento $calculadora,
        private readonly ClockInterface $clock,
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
        if (!isset(self::ROTULOS[$tipo])) {
            throw new \InvalidArgumentException(
                in_array($tipo, ['exito', 'sucumbencia'], true)
                    ? 'Êxito e sucumbência ainda não podem ser lançados por aqui.'
                    : 'Escolha o tipo do pagamento.'
            );
        }

        $totalCentavos = $this->lerTotal($pasta, $input);

        // Entrada maior que o total vira o total, como no desenho (`Math.min`):
        // a prévia já mostra a entrada cheia e nenhuma parcela.
        $entradaCentavos = min($this->lerEntrada($input->entrada), $totalCentavos);
        $quantidade      = $this->lerQuantidade($input->parcelas);
        $primeiro        = $this->lerData($input->primeiroVencimento);
        $taxa            = $input->comJuros ? $this->lerTaxa($input->taxaMensal) : '0';

        $principal = $totalCentavos - $entradaCentavos;
        $valores   = $principal > 0 ? $this->calculadora->parcelas($principal, $quantidade, $taxa) : [];
        $datas     = $principal > 0 ? $this->calculadora->vencimentos($primeiro, $quantidade) : [];

        $rotulo    = self::ROTULOS[$tipo];
        $hoje      = $this->clock->now()->setTime(0, 0);
        $lancados  = [];

        if ($entradaCentavos > 0) {
            $entrada = $this->novo($pasta, $autor, $tenant, 'Entrada · ' . $rotulo, $entradaCentavos, $hoje);
            if ($input->entradaPaga) {
                $entrada->alternarQuitacao($hoje);
            }
            $lancados[] = $entrada;
        }

        foreach ($valores as $k => $centavos) {
            $descricao  = $quantidade === 1 ? self::DESCRICAO_UNICA[$tipo] : sprintf('%dª parcela · %s', $k + 1, $rotulo);
            $lancados[] = $this->novo($pasta, $autor, $tenant, $descricao, $centavos, $datas[$k]);
        }

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

    private function novo(Pasta $pasta, User $autor, Tenant $tenant, string $descricao, int $centavos, \DateTimeImmutable $vencimento): PastaPagamento
    {
        $pagamento = new PastaPagamento();
        $pagamento->setPasta($pasta);
        $pagamento->setTenant($tenant);
        $pagamento->setAutor($autor);
        $pagamento->setDescricao($descricao);
        $pagamento->setValor(ValorEmReais::deCentavos($centavos));
        $pagamento->setVencimento($vencimento);

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
