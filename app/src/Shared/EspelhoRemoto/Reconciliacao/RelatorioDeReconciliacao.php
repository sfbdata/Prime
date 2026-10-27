<?php

declare(strict_types=1);

namespace App\Shared\EspelhoRemoto\Reconciliacao;

/**
 * O resultado de uma reconciliação: contagem por classe e, para o que não está `igual`, a FONTE e o
 * ID da linha do banco — nunca o nome do arquivo nem a chave remota (LGPD).
 */
final class RelatorioDeReconciliacao
{
    /** @var array<string, int> */
    private array $contagem = [];

    /** @var array<string, array<string, int>> classe => categoria => quantidade */
    private array $porCategoria = [];

    /** @var list<array{fonte: string, id: int, classe: string}> */
    private array $divergencias = [];

    public const SEM_CATEGORIA = 'sem_categoria';

    public function registrar(ReferenciaDeArquivo $referencia, ClasseDeReconciliacao $classe): void
    {
        $this->contagem[$classe->value] = ($this->contagem[$classe->value] ?? 0) + 1;

        $categoria = $referencia->categoria()->value ?? self::SEM_CATEGORIA;
        $this->porCategoria[$classe->value][$categoria] = ($this->porCategoria[$classe->value][$categoria] ?? 0) + 1;

        if ($classe !== ClasseDeReconciliacao::IGUAL) {
            $this->divergencias[] = ['fonte' => $referencia->fonte, 'id' => $referencia->idDaLinha, 'classe' => $classe->value];
        }
    }

    public function quantos(ClasseDeReconciliacao $classe): int
    {
        return $this->contagem[$classe->value] ?? 0;
    }

    /**
     * Quantas referências de uma classe, por categoria (o valor de {@see \App\Shared\Armazenamento\CategoriaDeArquivo},
     * nunca nome de arquivo).
     *
     * @return array<string, int> categoria => quantidade
     */
    public function porCategoria(ClasseDeReconciliacao $classe): array
    {
        $contagem = $this->porCategoria[$classe->value] ?? [];
        ksort($contagem);

        return $contagem;
    }

    public function total(): int
    {
        return array_sum($this->contagem);
    }

    /** @return array<string, int> */
    public function contagem(): array
    {
        ksort($this->contagem);

        return $this->contagem;
    }

    /** @return list<array{fonte: string, id: int, classe: string}> */
    public function divergencias(): array
    {
        return $this->divergencias;
    }
}
