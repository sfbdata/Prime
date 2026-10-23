<?php

declare(strict_types=1);

namespace App\Novidades\Service;

use App\Novidades\DTO\VersaoComNovidades;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Yaml\Yaml;

/**
 * Lê o app/config/novidades.yaml: o texto para o cliente de cada versão lançada, a mais nova
 * primeiro. Quem escreve o arquivo é o robô do PR de release (scripts/mudancas.py), a partir da
 * seção "## Para o cliente" dos arquivos em mudancas/. É global: o mesmo para todo escritório.
 *
 * Arquivo fora do formato derruba a leitura de propósito: o tests/Arquitetura/NovidadesArquivoTest
 * lê o arquivo real no portão do PR, então um formato quebrado nunca chega à produção.
 */
final class LeitorDeNovidades
{
    public function __construct(
        #[Autowire('%kernel.project_dir%/config/novidades.yaml')]
        private readonly string $arquivo,
    ) {}

    /** @return list<VersaoComNovidades> */
    public function versoes(): array
    {
        if (!is_file($this->arquivo)) {
            return [];
        }

        $dados = Yaml::parseFile($this->arquivo);
        $versoes = [];
        foreach ((array) ($dados['versoes'] ?? []) as $i => $entrada) {
            $versao = (string) ($entrada['versao'] ?? '');
            $data = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) ($entrada['data'] ?? ''));
            $itens = array_values(array_map('strval', (array) ($entrada['itens'] ?? [])));
            if (preg_match('/^\d+\.\d+\.\d+$/', $versao) !== 1 || $data === false || $itens === []) {
                throw new \UnexpectedValueException(sprintf('novidades.yaml: entrada %d fora do formato (versao X.Y.Z, data AAAA-MM-DD, itens).', $i));
            }
            $versoes[] = new VersaoComNovidades($versao, $data, $itens);
        }

        return $versoes;
    }

    public function maisRecente(): ?VersaoComNovidades
    {
        return $this->versoes()[0] ?? null;
    }
}
