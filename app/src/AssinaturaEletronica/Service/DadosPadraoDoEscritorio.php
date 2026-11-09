<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Service;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Profile\Service\TratamentoDeAdvogado;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Os valores iniciais dos campos do escritório nos modelos (`data-padrao`, contrato da etapa 1, seção 4.2), tirados
 * de Meu Escritório e do usuário logado. O desenho tinha os dados de um escritório fixos no código; aqui, campo sem
 * dado vem vazio.
 */
final readonly class DadosPadraoDoEscritorio
{
    public function __construct(
        private TranslatorInterface $tradutor,
    ) {
    }

    /** @return array<string, string> */
    public function de(User $usuario, Tenant $tenant): array
    {
        $cidade = self::cidade($tenant);

        return [
            'adv_trat' => $this->tratamento($usuario),
            'adv_nome' => trim((string) $usuario->getFullName()),
            'adv_seccional' => trim((string) $usuario->getOabUf()),
            'adv_oab' => trim((string) $usuario->getOabNumero()),
            'adv_email' => trim((string) $usuario->getEmail()),
            'esc_tel' => trim((string) $tenant->getTelefone()),
            'esc_end' => self::juntar([$tenant->getLogradouro(), $tenant->getNumero(), $tenant->getComplemento(), $tenant->getBairro(), $cidade]),
            'esc_cep' => trim((string) $tenant->getCep()),
            'esc_razao' => trim((string) $tenant->getName()),
            'esc_reg' => trim((string) $tenant->getOab()),
            'esc_cnpj' => trim((string) $tenant->getCnpj()),
            'cidade' => $cidade,
        ];
    }

    private function tratamento(User $usuario): string
    {
        $codigo = TratamentoDeAdvogado::de($usuario);

        return $codigo === null ? '' : $this->tradutor->trans('assinatura_eletronica.tratamento.' . $codigo);
    }

    /** "Cidade/UF", como o desenho; só a cidade quando falta a UF. */
    private static function cidade(Tenant $tenant): string
    {
        $cidade = trim((string) $tenant->getCidade());
        $uf = trim((string) $tenant->getEstado());

        return $cidade !== '' && $uf !== '' ? $cidade . '/' . $uf : $cidade;
    }

    /** @param list<?string> $partes */
    private static function juntar(array $partes): string
    {
        $preenchidas = array_filter(array_map(static fn (?string $p): string => trim((string) $p), $partes), static fn (string $p): bool => $p !== '');

        return implode(', ', $preenchidas);
    }
}
