<?php

declare(strict_types=1);

namespace App\Master\UseCase;

use App\Entity\Auth\User;
use App\Master\Entity\LancamentoDeCusto;
use App\Master\Exception\CustoRecusado;
use App\Master\Repository\LancamentoDeCustoRepository;
use App\Master\Service\ArquivoDeCustosCsv;
use App\Master\Service\RotulosDeCusto;
use App\Shared\Audit\EscopoDeAuditoriaDePlataforma;
use Psr\Clock\ClockInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Master importa custos de um CSV (desenho `csImport`, spec master-custos D7): tudo entra como `importado`, numa só
 * transação, auditado sem conta. Devolve quantos entraram e quantas linhas foram ignoradas; arquivo sem linha válida é
 * recusado.
 */
final class ImportarCustosUseCase
{
    public function __construct(
        private readonly LancamentoDeCustoRepository $lancamentos,
        private readonly ClockInterface $clock,
        private readonly EscopoDeAuditoriaDePlataforma $escopoDePlataforma,
        private readonly TranslatorInterface $tradutor,
    ) {
    }

    /** @return array{importados: int, ignoradas: int} */
    public function executar(string $conteudo, string $mesPadrao, ?User $ator): array
    {
        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $mesPadrao) !== 1) {
            throw new CustoRecusado('Escolha o mês.');
        }
        $lido = ArquivoDeCustosCsv::ler($conteudo, $mesPadrao, RotulosDeCusto::categorias($this->tradutor));
        if ($lido['linhas'] === []) {
            throw new CustoRecusado('Nenhuma linha válida. Use as colunas: Mês (AAAA-MM); Categoria; Fornecedor; Valor.');
        }

        $agora = \DateTimeImmutable::createFromInterface($this->clock->now());
        $novos = array_map(static fn (array $l): LancamentoDeCusto => new LancamentoDeCusto(
            $l['mes'],
            $l['categoria'],
            $l['valorCentavos'],
            $l['origem'],
            $l['fornecedor'],
            $l['observacao'],
            $agora,
            $ator,
        ), $lido['linhas']);
        $this->escopoDePlataforma->executar(fn () => $this->lancamentos->salvarVarios($novos));

        return ['importados' => count($novos), 'ignoradas' => $lido['ignoradas']];
    }
}
