<?php

declare(strict_types=1);

namespace App\Tests\Conector\Support;

use App\Conector\DTO\ChamadaDoConector;
use App\Conector\DTO\ResultadoDaConfirmacao;
use App\Conector\Exception\ErroDaFerramenta;
use App\Conector\Ferramenta\ExecutorDeProposta;
use App\Entity\Tenant\Tenant;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Executor de mentira para os testes da `confirmar_acao`: conta as chamadas, recusa ou lança quando mandado e, ao
 * executar, GRAVA um escritório com o nome dado (para provar que a falha desfaz o que ele gravou).
 */
final class ExecutorDePropostaDeTeste implements ExecutorDeProposta
{
    /** @var list<array{acao: string, dados: array<string, mixed>}> */
    public array $reconferidas = [];

    /** @var list<array{acao: string, dados: array<string, mixed>}> */
    public array $executadas = [];

    public ?ErroDaFerramenta $recusaAoReconferir = null;
    public ?\Throwable $falhaAoExecutar = null;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly string $ferramenta = 'atualizar_pasta',
        public string $nomeDoEscritorioGravado = '',
    ) {
    }

    public function ferramenta(): string
    {
        return $this->ferramenta;
    }

    public function reconferir(string $acao, array $dados, ChamadaDoConector $chamada): void
    {
        $this->reconferidas[] = ['acao' => $acao, 'dados' => $dados];
        if ($this->recusaAoReconferir !== null) {
            throw $this->recusaAoReconferir;
        }
    }

    public function executarConfirmada(string $acao, array $dados, ChamadaDoConector $chamada): ResultadoDaConfirmacao
    {
        $this->executadas[] = ['acao' => $acao, 'dados' => $dados];
        $gravado = (new Tenant())->setName($this->nomeDoEscritorioGravado !== '' ? $this->nomeDoEscritorioGravado : 'Gravado ' . uniqid())->setIsActive(true);
        $this->em->persist($gravado);
        $this->em->flush();
        if ($this->falhaAoExecutar !== null) {
            throw $this->falhaAoExecutar;
        }

        return new ResultadoDaConfirmacao(
            ['excluidos' => [['id' => $gravado->getId()]]],
            [(int) $gravado->getId()],
            ['excluidos' => 1],
            ['pasta' => [(int) $gravado->getId()]],
        );
    }
}
