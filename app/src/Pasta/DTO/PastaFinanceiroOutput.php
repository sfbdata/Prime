<?php

declare(strict_types=1);

namespace App\Pasta\DTO;

use App\Cliente\Entity\Cliente;
use App\Cliente\Entity\ClientePJ;
use App\Pasta\Entity\Pasta;

/**
 * O que a faixa do topo da aba Financeiro precisa mostrar, já pronto para
 * impressão — a tela não calcula nem formata nada.
 *
 * Ausência de dado vira travessão, nunca R$ 0,00: sem valor preenchido e sem
 * CPF vinculado são dois "não sei", e um zero ali seria um número inventado.
 */
final readonly class PastaFinanceiroOutput
{
    public const SEM_DADO = '—';

    public function __construct(
        public ?string $valorCausa,
        public string $valorCausaFormatado,
        public ?string $clienteNome,
        public string $mediaCpfFormatada,
        public string $mediaRotulo,
    ) {}

    /**
     * @param ?Cliente $cliente cliente de cadastro mais antigo da pasta; nulo quando não há nenhum
     * @param ?string  $mediaCpf média já apurada pelo repositório, em decimal
     */
    public static function montar(Pasta $pasta, ?Cliente $cliente, ?string $mediaCpf): self
    {
        return new self(
            valorCausa: $pasta->getValorCausa(),
            valorCausaFormatado: self::formatarReais($pasta->getValorCausa()),
            clienteNome: $cliente?->getNomeExibicao(),
            mediaCpfFormatada: self::formatarReais($mediaCpf),
            mediaRotulo: self::rotuloDaMedia($cliente),
        );
    }

    /**
     * O rótulo acompanha quem é o cliente: empresa não tem CPF, e chamar de
     * "Média por CPF" um número que agrupa CNPJ é dizer uma coisa por outra.
     * Sem cliente vinculado fica o rótulo do caso comum, que é pessoa física.
     */
    private static function rotuloDaMedia(?Cliente $cliente): string
    {
        return $cliente instanceof ClientePJ ? 'Média por CNPJ' : 'Média por CPF';
    }

    /**
     * Converte o decimal do banco ("12860.00") no que se lê na tela
     * ("R$ 12.860,00"). Nulo vira travessão.
     */
    public static function formatarReais(?string $valor): string
    {
        if ($valor === null || $valor === '') {
            return self::SEM_DADO;
        }

        return 'R$ ' . number_format((float) $valor, 2, ',', '.');
    }
}
