<?php

declare(strict_types=1);

namespace App\Djen\UseCase;

use App\Djen\DTO\RegistrarPublicacaoDoDiarioInput;
use App\Djen\Entity\PublicacaoDoDiarioDaOab;
use App\Djen\Enum\TipoDeAtoDoDiario;
use App\Djen\Exception\PublicacaoDoDiarioInvalidaException;
use App\Djen\Repository\PublicacaoDoDiarioDaOabRepository;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;

/** Registra à mão uma publicação do Diário da OAB (desenho 11). Pertence a quem registra. */
final class RegistrarPublicacaoDoDiarioUseCase
{
    private const PRAZO_MAXIMO = 365;

    public function __construct(private readonly PublicacaoDoDiarioDaOabRepository $repository)
    {
    }

    public function executar(Tenant $tenant, User $usuario, RegistrarPublicacaoDoDiarioInput $input, \DateTimeImmutable $hoje): PublicacaoDoDiarioDaOab
    {
        $falta = array_keys(array_filter([
            'data' => $input->data === '',
            'ato' => $input->ato === '',
            'órgão' => $input->orgao === '',
            'texto' => $input->texto === '',
            'seccional' => $input->seccional === '',
        ]));
        if ($falta !== []) {
            throw new PublicacaoDoDiarioInvalidaException('Preencha: ' . implode(', ', $falta) . '.');
        }

        $publicacao = new PublicacaoDoDiarioDaOab(
            $tenant,
            $usuario,
            $this->data($input->data, $hoje),
            $this->limitar($input->seccional, 20, 'Seccional'),
            TipoDeAtoDoDiario::tryFrom($input->tipo) ?? throw new PublicacaoDoDiarioInvalidaException('Tipo de publicação inválido.'),
            $this->limitar($input->ato, 255, 'Ato'),
            $this->limitar($input->orgao, 255, 'Órgão'),
            $input->texto,
            $input->numeroProcesso !== '' ? $this->limitar($input->numeroProcesso, 50, 'Processo') : null,
            $this->prazo($input->prazoDias),
        );
        $this->repository->salvar($publicacao);

        return $publicacao;
    }

    private function data(string $valor, \DateTimeImmutable $hoje): \DateTimeImmutable
    {
        $data = \DateTimeImmutable::createFromFormat('!Y-m-d', $valor);
        if ($data === false || $data->format('Y-m-d') !== $valor) {
            throw new PublicacaoDoDiarioInvalidaException('Data da publicação inválida.');
        }
        if ($data > $hoje->setTime(0, 0)) {
            throw new PublicacaoDoDiarioInvalidaException('A data da publicação não pode ser futura.');
        }

        return $data;
    }

    private function limitar(string $valor, int $limite, string $rotulo): string
    {
        if (mb_strlen($valor) > $limite) {
            throw new PublicacaoDoDiarioInvalidaException(sprintf('%s passa de %d caracteres.', $rotulo, $limite));
        }

        return $valor;
    }

    private function prazo(string $valor): ?int
    {
        if ($valor === '') {
            return null;
        }
        if (!ctype_digit($valor) || (int) $valor < 1 || (int) $valor > self::PRAZO_MAXIMO) {
            throw new PublicacaoDoDiarioInvalidaException(sprintf('O prazo vai de 1 a %d dias úteis.', self::PRAZO_MAXIMO));
        }

        return (int) $valor;
    }
}
