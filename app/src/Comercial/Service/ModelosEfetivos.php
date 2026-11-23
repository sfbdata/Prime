<?php

declare(strict_types=1);

namespace App\Comercial\Service;

use App\Comercial\Campanha\ModelosDeCampanha;
use App\Comercial\DTO\ModeloDaGrade;
use App\Comercial\Repository\ModeloDeCampanhaRepository;

/**
 * O modelo efetivo de uma chave (pedido M5.8 + M5.12; spec `comercial-e-emails.md` §8.2): para um modelo padrão do
 * sistema, a versão editada pelo Master (linha de `comercial_modelo` com `fixo_chave`) se existir, senão o literal de
 * {@see ModelosDeCampanha}, que continua puro. Para `cu<id>`, o modelo próprio. Quem mostra ou envia um modelo
 * (a grade, as datas comemorativas) lê por aqui, nunca direto do fixo.
 *
 * @phpstan-import-type ModeloEmJson from ModeloDaGrade
 */
final class ModelosEfetivos
{
    public function __construct(
        private readonly ModelosDeCampanha $fixos,
        private readonly ModeloDeCampanhaRepository $modelos,
    ) {
    }

    /** @return list<ModeloEmJson> os padrões do sistema, cada um na versão editada ou no literal */
    public function fixos(): array
    {
        return $this->aplicar($this->fixos->fixos());
    }

    /** @return list<ModeloEmJson> as 17 datas na ordem da próxima data, cada uma na versão editada ou no literal */
    public function datas(?\DateTimeImmutable $hoje = null): array
    {
        return $this->aplicar($this->fixos->datas($hoje));
    }

    /**
     * A chave de um padrão (inclusive `data-<id>`) ou `cu<id>` de um próprio; chave que não existe (ou próprio já
     * excluído), nulo.
     *
     * @return ModeloEmJson|null
     */
    public function porChave(string $chave): ?array
    {
        $fixo = $this->fixos->fixo($chave);
        if ($fixo !== null) {
            $editado = $this->modelos->doFixo($chave);

            return $editado === null ? ModeloDaGrade::deFixo($fixo) : ModeloDaGrade::deEditado($editado);
        }
        if (preg_match('/^cu(\d+)$/', $chave, $m) !== 1) {
            return null;
        }
        $proprio = $this->modelos->porId((int) $m[1]);

        return $proprio === null ? null : ModeloDaGrade::deEntidade($proprio);
    }

    /**
     * Só a versão editada de um padrão; sem ela, nulo (quem chama segue com o próprio texto, como as automações).
     *
     * @return ModeloEmJson|null
     */
    public function editadoDoFixo(string $chave): ?array
    {
        $editado = $this->fixos->fixo($chave) === null ? null : $this->modelos->doFixo($chave);

        return $editado === null ? null : ModeloDaGrade::deEditado($editado);
    }

    /**
     * @param list<array<string, mixed>> $padroes
     *
     * @return list<ModeloEmJson>
     */
    private function aplicar(array $padroes): array
    {
        $editados = $this->modelos->editados();

        return array_map(
            static fn (array $p): array => isset($editados[$p['chave']]) ? ModeloDaGrade::deEditado($editados[$p['chave']]) : ModeloDaGrade::deFixo($p),
            $padroes,
        );
    }
}
