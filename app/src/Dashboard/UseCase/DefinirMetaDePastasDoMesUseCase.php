<?php

declare(strict_types=1);

namespace App\Dashboard\UseCase;

use App\Dashboard\DTO\DefinirMetaDePastasInput;
use App\Dashboard\DTO\MetaDePastasGravadaOutput;
use App\Dashboard\Entity\DashboardMetaPastas;
use App\Dashboard\Exception\MetaDePastasInvalidaException;
use App\Dashboard\Exception\MetaDePastasNaoPermitidaException;
use App\Dashboard\Repository\DashboardMetaPastasRepository;
use App\Tenant\Service\RegraDosPrimarios;

/**
 * Define a meta de pastas do mês (DASH-016; desenho "01 - Dashboard - VS 25.10.26", `salvarMeta`).
 *
 * Grava uma linha nova em `mes` = dia 1º do mês corrente (o histórico fica, nada se edita): a meta vale
 * desse mês em diante, até alguém trocá-la. No mesmo mês vale a última gravada. Meta que alguém já tinha
 * deixado para meses à frente não existe (o relógio só anda para frente), então não há o que apagar.
 *
 * Só quem gere o escritório define: a mesma régua do "Zerar" (RegraDosPrimarios::podeGerirPrimarios);
 * os outros recebem MetaDePastasNaoPermitidaException (403). Valor: inteiro de 1 a 9999 (o desenho aceita
 * até 5 dígitos; 9999 é o teto razoável de pastas por mês). Para encerrar a meta, RemoverMetaDePastasDoMesUseCase.
 */
final class DefinirMetaDePastasDoMesUseCase
{
    public const VALOR_MAXIMO = 9999;

    public function __construct(
        private readonly DashboardMetaPastasRepository $repository,
        private readonly RegraDosPrimarios $regraDosPrimarios,
    ) {
    }

    public function executar(DefinirMetaDePastasInput $input): MetaDePastasGravadaOutput
    {
        if (!$this->regraDosPrimarios->podeGerirPrimarios($input->autor, $input->tenant)) {
            throw new MetaDePastasNaoPermitidaException('Só o usuário primário pode definir a meta de pastas.');
        }
        if ($input->valor < 1 || $input->valor > self::VALOR_MAXIMO) {
            throw new MetaDePastasInvalidaException(sprintf('A meta de pastas deve ser um número de 1 a %d.', self::VALOR_MAXIMO));
        }

        $meta = new DashboardMetaPastas($input->tenant, $input->agora, $input->valor, $input->autor, $input->agora);
        $this->repository->salvar($meta, flush: true);

        return MetaDePastasGravadaOutput::fromEntity($meta);
    }
}
