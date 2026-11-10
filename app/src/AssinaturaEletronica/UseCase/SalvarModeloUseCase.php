<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\UseCase;

use App\AssinaturaEletronica\DTO\DadosDoModelo;
use App\AssinaturaEletronica\Entity\ModeloDoEscritorio;
use App\AssinaturaEletronica\Exception\ModeloNaoEncontrado;
use App\AssinaturaEletronica\Repository\ModeloDoEscritorioRepository;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use Psr\Clock\ClockInterface;

/**
 * "Salvar modelo" do editor (contrato da etapa 4, seção 4): cria (id nulo) ou troca o conteúdo de um modelo do
 * escritório. Quem tem o módulo edita qualquer modelo do escritório (spec 5.4); modelo de outro escritório ou já
 * excluído é `ModeloNaoEncontrado` (404). Os documentos já feitos com o modelo não mudam: guardam os blocos deles.
 */
final readonly class SalvarModeloUseCase
{
    public function __construct(
        private ModeloDoEscritorioRepository $modelos,
        private ClockInterface $relogio,
    ) {
    }

    /**
     * @param int|null $id nulo cria o modelo
     *
     * @return int o id do modelo
     *
     * @throws ModeloNaoEncontrado
     */
    public function executar(User $ator, Tenant $tenant, ?int $id, DadosDoModelo $dados): int
    {
        $agora = \DateTimeImmutable::createFromInterface($this->relogio->now());
        $modelo = $id === null ? new ModeloDoEscritorio($tenant, $ator, $agora) : $this->doEscritorio($id, $tenant);

        $modelo->aplicar($dados, $agora);
        $this->modelos->salvar($modelo);

        return (int) $modelo->getId();
    }

    /** @throws ModeloNaoEncontrado */
    private function doEscritorio(int $id, Tenant $tenant): ModeloDoEscritorio
    {
        return $this->modelos->doEscritorio($id, $tenant) ?? throw new ModeloNaoEncontrado();
    }
}
