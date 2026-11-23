<?php

declare(strict_types=1);

namespace App\Comercial\DTO;

use App\Comercial\Exception\CampanhaRecusada;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Os endereços do "Enviar e-mail de teste" e do "Encaminhar" do Relatório (pedido do Farlei M5.17, 23/11/2026; a C4,
 * "teste só ao Master", caiu): de 1 a 5, separados por vírgula, ponto e vírgula ou espaço, cada um válido pelo
 * Validator do Symfony, sem repetidos (sem diferença de caixa). Texto vazio ou ausente = nenhum endereço informado
 * (o teste vai só ao Master; o Encaminhar recusa).
 */
final class DestinosDoTeste
{
    public const MAXIMO = 5;
    public const FORA_DO_LIMITE = 'Informe de 1 a 5 endereços.';

    /** @param list<string> $enderecos normalizados (minúsculos), sem repetição */
    private function __construct(public readonly array $enderecos)
    {
    }

    public static function deEntrada(mixed $bruto, ValidatorInterface $validador): self
    {
        if ($bruto === null) {
            return new self([]);
        }
        if (!\is_string($bruto)) {
            throw new CampanhaRecusada(self::FORA_DO_LIMITE);
        }
        if (trim($bruto) === '') {
            return new self([]);
        }
        $partes = preg_split('/[,;\s]+/u', $bruto, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $enderecos = [];
        foreach ($partes as $parte) {
            $enderecos[mb_strtolower($parte)] = true;
        }
        $enderecos = array_map('strval', array_keys($enderecos));
        if ($enderecos === [] || \count($enderecos) > self::MAXIMO) {
            throw new CampanhaRecusada(self::FORA_DO_LIMITE);
        }
        foreach ($enderecos as $endereco) {
            if (\count($validador->validate($endereco, new Email())) > 0 || !(new DestinatarioDaCampanha($endereco))->valido()) {
                throw new CampanhaRecusada('Endereço inválido: ' . mb_substr($endereco, 0, 80) . '.');
            }
        }

        return new self($enderecos);
    }

    public function vazio(): bool
    {
        return $this->enderecos === [];
    }
}
