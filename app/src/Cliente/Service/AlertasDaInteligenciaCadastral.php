<?php

declare(strict_types=1);

namespace App\Cliente\Service;

use App\Cliente\DTO\AlertaDaInteligenciaCadastral as Alerta;
use App\Cliente\Entity\Cliente;
use App\Cliente\Entity\ClientePJ;
use App\Cliente\Repository\ClienteRepository;
use App\Cobranca\Repository\PessoaRepository;
use App\Entity\Auth\User;
use App\Entity\Permission\AccessRequest;
use App\Entity\Tenant\Tenant;
use App\Service\PermissionChecker;

/**
 * Alertas do bloco "Inteligência cadastral" (F-A, só regras internas; desenho 02, l.3362 a 3367). Tudo no MESMO
 * escritório, comparando o nome normalizado ({@see NomeNormalizado}) e o documento em dígitos ({@see DocumentoDoCliente}):
 *
 * - "Possível homônimo.": outros clientes do mesmo tipo com o mesmo nome e documento diferente;
 * - "Mesmo CPF com outro nome.": outro cliente com o mesmo documento e nome diferente (o nome só aparece
 *   para quem pode ver aquele cadastro) e a pessoa da Cobrança com o mesmo documento e nome diferente, só para quem
 *   acessa o módulo de cobranças.
 *
 * Nenhum CPF/CNPJ entra no texto.
 */
final class AlertasDaInteligenciaCadastral
{
    private const MODULO_COBRANCAS = 'cobrancas';

    public function __construct(
        private readonly ClienteRepository $clientes,
        private readonly PessoaRepository $pessoas,
        private readonly PermissionChecker $permissoes,
    ) {
    }

    /** @return list<Alerta> */
    public function de(Cliente $cliente, string $digitos, User $usuario, Tenant $tenant): array
    {
        return array_values(array_filter([
            $this->homonimo($cliente, $digitos, $tenant),
            $this->outroClienteComOMesmoDocumento($cliente, $digitos, $usuario, $tenant),
            $this->pessoaDaCobrancaComOMesmoDocumento($cliente, $digitos, $usuario, $tenant),
        ]));
    }

    private function homonimo(Cliente $cliente, string $digitos, Tenant $tenant): ?Alerta
    {
        $outros = array_filter(
            $this->clientes->comONomeNormalizado($cliente, $tenant),
            static fn (array $c): bool => NomeNormalizado::iguais($c['nome'], $cliente->getNomeExibicao())
                && DocumentoDoCliente::digitos($c['documento']) !== $digitos,
        );
        if ($outros === []) {
            return null;
        }
        $t = RotulosDaInteligenciaCadastral::homonimo(\count($outros), $cliente instanceof ClientePJ);

        return new Alerta(Alerta::HOMONIMO, RotulosDaInteligenciaCadastral::ICONE_HOMONIMO, $t['negrito'], $t['texto']);
    }

    private function outroClienteComOMesmoDocumento(Cliente $cliente, string $digitos, User $usuario, Tenant $tenant): ?Alerta
    {
        foreach ($this->clientes->comOMesmoDocumento($cliente, $tenant, $digitos) as $outro) {
            if (NomeNormalizado::iguais($outro['nome'], $cliente->getNomeExibicao())) {
                continue;
            }
            $visivel = $this->permissoes->canAccessResource($usuario, $tenant, AccessRequest::RESOURCE_CLIENTE, $outro['id'], AccessRequest::ACTION_VIEW);
            $t = RotulosDaInteligenciaCadastral::mesmoDocumento($visivel ? $outro['nome'] : null, RotulosDaInteligenciaCadastral::ORIGEM_OUTRO_CADASTRO, $cliente instanceof ClientePJ);

            return new Alerta(Alerta::MESMO_DOCUMENTO, RotulosDaInteligenciaCadastral::ICONE_MESMO_DOCUMENTO, $t['negrito'], $t['texto']);
        }

        return null;
    }

    private function pessoaDaCobrancaComOMesmoDocumento(Cliente $cliente, string $digitos, User $usuario, Tenant $tenant): ?Alerta
    {
        if (!$this->permissoes->canAccessModule($usuario, $tenant, self::MODULO_COBRANCAS)) {
            return null;
        }
        $pj = $cliente instanceof ClientePJ;
        $pessoas = $this->pessoas->buscarPossiveisDuplicadas($tenant, $pj ? null : $digitos, $pj ? $digitos : null);
        foreach ($pessoas as $pessoa) {
            if ($pessoa->getTenant()?->getId() === $tenant->getId()
                && !NomeNormalizado::iguais($pessoa->getNome(), $cliente->getNomeExibicao())) {
                $t = RotulosDaInteligenciaCadastral::mesmoDocumento($pessoa->getNome(), RotulosDaInteligenciaCadastral::ORIGEM_COBRANCAS, $pj);

                return new Alerta(Alerta::MESMO_DOCUMENTO, RotulosDaInteligenciaCadastral::ICONE_MESMO_DOCUMENTO, $t['negrito'], $t['texto']);
            }
        }

        return null;
    }
}
