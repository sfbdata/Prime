<?php

declare(strict_types=1);

namespace App\Pasta\Armazenamento;

use App\Pasta\Repository\ArquivoArmazenadoRepository;

/** Cunha `DOC-XXXX-XXXX-XXXX`, único por escritório. Alfabeto sem 0/O/1/I/L para ler ao telefone. */
final class GeradorDeCodigoDeDocumento
{
    private const ALFABETO = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
    private const TENTATIVAS = 10;

    public function __construct(private readonly ArquivoArmazenadoRepository $arquivos)
    {
    }

    public function gerar(int $tenantId): string
    {
        for ($i = 0; $i < self::TENTATIVAS; ++$i) {
            $codigo = self::sortear();
            if (!$this->arquivos->existeCodigo($tenantId, $codigo)) {
                return $codigo;
            }
        }

        throw new \RuntimeException('Não foi possível cunhar um código de documento único.');
    }

    public static function sortear(): string
    {
        $grupos = [];
        for ($g = 0; $g < 3; ++$g) {
            $grupo = '';
            for ($c = 0; $c < 4; ++$c) {
                $grupo .= self::ALFABETO[random_int(0, strlen(self::ALFABETO) - 1)];
            }
            $grupos[] = $grupo;
        }

        return 'DOC-' . implode('-', $grupos);
    }
}
