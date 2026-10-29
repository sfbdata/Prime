<?php

declare(strict_types=1);

namespace App\ChatIa\Service;

use App\ChatIa\DTO\PropostaOutput;
use App\ChatIa\Entity\PropostaDeCadastro;
use App\ChatIa\Enum\EstadoDaProposta;
use App\ChatIa\Enum\TipoDaProposta;
use App\Pasta\Entity\Pasta;
use App\Pasta\Repository\PastaRepository;

/**
 * Monta o {@see PropostaOutput} de uma proposta: o JSON guardado vira a lista de campo e valor, com os avisos que o
 * servidor consulta na hora (erro de validação, CPF/CNPJ que já existe, pasta parecida). A consulta de duplicidade é só
 * para AVISAR: quem recusa de verdade é o Confirmar. Proposta já decidida ou vencida não leva dado.
 */
final class MontadorDoCartaoDaProposta
{
    public function __construct(
        private readonly ValidadorDaProposta $validador,
        private readonly PastaRepository $pastas,
    ) {
    }

    public function montar(PropostaDeCadastro $proposta, \DateTimeImmutable $agora): PropostaOutput
    {
        $estado = $proposta->estadoEm($agora);
        $base = [(int) $proposta->getId(), $proposta->getTipo()->value, $estado->value, $proposta->getExpiraEm()->format(\DATE_ATOM)];
        $dados = $proposta->getDados();
        $tenant = $proposta->getTenant();
        if ($estado === EstadoDaProposta::Confirmada) {
            return new PropostaOutput(...$base, resultado: ['tipo' => (string) $proposta->getResultadoTipo(), 'id' => (int) $proposta->getResultadoId()]);
        }
        if ($estado !== EstadoDaProposta::Pendente || $dados === null || $tenant === null) {
            return new PropostaOutput(...$base);
        }

        $validacao = $this->validador->validar($proposta->getTipo(), $dados, $tenant);
        $cliente = $proposta->getTipo() === TipoDaProposta::Cliente ? $dados : (\is_array($dados['cliente'] ?? null) ? $dados['cliente'] : null);
        $duplicada = $validacao->existente === null ? null : [
            'id' => (int) $validacao->existente->getId(),
            'nome' => $validacao->existente->getNomeExibicao(),
            'documento' => (string) $validacao->documento,
            'podeUsarExistente' => $proposta->getTipo() === TipoDaProposta::Pasta,
        ];

        return new PropostaOutput(
            ...$base,
            pessoa: $cliente === null ? null : (string) $cliente['tipoPessoa'],
            campos: $cliente === null ? null : self::campos($cliente),
            pasta: $proposta->getTipo() === TipoDaProposta::Pasta
                ? ['nomeCliente' => (string) $dados['nomeCliente'], 'nomeAcao' => (string) $dados['nomeAcao'], 'nup' => (string) ($dados['nup'] ?? '')]
                : null,
            erros: $validacao->erros,
            duplicada: $duplicada,
            semelhantes: $proposta->getTipo() === TipoDaProposta::Pasta ? $this->semelhantes($proposta, (string) $dados['nomeCliente'], (string) $dados['nomeAcao']) : null,
        );
    }

    /**
     * @param array<string, mixed> $cliente `{tipoPessoa, campos}`
     *
     * @return list<array{chave: string, rotulo: string, valor: string}>
     */
    private static function campos(array $cliente): array
    {
        $rotulos = CamposDaProposta::rotulos((string) $cliente['tipoPessoa']);
        $lista = [];
        foreach ($rotulos as $chave => $rotulo) {
            $valor = \is_array($cliente['campos'] ?? null) ? ($cliente['campos'][$chave] ?? null) : null;
            if (\is_string($valor) && $valor !== '') {
                $lista[] = ['chave' => $chave, 'rotulo' => $rotulo, 'valor' => $valor];
            }
        }

        return $lista;
    }

    /** @return array{lista: list<array{id: int, nup: string, nomeCliente: string, nomeAcao: string}>, total: int}|null */
    private function semelhantes(PropostaDeCadastro $proposta, string $nomeCliente, string $nomeAcao): ?array
    {
        $tenant = $proposta->getTenant();
        $lista = $tenant === null ? [] : $this->pastas->findSemelhantesPorClienteEAcao($tenant, $nomeCliente, $nomeAcao);
        if ($tenant === null || $lista === []) {
            return null;
        }

        return [
            'lista' => array_map(static fn (Pasta $p): array => [
                'id' => (int) $p->getId(),
                'nup' => (string) $p->getNup(),
                'nomeCliente' => (string) $p->getNomeCliente(),
                'nomeAcao' => (string) $p->getNomeAcao(),
            ], $lista),
            'total' => $this->pastas->contarSemelhantesPorClienteEAcao($tenant, $nomeCliente, $nomeAcao),
        ];
    }
}
