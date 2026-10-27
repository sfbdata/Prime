<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\DTO;

use App\TrabalhoExterno\Exception\DadosInvalidos;

/**
 * Os campos do modal do Termo de Voluntariado (desenho 06 de 25/10, `termoNovo` l.1127 e `tmOn` l.1130), no
 * snapshot que fica gravado. Só as chaves conhecidas entram, sempre como texto aparado e com tamanho máximo;
 * `ress` é "nao" ou "sim". CPF e RG ficam aqui e em nenhum outro lugar do sistema.
 */
final class DadosDoTermo
{
    /** chave → tamanho máximo. A ordem é a do modal. */
    public const CAMPOS = [
        'eNome' => 200, 'eDoc' => 30, 'eEnd' => 300, 'eEmail' => 150, 'eTel' => 40, 'resp' => 150, 'respOab' => 20, 'respOabUf' => 2,
        'orient' => 150, 'vNome' => 200, 'vNac' => 60, 'vRg' => 30, 'vCpf' => 20, 'vOab' => 20, 'vOabUf' => 2, 'vSit' => 10,
        'vInst' => 200, 'vSem' => 40, 'vEnd' => 300, 'vEmail' => 150, 'ativ' => 1500, 'horas' => 3, 'modal' => 60, 'inicio' => 10,
        'meses' => 3, 'aviso' => 3, 'ress' => 3, 'ressLim' => 20, 'foro' => 120, 'cidade' => 150, 'data' => 10,
    ];

    /** Os 7 obrigatórios do `enviarTermo` (l.1134), com o nome que a mensagem usa. */
    public const OBRIGATORIOS = [
        'eNome' => 'nome do escritório', 'eDoc' => 'CNPJ/CPF do escritório', 'vNome' => 'nome do voluntário',
        'vCpf' => 'CPF do voluntário', 'inicio' => 'data de início', 'meses' => 'prazo', 'foro' => 'foro',
    ];

    private const NUMERICOS = ['horas', 'meses', 'aviso'];
    private const DATAS = ['inicio', 'data'];

    /** @param array<string, string> $campos */
    private function __construct(public readonly array $campos)
    {
    }

    /** @param array<string, mixed> $d */
    public static function deArray(array $d): self
    {
        $campos = [];
        foreach (self::CAMPOS as $chave => $max) {
            $valor = $d[$chave] ?? '';
            $texto = is_scalar($valor) ? trim((string) $valor) : '';
            $campos[$chave] = self::limpar($chave, mb_substr($texto, 0, $max));
        }
        $campos['ress'] = $campos['ress'] === 'sim' ? 'sim' : 'nao';

        return new self($campos);
    }

    private static function limpar(string $chave, string $texto): string
    {
        if (in_array($chave, self::NUMERICOS, true)) {
            return preg_replace('/\D+/', '', $texto) ?? '';
        }
        if (in_array($chave, self::DATAS, true)) {
            return preg_match('/^\d{4}-\d{2}-\d{2}$/', $texto) === 1 && checkdate((int) substr($texto, 5, 2), (int) substr($texto, 8, 2), (int) substr($texto, 0, 4)) ? $texto : '';
        }

        return $texto;
    }

    /** @return list<string> os nomes dos obrigatórios em branco */
    public function faltando(): array
    {
        return array_values(array_map(
            static fn (string $chave): string => self::OBRIGATORIOS[$chave],
            array_filter(array_keys(self::OBRIGATORIOS), fn (string $chave): bool => $this->campos[$chave] === ''),
        ));
    }

    /** "Preencha antes de enviar: …" (l.1134). */
    public function exigirObrigatorios(): void
    {
        $faltam = $this->faltando();
        if ($faltam !== []) {
            throw new DadosInvalidos('Preencha antes de enviar: ' . implode(', ', $faltam) . '.');
        }
    }
}
