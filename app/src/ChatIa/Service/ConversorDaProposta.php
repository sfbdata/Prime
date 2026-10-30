<?php

declare(strict_types=1);

namespace App\ChatIa\Service;

use App\ChatIa\DTO\ResultadoDoCadastro;
use App\ChatIa\Enum\TipoDaProposta;
use App\Cliente\Entity\Cliente;
use App\Cliente\UseCase\CriarClienteAvulsoUseCase;
use App\Cliente\UseCase\CriarClienteDaPastaUseCase;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\DTO\CriarPastaDTO;
use App\Pasta\Service\RegistroDoSistemaNaPasta;
use App\Pasta\UseCase\CriarPastaUseCase;

/**
 * Converte a proposta numa chamada dos UseCases que já existem (eles NÃO mudam): cliente avulso, ou pasta (e, se a proposta
 * traz o cliente, o cadastro ligado a ela; se a pessoa mandou usar o existente, liga o do escritório). Tudo com o usuário
 * e o escritório da SESSÃO, nunca algo vindo do modelo. Roda dentro da transação do Confirmar: uma falha no segundo passo
 * desfaz a pasta do primeiro.
 */
final class ConversorDaProposta implements ExecutorDaProposta
{
    public function __construct(
        private readonly CriarClienteAvulsoUseCase $criarCliente,
        private readonly CriarClienteDaPastaUseCase $criarClienteDaPasta,
        private readonly CriarPastaUseCase $criarPasta,
        private readonly RegistroDoSistemaNaPasta $registro,
    ) {
    }

    public function executar(TipoDaProposta $tipo, array $dados, User $eu, Tenant $tenant, ?Cliente $existente): ResultadoDoCadastro
    {
        if ($tipo === TipoDaProposta::Cliente) {
            $cliente = $this->criarCliente->executar((string) $dados['tipoPessoa'], self::campos($dados), $eu, $tenant);

            return new ResultadoDoCadastro($tipo->value, (int) $cliente->getId());
        }

        $cliente = \is_array($dados['cliente'] ?? null) ? $dados['cliente'] : null;
        $nup = trim((string) ($dados['nup'] ?? ''));
        $pasta = $this->criarPasta->executar(new CriarPastaDTO(
            nup: $nup !== '' ? $nup : null,
            nomeCliente: trim((string) $dados['nomeCliente']),
            nomeAcao: trim((string) $dados['nomeAcao']),
            // Como a tela de Nova pasta: sem cadastro novo nem escolhido, liga o cadastro único de mesmo nome, se houver.
            vincularAoCadastroDoMesmoNome: $cliente === null && $existente === null,
        ), $eu, $tenant);

        if ($existente !== null) {
            $pasta->addCliente($existente);
            $this->registro->registrar($pasta, $eu, 'cadastro', 'cadastro de ' . $existente->getNomeExibicao() . ' ligado à pasta.');
        } elseif ($cliente !== null) {
            $this->criarClienteDaPasta->executar($pasta, (string) $cliente['tipoPessoa'], self::campos($cliente), $eu, $tenant);
        }

        return new ResultadoDoCadastro($tipo->value, (int) $pasta->getId(), $pasta);
    }

    /**
     * @param array<string, mixed> $dados
     *
     * @return array<string, string>
     */
    private static function campos(array $dados): array
    {
        return array_map(static fn (mixed $v): string => (string) $v, \is_array($dados['campos'] ?? null) ? $dados['campos'] : []);
    }
}
