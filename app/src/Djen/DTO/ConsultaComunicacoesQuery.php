<?php

declare(strict_types=1);

namespace App\Djen\DTO;

/**
 * Parâmetros de uma consulta ao endpoint público GET /api/v1/comunicacao do DJEN.
 *
 * Só inclui os parâmetros documentados/confirmados da API — enviar parâmetros desconhecidos
 * (ex.: cpf) faz a consulta falhar. itensPorPagina aceita apenas 5 ou 100 (default 100).
 */
final class ConsultaComunicacoesQuery
{
    public function __construct(
        public readonly ?string $numeroOab = null,
        public readonly ?string $ufOab = null,
        public readonly ?\DateTimeImmutable $dataDisponibilizacaoInicio = null,
        public readonly ?\DateTimeImmutable $dataDisponibilizacaoFim = null,
        public readonly ?string $siglaTribunal = null,
        public readonly int $pagina = 1,
        public readonly int $itensPorPagina = 100,
    ) {
    }

    /**
     * Monta o array de query string, incluindo apenas os campos preenchidos.
     *
     * @return array<string, string|int>
     */
    public function toQueryParams(): array
    {
        $params = [
            'pagina' => $this->pagina,
            'itensPorPagina' => $this->itensPorPagina,
        ];

        if ($this->numeroOab !== null && $this->numeroOab !== '') {
            $params['numeroOab'] = $this->numeroOab;
        }

        if ($this->ufOab !== null && $this->ufOab !== '') {
            $params['ufOab'] = $this->ufOab;
        }

        if ($this->siglaTribunal !== null && $this->siglaTribunal !== '') {
            $params['siglaTribunal'] = $this->siglaTribunal;
        }

        if ($this->dataDisponibilizacaoInicio !== null) {
            $params['dataDisponibilizacaoInicio'] = $this->dataDisponibilizacaoInicio->format('Y-m-d');
        }

        if ($this->dataDisponibilizacaoFim !== null) {
            $params['dataDisponibilizacaoFim'] = $this->dataDisponibilizacaoFim->format('Y-m-d');
        }

        return $params;
    }
}
