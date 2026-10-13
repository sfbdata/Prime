<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaPagamento;
use App\Pasta\UseCase\CorrigirValorDoPagamentoUseCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * SEC-006 — dois "corrigir valor" simultâneos. Sob o DAMA não há segunda conexão que
 * enxergue a fixture, então a corrida é provada em duas partes:
 *
 * 1. a SEQUÊNCIA que o banco recebeu: a leitura que decide sai com FOR UPDATE, antes do UPDATE;
 * 2. a RELEITURA: o valor muda por fora do UnitOfWork (como faria o commit do outro POST) e a
 *    decisão segue o banco, não a entidade em memória.
 */
#[CoversClass(CorrigirValorDoPagamentoUseCase::class)]
final class CorrigirValorDoPagamentoTravaTest extends KernelTestCase
{
    #[TestDox('O pagamento é relido com FOR UPDATE antes do UPDATE da correção')]
    public function testLeituraTravadaVemAntesDaGravacao(): void
    {
        self::bootKernel();
        [$pagamento, $pasta, $tenant] = $this->cenario('1300.00');

        $coletor = static::getContainer()->get('doctrine.debug_data_holder');
        $coletor->reset();

        self::assertTrue($this->useCase()->executar($pagamento, $pasta, $tenant, '900,00', '1.300,00'));

        $consultas = array_column($coletor->getData()['default'] ?? [], 'sql');
        $leitura   = $this->primeiroIndice($consultas, '/^SELECT\b.*\bFROM\s+pasta_pagamento\b.*\bFOR\s+UPDATE\b/is');
        $gravacao  = $this->primeiroIndice($consultas, '/^UPDATE\s+pasta_pagamento\b/i');

        self::assertNotNull($leitura, 'a decisão lê o pagamento pelo banco, com a linha travada');
        self::assertNotNull($gravacao);
        self::assertLessThan($gravacao, $leitura, 'a trava vem antes da gravação');
        self::assertSame('900.00', $this->valorGravado($pagamento));
    }

    /**
     * O outro POST já gravou 1.450,00; esta entidade foi carregada antes e ainda diz 1.300,00.
     * A tela deste POST mostrava 1.300,00: tem de dar conflito. Antes, a comparação com o getter
     * passava e o 900,00 sobrescrevia a correção do outro.
     */
    #[TestDox('Valor mudou no banco depois do carregamento: conflito com o valor do banco, nada gravado')]
    public function testValorMudadoNoBancoDaConflito(): void
    {
        self::bootKernel();
        [$pagamento, $pasta, $tenant] = $this->cenario('1300.00');
        $this->alterarPorFora($pagamento, '1450.00');
        self::assertSame('1300.00', $pagamento->getValor(), 'premissa: a entidade em memória está velha');

        $capturada = null;
        try {
            $this->useCase()->executar($pagamento, $pasta, $tenant, '900,00', '1.300,00');
        } catch (\UnexpectedValueException $e) {
            $capturada = $e;
        }

        self::assertNotNull($capturada, 'a correção sobre o valor velho tem de ser recusada');
        self::assertSame(
            'O valor foi alterado por outra pessoa (agora R$ 1.450,00). Recarregue e confira antes de corrigir.',
            $capturada->getMessage(),
        );
        self::assertSame('1450.00', $this->valorGravado($pagamento), 'a correção do outro fica');
        self::assertSame('1450.00', $pagamento->getValor(), 'a entidade sai com o valor do banco, para o 409 responder o atual');
        self::assertTrue($this->em()->isOpen(), 'o controller ainda relê o card depois do 409');
    }

    /** O espelho: quem viu o valor que está no banco corrige, mesmo com a entidade velha. */
    #[TestDox('Tela mostrava o valor do banco (entidade velha em memória): a correção é gravada')]
    public function testValorDoBancoNaTelaGrava(): void
    {
        self::bootKernel();
        [$pagamento, $pasta, $tenant] = $this->cenario('1300.00');
        $this->alterarPorFora($pagamento, '1450.00');

        self::assertTrue($this->useCase()->executar($pagamento, $pasta, $tenant, '900,00', '1.450,00'));
        self::assertSame('900.00', $this->valorGravado($pagamento));
    }

    /**
     * Com a entidade velha dizendo 1.300,00 e o banco 1.450,00, corrigir PARA 1.300,00 é uma
     * mudança de verdade. Sem a releitura, o UnitOfWork não veria diferença e o "sucesso" não
     * gravaria nada.
     */
    #[TestDox('Corrigir para o valor que só a memória tinha grava de fato')]
    public function testCorrigirParaOValorVelhoDaMemoriaGrava(): void
    {
        self::bootKernel();
        [$pagamento, $pasta, $tenant] = $this->cenario('1300.00');
        $this->alterarPorFora($pagamento, '1450.00');

        self::assertTrue($this->useCase()->executar($pagamento, $pasta, $tenant, '1.300,00', '1.450,00'));
        self::assertSame('1300.00', $this->valorGravado($pagamento));
    }

    // ------------------------------------------------------------------ helpers

    /** @param list<string> $consultas */
    private function primeiroIndice(array $consultas, string $padrao): ?int
    {
        foreach ($consultas as $i => $sql) {
            if (preg_match($padrao, $sql) === 1) {
                return $i;
            }
        }

        return null;
    }

    /** Como faria o commit do outro POST: direto na conexão, sem passar pelo UnitOfWork. */
    private function alterarPorFora(PastaPagamento $pagamento, string $valor): void
    {
        $this->em()->getConnection()->executeStatement(
            'UPDATE pasta_pagamento SET valor = ? WHERE id = ?',
            [$valor, $pagamento->getId()],
        );
    }

    private function valorGravado(PastaPagamento $pagamento): string
    {
        return (string) $this->em()->getConnection()->fetchOne(
            'SELECT valor FROM pasta_pagamento WHERE id = ?',
            [$pagamento->getId()],
        );
    }

    private function useCase(): CorrigirValorDoPagamentoUseCase
    {
        return static::getContainer()->get(CorrigirValorDoPagamentoUseCase::class);
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    /** @return array{0: PastaPagamento, 1: Pasta, 2: Tenant} */
    private function cenario(string $valor): array
    {
        $em = $this->em();

        $tenant = new Tenant();
        $tenant->setName('Tenant SEC-006 ' . uniqid());
        $em->persist($tenant);

        $pasta = new Pasta();
        $pasta->setNup('TEST-SEC006-' . uniqid());
        $pasta->setTenant($tenant);
        $em->persist($pasta);

        $pagamento = new PastaPagamento();
        $pagamento->setPasta($pasta);
        $pagamento->setTenant($tenant);
        $pagamento->setDescricao('2ª parcela — honorários');
        $pagamento->setValor($valor);
        $pagamento->setVencimento(new \DateTimeImmutable('+20 days'));
        $em->persist($pagamento);
        $em->flush();

        return [$pagamento, $pasta, $tenant];
    }
}
